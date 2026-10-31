; Scribe, local edition: the Windows installer (Inno Setup 6). Built by installer/windows/build.sh, which passes
; MyVersion and Stage (the folder with app, php, pgsql, caddy, launcher and redist).
;
; Installs for the current Windows user only, without administrator rights, into %LOCALAPPDATA%\Programs\Scribe.
; The data (database, files, settings) lives in %LOCALAPPDATA%\Scribe and backups in Documents\Scribe Backups: an
; update or uninstall never touches them. The one step that may ask for administrator rights is Microsoft's Visual
; C++ runtime, and only on a PC that doesn't have it yet.

#ifndef MyVersion
  #define MyVersion "0.0.0"
#endif
#ifndef Stage
  #define Stage "stage"
#endif
#define PS "{sys}\WindowsPowerShell\v1.0\powershell.exe"
#define Launcher '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\launcher\scribe.ps1""'

[Setup]
AppId={{6F1E3B7A-4C1D-4E7B-9C55-5C2B1A9D3E21}
AppName=Scribe
AppVersion={#MyVersion}
AppVerName=Scribe {#MyVersion}
AppPublisher=Pinotech
DefaultDirName={localappdata}\Programs\Scribe
DisableDirPage=yes
DefaultGroupName=Scribe
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputBaseFilename=Scribe-Setup-{#MyVersion}
SetupIconFile=launcher\scribe.ico
UninstallDisplayIcon={app}\launcher\scribe.ico
UninstallDisplayName=Scribe
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
CloseApplications=no

[Tasks]
Name: desktopicon; Description: "Put a Scribe icon on the desktop"

[InstallDelete]
; an update replaces the programs completely (old files left behind could still be loaded); the data is elsewhere
Type: filesandordirs; Name: "{app}\app"
Type: filesandordirs; Name: "{app}\php"
Type: filesandordirs; Name: "{app}\pgsql"
Type: filesandordirs; Name: "{app}\caddy"
Type: filesandordirs; Name: "{app}\launcher"

[Files]
Source: "{#Stage}\app\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\php\*"; DestDir: "{app}\php"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\pgsql\*"; DestDir: "{app}\pgsql"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\caddy\*"; DestDir: "{app}\caddy"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\launcher\*"; DestDir: "{app}\launcher"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#Stage}\README.txt"; DestDir: "{app}"; Flags: ignoreversion isreadme
Source: "{#Stage}\redist\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall; Check: VCRedistNeeded

[Icons]
Name: "{autoprograms}\Scribe\Scribe"; Filename: "{#PS}"; Parameters: "{#Launcher} start"; WorkingDir: "{app}"; IconFilename: "{app}\launcher\scribe.ico"; Comment: "Open Scribe"
Name: "{autoprograms}\Scribe\Stop Scribe"; Filename: "{#PS}"; Parameters: "{#Launcher} stop"; WorkingDir: "{app}"; IconFilename: "{app}\launcher\scribe.ico"
Name: "{autoprograms}\Scribe\Back up now"; Filename: "{#PS}"; Parameters: "{#Launcher} backup"; WorkingDir: "{app}"; IconFilename: "{app}\launcher\scribe.ico"
Name: "{autoprograms}\Scribe\Restore a backup"; Filename: "{#PS}"; Parameters: "{#Launcher} restore"; WorkingDir: "{app}"; IconFilename: "{app}\launcher\scribe.ico"
Name: "{autoprograms}\Scribe\Open the backups folder"; Filename: "{#PS}"; Parameters: "{#Launcher} open-backups"; WorkingDir: "{app}"; IconFilename: "{app}\launcher\scribe.ico"
Name: "{autoprograms}\Scribe\Read me"; Filename: "{app}\README.txt"
Name: "{autodesktop}\Scribe"; Filename: "{#PS}"; Parameters: "{#Launcher} start"; WorkingDir: "{app}"; IconFilename: "{app}\launcher\scribe.ico"; Comment: "Open Scribe"; Tasks: desktopicon

[Run]
; Microsoft's runtime for PHP and PostgreSQL, only if missing (asks for administrator rights)
Filename: "{tmp}\vc_redist.x64.exe"; Parameters: "/install /quiet /norestart"; Verb: runas; Flags: shellexec waituntilterminated; StatusMsg: "Installing the Microsoft Visual C++ runtime..."; Check: VCRedistNeeded
Filename: "{#PS}"; Parameters: "{#Launcher} start"; Description: "Open Scribe now"; Flags: postinstall nowait skipifsilent runhidden

[UninstallRun]
Filename: "{#PS}"; Parameters: "{#Launcher} stop"; Flags: runhidden waituntilterminated; RunOnceId: "StopScribe"

[Code]
// The Visual C++ 2015-2022 runtime (x64), recent enough for PHP 8.4 (built with Visual Studio 2022)
function VCRedistNeeded: Boolean;
var
  Installed, Major, Minor: Cardinal;
begin
  Result := not (RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Installed', Installed)
                 and RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Major', Major)
                 and RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Minor', Minor)
                 and (Installed = 1) and ((Major > 14) or ((Major = 14) and (Minor >= 40))));
end;

// An update: stop the running Scribe first, so its files can be replaced
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
  Launcher: String;
begin
  Result := '';
  Launcher := ExpandConstant('{app}\launcher\scribe.ps1');
  if FileExists(Launcher) then
    Exec(ExpandConstant('{#PS}'), '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + Launcher + '" stop',
         '', SW_HIDE, ewWaitUntilTerminated, Code);
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usPostUninstall then
    MsgBox('Scribe has been removed. Your patients and prescriptions are still on this PC (in '
           + ExpandConstant('{localappdata}') + '\Scribe) and your backups in Documents\Scribe Backups: '
           + 'installing Scribe again brings them back. Delete those folders yourself only if you are sure.',
           mbInformation, MB_OK);
end;
