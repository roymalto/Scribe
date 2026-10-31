#!/bin/sh
# Builds Scribe-Setup-<version>.exe, the local edition for one Windows PC (no Docker on the PC). Run on karokrok
# from the project folder:   sh installer/windows/build.sh
#
# What goes in: the app as built into the scribe:prod image (code, vendor, built assets), PHP 8.4 for Windows,
# PostgreSQL 17 for Windows (server and client tools only), Caddy, Microsoft's Visual C++ runtime installer, and the
# launcher (installer/windows/launcher). Downloads are pinned and checked, and kept in ~/.cache/scribe-windows.
# Inno Setup runs in the amake/innosetup image (Wine). The result lands in installer/windows/dist.
set -eu
cd "$(dirname "$0")/../.."
HERE=installer/windows
CACHE="${HOME}/.cache/scribe-windows"
STAGE="${CACHE}/stage"
VERSION=$(cat VERSION)

PHP_ZIP=php-8.4.27-1-nts-Win32-vs17-x64.zip
PHP_URL=https://windows.php.net/downloads/releases/$PHP_ZIP
PHP_SHA256=dbae154a0a89791f36c3d48e68bae797878333e7d6cb5721e74617de59b63ab7
PG_ZIP=postgresql-17.9-1-windows-x64-binaries.zip
PG_URL=https://get.enterprisedb.com/postgresql/$PG_ZIP
PG_SHA256=e4b31879202d87894375eee6cfc88adf5afbb030687a3674361d78e427357f24        # as downloaded 2026-10-31
CADDY_ZIP=caddy_2.11.7_windows_amd64.zip
CADDY_URL=https://github.com/caddyserver/caddy/releases/download/v2.11.7/$CADDY_ZIP
CADDY_SHA512=c308154504e53755958ffc62d8a2657ab17eefd6dd25c45dbb2c3d93d29519971f77ca652f7d0f2bcb7f980b9ba3822478865e29969d0272d7613550a43152b1
VCREDIST_URL=https://aka.ms/vs/17/release/vc_redist.x64.exe

fetch() {   # file url
  [ -s "$CACHE/$1" ] || { echo "Downloading $1"; curl -fL --retry 3 -o "$CACHE/$1.part" "$2" && mv "$CACHE/$1.part" "$CACHE/$1"; }
}
mkdir -p "$CACHE"
fetch "$PHP_ZIP" "$PHP_URL"
fetch "$PG_ZIP" "$PG_URL"
fetch "$CADDY_ZIP" "$CADDY_URL"
fetch vc_redist.x64.exe "$VCREDIST_URL"
echo "$PHP_SHA256  $CACHE/$PHP_ZIP" | sha256sum -c -
echo "$PG_SHA256  $CACHE/$PG_ZIP" | sha256sum -c -
echo "$CADDY_SHA512  $CACHE/$CADDY_ZIP" | sha512sum -c -

echo "Staging in $STAGE"
rm -rf "$STAGE" && mkdir -p "$STAGE/php" "$STAGE/caddy" "$STAGE/redist"
unzip -q "$CACHE/$PHP_ZIP" -d "$STAGE/php"
unzip -q "$CACHE/$CADDY_ZIP" caddy.exe -d "$STAGE/caddy"
# PostgreSQL: the server and its tools only (no pgAdmin, StackBuilder, docs, headers or debug symbols)
unzip -q "$CACHE/$PG_ZIP" 'pgsql/bin/*' 'pgsql/lib/*' 'pgsql/share/*' -d "$STAGE"
find "$STAGE/pgsql" -name '*.pdb' -delete
rm -rf "$STAGE/pgsql/lib/"*.lib "$STAGE/pgsql/share/doc"
cp "$CACHE/vc_redist.x64.exe" "$STAGE/redist/"

# The app, exactly as the production image has it
docker compose -f docker-compose.prod.yml build app
cid=$(docker create scribe:prod)
docker cp "$cid:/var/www/html" "$STAGE/app"
docker rm "$cid" >/dev/null
rm -rf "$STAGE/app/.env" "$STAGE/app/tests" "$STAGE/app/docker" "$STAGE/app/installer" "$STAGE/app/storage" \
       "$STAGE/app/bootstrap/cache/"*.php
cp -r "$HERE/launcher" "$STAGE/launcher"
cp "$HERE/README.txt" "$STAGE/README.txt"

mkdir -p "$HERE/dist"
docker run --rm -v "$STAGE:/stage:ro" -v "$PWD/$HERE:/work" amake/innosetup:latest \
  "/DMyVersion=$VERSION" "/DStage=Z:\\stage" "/OZ:\\work\\dist" scribe.iss
ls -la "$HERE/dist"
