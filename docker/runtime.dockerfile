# syntax=docker/dockerfile:1

FROM php:8.3-apache-bookworm AS php-base
WORKDIR /var/www/html

# Runtime extensions needed by Laravel, installed packages and the MySQL database.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4 libicu72 libonig5 \
        libcurl4-openssl-dev libicu-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring pdo_mysql opcache \
    && apt-get purge -y --auto-remove \
        libcurl4-openssl-dev libicu-dev libonig-dev $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite

COPY docker/runtime-apache.conf /etc/apache2/sites-available/000-default.conf
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr

FROM php-base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./artisan
COPY storage/app/country_list.csv ./storage/app/country_list.csv
RUN mkdir -p storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize \
    && composer check-platform-reqs --no-dev

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

FROM php-base AS runtime
COPY --from=dependencies /var/www/html /var/www/html
COPY --from=assets /app/public/build ./public/build
COPY --chmod=755 docker/runtime-entrypoint.sh /usr/local/bin/vina-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/vina-entrypoint \
    && ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["vina-entrypoint"]
CMD ["apache2-foreground"]
