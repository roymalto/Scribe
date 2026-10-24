# Scribe. One Dockerfile, two targets:
#   dev   PHP 8.4 + Apache + Composer; the code is mounted from the host (docker-compose.yml)
#   prod  code, PHP dependencies (no dev tools) and built CSS/JS baked in (docker-compose.prod.yml)

# ---------------------------------------------------------------- base
FROM php:8.4-apache AS base
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev \
        libfreetype6-dev libwebp-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
 && docker-php-ext-install pdo_pgsql intl zip opcache bcmath pcntl gd \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/app/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/app/php.ini "$PHP_INI_DIR/conf.d/scribe.ini"
WORKDIR /var/www/html

# ---------------------------------------------------------------- dev
FROM base AS dev
RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------- PHP dependencies (build only)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------------------------------------------------------------- CSS/JS (build only)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
                   ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------------------------------------------------------------- prod
FROM base AS prod
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
RUN { echo 'opcache.validate_timestamps=0'; echo 'opcache.memory_consumption=128'; } > "$PHP_INI_DIR/conf.d/opcache-prod.ini"
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
RUN php artisan package:discover --ansi \
 && rm -rf tests docs node_modules .env storage/logs/* storage/framework/*/* \
 && chown -R www-data:www-data bootstrap/cache storage
COPY docker/app/entrypoint.sh /usr/local/bin/scribe-entrypoint
RUN chmod +x /usr/local/bin/scribe-entrypoint
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/up") === false ? 1 : 0);'
ENTRYPOINT ["scribe-entrypoint"]
CMD ["apache2-foreground"]
