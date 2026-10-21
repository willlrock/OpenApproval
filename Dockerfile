FROM node:22-bookworm-slim AS node
FROM composer:2 AS composer

# Verify Composer requirements on the same PHP and extensions used in production.
FROM php:8.3-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl git unzip bash nginx supervisor \
    libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libicu-dev libonig-dev \
    libreoffice-writer poppler-utils fonts-dejavu-core fonts-liberation \
    python3 python3-opencv python3-numpy \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_pgsql pgsql gd zip intl mbstring bcmath pcntl opcache \
 && rm -rf /var/lib/apt/lists/*

FROM base AS build
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
COPY . .
ARG VITE_APP_NAME=TEG-Approval
RUN mkdir -p bootstrap/cache storage/framework/views storage/framework/cache/data \
 && php artisan package:discover --ansi \
 && npm ci --no-audit --no-fund \
 && VITE_APP_NAME="$VITE_APP_NAME" npm run build \
 && composer check-platform-reqs --no-dev \
 && rm -rf /app/node_modules

FROM base AS production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/teg-approval.conf
WORKDIR /var/www/html
COPY --from=build /app /var/www/html
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod 755 /entrypoint.sh \
 && chmod 755 /var/www/html \
 && mkdir -p storage/logs storage/framework/sessions storage/framework/views \
    storage/framework/cache/data storage/app/public storage/app/private bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
ENV APP_ENV=production APP_DEBUG=false TEG_BACKGROUND_ENABLED=true
EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
