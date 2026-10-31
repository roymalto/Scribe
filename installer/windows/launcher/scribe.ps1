<#
  Scribe, local edition: starts, stops, backs up and restores Scribe on this PC. The Start menu and desktop shortcuts
  call it (hidden window):  scribe.ps1 start | stop | backup | restore | open-backups

  Programs (read-only):  this install folder - app, php, pgsql, caddy, launcher
  Data (this Windows user): %LOCALAPPDATA%\Scribe - pgdata (the database), storage (logos, signatures, PDFs),
                            settings.env (generated passwords and key: never shown), logs, run (generated config)
  Backups:                  Documents\Scribe Backups\<date>, one a day when Scribe starts, the last 30 kept

  Everything listens on 127.0.0.1 only: Scribe http://127.0.0.1:8100, PostgreSQL 55432, PHP 9101-9104.
  Works with Windows PowerShell 5.1 (no newer syntax).
#>
param([ValidateSet('start', 'stop', 'backup', 'restore', 'open-backups')] [string] $Action = 'start')

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms

$Root     = Split-Path -Parent $PSScriptRoot
$Php      = Join-Path $Root 'php'
$PgBin    = Join-Path $Root 'pgsql\bin'
$CaddyExe = Join-Path $Root 'caddy\caddy.exe'
$Web      = Join-Path $Root 'app'
$Data     = Join-Path $env:LOCALAPPDATA 'Scribe'
$PgData   = Join-Path $Data 'pgdata'
$Storage  = Join-Path $Data 'storage'
$Logs     = Join-Path $Data 'logs'
$Run      = Join-Path $Data 'run'
$Temp     = Join-Path $Data 'tmp'
$Settings = Join-Path $Data 'settings.env'
$Backups  = Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'Scribe Backups'
$WebPort  = 8100
$PgPort   = 55432
$FcgiPorts = 9101, 9102, 9103, 9104
$Url      = "http://127.0.0.1:$WebPort/"
$KeepBackups = 30

foreach ($d in $Data, $Logs, $Run, $Temp) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
$LogFile = Join-Path $Logs 'launcher.log'

function Log([string] $msg) {
    Add-Content -Path $LogFile -Value ('{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $msg)
}

function Fail([string] $msg) {
    Log "FAILED: $msg"
    [System.Windows.Forms.MessageBox]::Show("$msg`n`nDetails are in $LogFile", 'Scribe', 'OK', 'Error') | Out-Null
    exit 1
}

function Ask([string] $msg) {
    return [System.Windows.Forms.MessageBox]::Show($msg, 'Scribe', 'YesNo', 'Question') -eq 'Yes'
}

# Runs a program, waits, logs its output; stops with $what if it fails. (PowerShell 5.1 turns a program's normal
# messages on stderr into errors, so they're only collected here, and the exit code decides.)
function Exec([string] $exe, [string[]] $arguments, [string] $what) {
    $ErrorActionPreference = 'Continue'
    $out = & $exe @arguments 2>&1
    $code = $LASTEXITCODE
    if ($out) { Log (($out | Out-String).Trim()) }
    if ($code -ne 0) { Fail "$what (exit code $code)." }
}

# ---------------------------------------------------------------------------------------------- settings
function New-Secret([int] $length = 32) {
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'.ToCharArray()
    $bytes = New-Object byte[] $length
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

function Read-Settings {
    $s = @{}
    if (Test-Path $Settings) {
        foreach ($line in Get-Content $Settings) {
            if ($line -match '^\s*([A-Z_]+)=(.*)$') { $s[$Matches[1]] = $Matches[2] }
        }
    }
    return $s
}

# First start: passwords for the two database roles and the app's encryption key, kept for this Windows user only
function Initialize-Settings {
    if (Test-Path $Settings) { return }
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $key = [Convert]::ToBase64String($bytes)
    @("DB_OWNER_PASSWORD=$(New-Secret)", "DB_PASSWORD=$(New-Secret)", "APP_KEY=base64:$key") |
        Set-Content -Path $Settings -Encoding ASCII
    Log 'Created settings (new passwords and key)'
}

function Fill([string] $template, [hashtable] $values) {
    $text = Get-Content -Raw -Path (Join-Path $PSScriptRoot $template)
    foreach ($k in $values.Keys) { $text = $text.Replace("{{$k}}", $values[$k]) }
    return $text
}

# The generated config: php.ini, Caddyfile and the app's .env (rewritten on every start, so an update applies)
function Write-Config {
    $s = Read-Settings
    $utf8 = New-Object System.Text.UTF8Encoding $false
    [IO.File]::WriteAllText((Join-Path $Run 'php.ini'),
        (Fill 'php.ini.template' @{ PHP = $Php; LOGS = $Logs; TEMP = $Temp }), $utf8)
    $upstreams = ($FcgiPorts | ForEach-Object { "127.0.0.1:$_" }) -join ' '
    [IO.File]::WriteAllText((Join-Path $Run 'Caddyfile'),
        (Fill 'Caddyfile.template' @{ WEB_PORT = "$WebPort"; PUBLIC = (Join-Path $Web 'public').Replace('\', '/');
                                      LAUNCHER = $PSScriptRoot.Replace('\', '/'); FCGI_UPSTREAMS = $upstreams;
                                      LOGS = $Logs.Replace('\', '/') }), $utf8)
    $envLines = @(
        'APP_NAME=Scribe', 'APP_ENV=production', "APP_KEY=$($s['APP_KEY'])", 'APP_DEBUG=false', "APP_URL=$($Url.TrimEnd('/'))",
        'SCRIBE_EDITION=local', 'LOG_CHANNEL=single', 'LOG_LEVEL=warning',
        'DB_CONNECTION=pgsql', 'DB_HOST=127.0.0.1', "DB_PORT=$PgPort", 'DB_DATABASE=scribe', 'DB_SSLMODE=disable',
        'DB_USERNAME=scribe_app', "DB_PASSWORD=$($s['DB_PASSWORD'])",
        'DB_OWNER_USERNAME=scribe_owner', "DB_OWNER_PASSWORD=$($s['DB_OWNER_PASSWORD'])",
        'SESSION_DRIVER=database', 'SESSION_LIFETIME=480', 'CACHE_STORE=database', 'QUEUE_CONNECTION=sync',
        'MAIL_MAILER=log', 'FILESYSTEM_DISK=local'
    )
    [IO.File]::WriteAllText((Join-Path $Web '.env'), (($envLines -join "`r`n") + "`r`n"), $utf8)

    foreach ($d in 'app\private', 'app\public', 'framework\cache\data', 'framework\sessions', 'framework\views', 'logs') {
        New-Item -ItemType Directory -Force -Path (Join-Path $Storage $d) | Out-Null
    }
}

# What PHP, artisan and the PostgreSQL tools need in their environment
function Set-ProcessEnvironment {
    $s = Read-Settings
    $env:PATH = "$Php;$PgBin;$env:PATH"
    $env:PHPRC = $Run
    $env:LARAVEL_STORAGE_PATH = $Storage
    $env:PHP_FCGI_MAX_REQUESTS = '0'                   # php-cgi otherwise quits after 500 requests
    $env:PGHOST = '127.0.0.1'; $env:PGPORT = "$PgPort"; $env:PGUSER = 'scribe_owner'
    $env:PGPASSWORD = $s['DB_OWNER_PASSWORD']
}

# ---------------------------------------------------------------------------------------------- processes
function Test-Up {
    try { return (Invoke-WebRequest -Uri "${Url}up" -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200 } catch { return $false }
}

function Test-PortFree([int] $port) {
    return -not (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
}

# This install's own processes (by their path), whatever started them
function Get-OurProcesses([string] $name) {
    return Get-Process -Name $name -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith($Root) }
}

function Start-WebServer {
    if (Get-OurProcesses 'caddy') { return }
    if (-not (Test-PortFree $WebPort)) { Fail "Another program is using port $WebPort, which Scribe needs. Close it and try again." }
    Start-Process -FilePath $CaddyExe -ArgumentList @('run', '--config', (Join-Path $Run 'Caddyfile'), '--adapter', 'caddyfile') `
        -WindowStyle Hidden | Out-Null
    Log 'Started the web server'
}

function Start-Php {
    if (Get-OurProcesses 'php-cgi') { return }
    foreach ($port in $FcgiPorts) {
        Start-Process -FilePath (Join-Path $Php 'php-cgi.exe') -ArgumentList @('-b', "127.0.0.1:$port") `
            -WindowStyle Hidden | Out-Null
    }
    Log 'Started PHP'
}

function Test-PgRunning {
    $ErrorActionPreference = 'Continue'
    & (Join-Path $PgBin 'pg_ctl.exe') status -D $PgData *> $null
    return $LASTEXITCODE -eq 0
}

# First start: an empty database cluster, the owner and the app's role (as the Docker install's docker/db/01-roles.sh)
function Initialize-Database {
    if (Test-Path (Join-Path $PgData 'PG_VERSION')) { return }
    Log 'Creating the database (first start)'
    $s = Read-Settings
    $pwfile = Join-Path $Temp 'pw.txt'
    [IO.File]::WriteAllText($pwfile, $s['DB_OWNER_PASSWORD'])
    try {
        Exec (Join-Path $PgBin 'initdb.exe') @('-D', $PgData, '-U', 'scribe_owner', "--pwfile=$pwfile", '-A', 'scram-sha-256',
                                              '-E', 'UTF8', '--no-locale') 'Could not create the database'
    } finally { Remove-Item $pwfile -Force -ErrorAction SilentlyContinue }
    Add-Content -Path (Join-Path $PgData 'postgresql.conf') -Value @(
        '', '# Scribe local edition', "listen_addresses = '127.0.0.1'", "port = $PgPort", "timezone = 'Asia/Manila'",
        "log_timezone = 'Asia/Manila'", 'max_connections = 30'
    )
    Start-Database
    $psql = Join-Path $PgBin 'psql.exe'
    Exec $psql @('-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c',
        "CREATE ROLE scribe_app LOGIN PASSWORD '$($s['DB_PASSWORD'])' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS") 'Could not create the app''s database role'
    Exec $psql @('-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c', 'CREATE DATABASE scribe OWNER scribe_owner') 'Could not create the database'
    Grant-AppAccess
}

function Grant-AppAccess {
    Exec (Join-Path $PgBin 'psql.exe') @('-v', 'ON_ERROR_STOP=1', '-d', 'scribe', '-c',
        'GRANT CONNECT ON DATABASE scribe TO scribe_app; GRANT USAGE ON SCHEMA public TO scribe_app') 'Could not give the app access to the database'
}

function Start-Database {
    if (Test-PgRunning) { return }
    if (-not (Test-PortFree $PgPort)) { Fail "Another program is using port $PgPort, which Scribe's database needs." }
    Exec (Join-Path $PgBin 'pg_ctl.exe') @('start', '-D', $PgData, '-w', '-t', '120', '-l', (Join-Path $Logs 'postgresql.log')) 'The database did not start'
    Log 'Started the database'
}

# Database updates (an update of Scribe may bring some) and the app's caches, before PHP starts serving
function Update-App {
    $php = Join-Path $Php 'php.exe'
    $artisan = Join-Path $Web 'artisan'
    Push-Location $Web
    try {
        Exec $php @($artisan, 'migrate', '--database=pgsql_owner', '--force', '--no-interaction') 'Could not update the database'
        Exec $php @($artisan, 'db:seed', '--database=pgsql_owner', '--force', '--no-interaction') 'Could not load the reference data'
        Exec $php @($artisan, 'optimize') 'Could not prepare the app'
    } finally { Pop-Location }
}

function Stop-All([switch] $KeepDatabase) {
    foreach ($name in 'caddy', 'php-cgi') {
        Get-OurProcesses $name | ForEach-Object { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue }
    }
    if (-not $KeepDatabase -and (Test-PgRunning)) {
        Exec (Join-Path $PgBin 'pg_ctl.exe') @('stop', '-D', $PgData, '-m', 'fast', '-w') 'The database did not stop'
    }
    Log ('Stopped Scribe' + $(if ($KeepDatabase) { ' (database left running)' } else { '' }))
}

# ---------------------------------------------------------------------------------------------- backups
function Backup-Now([string] $label = '') {
    New-Item -ItemType Directory -Force -Path $Backups | Out-Null
    $name = (Get-Date -Format 'yyyy-MM-dd HHmm') + $(if ($label) { " $label" } else { '' })
    $dir = Join-Path $Backups $name
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    Exec (Join-Path $PgBin 'pg_dump.exe') @('-d', 'scribe', '-Fc', '-f', (Join-Path $dir 'scribe.dump')) 'The backup of the database failed'
    $files = Join-Path $Storage 'app'
    if (Get-ChildItem -Path $files -Recurse -File -ErrorAction SilentlyContinue | Select-Object -First 1) {
        Compress-Archive -Path (Join-Path $files '*') -DestinationPath (Join-Path $dir 'files.zip') -Force
    }
    Set-Content -Path (Join-Path $dir 'README.txt') -Value @(
        "Scribe backup made $(Get-Date -Format 'd MMMM yyyy HH:mm') on $env:COMPUTERNAME.",
        'scribe.dump is the database (patients, prescriptions, accounts); files.zip holds logos, signatures and PDFs.',
        'To restore: Start menu > Scribe > Restore a backup, then choose this folder.')
    Log "Backup made: $dir"
    # keep the newest ones
    Get-ChildItem -Path $Backups -Directory | Where-Object { Test-Path (Join-Path $_.FullName 'scribe.dump') } |
        Sort-Object Name -Descending | Select-Object -Skip $KeepBackups | Remove-Item -Recurse -Force
}

function Backup-Daily {
    $today = Get-Date -Format 'yyyy-MM-dd'
    if (-not (Get-ChildItem -Path $Backups -Directory -Filter "$today*" -ErrorAction SilentlyContinue)) {
        try { Backup-Now } catch { Log "Daily backup failed: $_" }
    }
}

function Restore-Backup {
    $pick = New-Object System.Windows.Forms.FolderBrowserDialog
    $pick.Description = 'Choose the backup folder to restore (in Documents\Scribe Backups)'
    $pick.SelectedPath = $Backups
    $pick.ShowNewFolderButton = $false
    if ($pick.ShowDialog() -ne 'OK') { return }
    $dir = $pick.SelectedPath
    $dump = Join-Path $dir 'scribe.dump'
    if (-not (Test-Path $dump)) { Fail "$dir is not a Scribe backup (it has no scribe.dump)." }
    if (-not (Ask "Replace everything in Scribe with the backup from:`n$dir`n`nWhat you entered since then is lost. A backup of how it is now is made first.")) { return }

    Stop-All -KeepDatabase
    Start-Database
    Backup-Now 'before restore'
    $psql = Join-Path $PgBin 'psql.exe'
    Exec $psql @('-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c', 'DROP DATABASE IF EXISTS scribe WITH (FORCE)') 'Could not clear the database'
    Exec $psql @('-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c', 'CREATE DATABASE scribe OWNER scribe_owner') 'Could not create the database'
    Exec (Join-Path $PgBin 'pg_restore.exe') @('-d', 'scribe', $dump) 'The restore of the database failed'
    Grant-AppAccess
    $files = Join-Path $Storage 'app'
    Remove-Item -Recurse -Force -Path $files -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force -Path $files | Out-Null
    $zip = Join-Path $dir 'files.zip'
    if (Test-Path $zip) { Expand-Archive -Path $zip -DestinationPath $files -Force }
    Log "Restored $dir"
    Start-Scribe
    [System.Windows.Forms.MessageBox]::Show("Scribe has been restored from:`n$dir", 'Scribe', 'OK', 'Information') | Out-Null
}

# ---------------------------------------------------------------------------------------------- start
function Start-Scribe {
    if (Test-Up) { Start-Process $Url; return }      # already running: just open it
    Log 'Starting Scribe'
    Initialize-Settings
    Write-Config
    Set-ProcessEnvironment
    Start-WebServer
    Start-Process $Url                                 # shows "Scribe is starting" until PHP answers
    Initialize-Database
    Start-Database
    Update-App
    Start-Php
    $deadline = (Get-Date).AddSeconds(60)
    while (-not (Test-Up)) {
        if ((Get-Date) -gt $deadline) { Fail 'Scribe did not start.' }
        Start-Sleep -Milliseconds 500
    }
    Log 'Scribe is running'
    Backup-Daily
}

try {
    switch ($Action) {
        'start'        { Start-Scribe }
        'stop'         { Set-ProcessEnvironment; Stop-All }
        'backup'       {
            Set-ProcessEnvironment; Start-Database; Backup-Now
            [System.Windows.Forms.MessageBox]::Show("Backup made in $Backups", 'Scribe', 'OK', 'Information') | Out-Null
        }
        'restore'      { Initialize-Settings; Write-Config; Set-ProcessEnvironment; Restore-Backup }
        'open-backups' { New-Item -ItemType Directory -Force -Path $Backups | Out-Null; Start-Process $Backups }
    }
} catch {
    Fail "Something went wrong: $($_.Exception.Message)"
}
