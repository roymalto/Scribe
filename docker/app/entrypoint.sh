#!/bin/sh
# Production start-up (docker-compose.prod.yml). The web container also updates the database:
# migrations as the owner, then the reference data and first administrator (both add-only).
# The queue and scheduler containers start as www-data and only cache the configuration.
set -e
cd /var/www/html

as_www() { if [ "$(id -u)" = 0 ]; then su -s /bin/sh www-data -c "$1"; else sh -c "$1"; fi; }

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Run: docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show" >&2
  echo "then put the value in .env, and keep a copy in your password safe: without it, encrypted data can't be read." >&2
  exit 1
fi

if [ "$(id -u)" = 0 ]; then
  # storage/ is a volume: create its folders on first start.
  mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
  chown -R www-data:www-data storage bootstrap/cache
fi

if [ "$1" = "apache2-foreground" ] && [ "${SCRIBE_MIGRATE_ON_START:-1}" = "1" ]; then
  as_www "php artisan migrate --database=pgsql_owner --force --no-interaction"
  as_www "php artisan db:seed --database=pgsql_owner --force --no-interaction"
fi

as_www "php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache" >/dev/null
exec docker-php-entrypoint "$@"
