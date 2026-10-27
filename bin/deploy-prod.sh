#!/bin/sh
# Deploy Scribe to production (ATISCORE, ssh alias newprod) from a clean checkout of main on karokrok.
#   bin/deploy-prod.sh
# 1. ships main as a git bundle (ATISCORE has no GitHub access) into ~/scribe there;
# 2. first time only: writes ~/scribe/.env on ATISCORE with fresh random secrets (mode 600, never printed);
# 3. builds and starts docker-compose.prod.yml (the app migrates and seeds add-only on start), waits for /up;
# 4. installs the nightly backup (02:30, bin/backup.sh) in the crontab if it isn't there.
set -eu
HOST="${SCRIBE_PROD_HOST:-newprod}"
DIR="${SCRIBE_PROD_DIR:-scribe}"
URL="${SCRIBE_PROD_URL:-https://scribe.pinotech.solutions}"
ADMIN="${SCRIBE_PROD_ADMIN:-roymalto@gmail.com}"
cd "$(dirname "$0")/.."

[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || { echo "not on main" >&2; exit 1; }
git diff --quiet && git diff --cached --quiet || { echo "uncommitted changes: commit first" >&2; exit 1; }
REV=$(git rev-parse --short HEAD)
BUNDLE=$(mktemp /tmp/scribe-XXXXXX.bundle)
trap 'rm -f "$BUNDLE"' EXIT
git bundle create -q "$BUNDLE" main
scp -q "$BUNDLE" "$HOST:/tmp/scribe.bundle"
echo "shipped $REV to $HOST"

ssh "$HOST" DIR="$DIR" URL="$URL" ADMIN="$ADMIN" REV="$REV" sh -s <<'REMOTE'
set -eu
cd "$HOME"
if [ -d "$DIR/.git" ]; then
  git -C "$DIR" fetch -q /tmp/scribe.bundle main && git -C "$DIR" reset -q --hard FETCH_HEAD
else
  git clone -q -b main /tmp/scribe.bundle "$DIR"
fi
rm -f /tmp/scribe.bundle
cd "$DIR"
[ "$(git rev-parse --short HEAD)" = "$REV" ] || { echo "checkout is not $REV" >&2; exit 1; }

if [ ! -f .env ]; then
  umask 077
  gen() { openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 28; }
  cp .env.example .env
  sed -i -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
         -e "s|^APP_URL=.*|APP_URL=$URL|" -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
         -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
         -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(gen)|" -e "s|^DB_OWNER_PASSWORD=.*|DB_OWNER_PASSWORD=$(gen)|" \
         -e "s|^SCRIBE_ADMIN_EMAIL=.*|SCRIBE_ADMIN_EMAIL=$ADMIN|" -e "s|^SCRIBE_ADMIN_PASSWORD=.*|SCRIBE_ADMIN_PASSWORD=$(gen)|" \
         -e "s|^SCRIBE_DEMO_PASSWORD=.*|SCRIBE_DEMO_PASSWORD=|" -e "s|^APP_PORT=.*|APP_PORT=8100|" \
         -e 's|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS="no-reply@pinotech.solutions"|' .env
  echo "COMPOSE_PROJECT_NAME=scribe" >> .env
  echo "wrote .env (new secrets; the administrator password is SCRIBE_ADMIN_PASSWORD in ~/$DIR/.env)"
fi

docker compose -f docker-compose.prod.yml up -d --build --quiet-pull 2>&1 | tail -3
i=0
until curl -fsS -o /dev/null "http://127.0.0.1:$(grep ^APP_PORT= .env | cut -d= -f2)/up"; do
  i=$((i + 1)); [ $i -lt 60 ] || { echo "not healthy after 3 minutes" >&2; docker compose -f docker-compose.prod.yml logs --tail 40 app >&2; exit 1; }
  sleep 3
done
docker compose -f docker-compose.prod.yml ps --format '{{.Name}} {{.Status}}'

mkdir -p "$HOME/backups/scribe"
if ! crontab -l 2>/dev/null | grep -q "$DIR && bin/backup.sh"; then
  (crontab -l 2>/dev/null; echo "30 2 * * * cd \$HOME/$DIR && bin/backup.sh >> \$HOME/backups/scribe/backup.log 2>&1") | crontab -
  echo "nightly backup installed (02:30)"
fi
echo "Scribe $REV is up on $(hostname)"
REMOTE
