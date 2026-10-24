# Laravel API image (php-fpm). Used for the api, horizon and scheduler services.
FROM php:8.4-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY infrastructure/docker/php.ini /usr/local/etc/php/conf.d/zz-etijahfly.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS build
COPY apps/backend/composer.json apps/backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader
COPY apps/backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && rm -rf tests .env .env.* storage/logs/* storage/framework/cache/data/*

FROM base AS runtime
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
RUN mkdir -p storage/app/mpdf && chown -R www-data:www-data storage bootstrap/cache
USER www-data

# Config/route/event caches are built at container start because they depend on runtime env.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan event:cache && php-fpm"]

# Single-container web service for hosts that expect one process serving HTTP on $PORT
# (e.g. Render): nginx in front of php-fpm in the same container. Workers reuse this image
# with their own command (horizon / schedule:work); see render.yaml at the repository root.
FROM runtime AS web
USER root
RUN apt-get update && apt-get install -y --no-install-recommends nginx \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && mkdir -p /tmp/nginx && chown -R www-data:www-data /tmp/nginx /var/lib/nginx \
    # nginx is in the same container: keep php-fpm (FastCGI) off the network interfaces.
    && sed -i 's/^listen = 9000$/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/zz-docker.conf \
    && grep -q '^listen = 127.0.0.1:9000$' /usr/local/etc/php-fpm.d/zz-docker.conf
COPY infrastructure/docker/web-nginx.conf /etc/nginx/etijahfly.conf.template
COPY infrastructure/docker/web-start.sh /usr/local/bin/web-start
RUN chmod 755 /usr/local/bin/web-start
USER www-data
ENV PORT=10000
EXPOSE 10000
CMD ["web-start"]
