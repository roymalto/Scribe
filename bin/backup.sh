#!/bin/sh
# Nightly backup of a Scribe production install: the database (pg_dump, custom format) and the private files
# (clinic logos, prescription PDFs). Keeps 30 days. Run from the install folder, e.g. from cron at 02:30:
#   30 2 * * * cd ~/scribe && bin/backup.sh >> ~/backups/scribe/backup.log 2>&1
set -eu
DEST="${SCRIBE_BACKUP_DIR:-$HOME/backups/scribe}"
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$DEST"
umask 077
dc="docker compose -f docker-compose.prod.yml"
$dc exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$DEST/scribe-db-$STAMP.dump"
$dc exec -T app tar -C /var/www/html/storage/app -czf - private > "$DEST/scribe-files-$STAMP.tar.gz"
find "$DEST" -name 'scribe-*' -mtime +30 -delete
echo "$(date '+%F %T') backup ok: $(du -h "$DEST/scribe-db-$STAMP.dump" | cut -f1) db, $(du -h "$DEST/scribe-files-$STAMP.tar.gz" | cut -f1) files"
