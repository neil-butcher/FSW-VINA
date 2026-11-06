FROM composer:2 AS composer

FROM node:20 AS frontend
WORKDIR /app
COPY . .
RUN npm ci && npm run build

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libpng-dev libxml2-dev \
    && docker-php-ext-install gd intl pdo_mysql soap zip \
    && a2enmod rewrite \
    && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY --from=laravelbase /composer.json /opt/laravelbase/composer.json
COPY --from=laravelbase /src /opt/laravelbase/src
COPY --from=laravelbase /config /opt/laravelbase/config
COPY --from=laravelbase /database /opt/laravelbase/database
COPY --from=laravelbase /resources /opt/laravelbase/resources
COPY --from=laravelbase /routes /opt/laravelbase/routes
COPY . .
COPY --from=frontend /app/public/build /opt/vina-assets
COPY docker/local-entrypoint.sh /usr/local/bin/vina-local-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/vina-local-entrypoint \
    && chmod +x /usr/local/bin/vina-local-entrypoint

# Install the locked dependencies, substituting the adjacent checkout for the private package.
RUN cp composer.lock /tmp/composer.lock \
    && php -r '$lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR); $found = false; foreach ($lock["packages"] as &$package) { if ($package["name"] === "utrecht_university/hts_appteam_laravelbase") { $package["dist"] = ["type" => "path", "url" => "/opt/laravelbase", "reference" => null]; $package["transport-options"] = ["symlink" => false]; $found = true; break; } } if (!$found) { throw new RuntimeException("Base package missing from composer.lock"); } file_put_contents("composer.lock", json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));' \
    && composer install --no-interaction --prefer-dist --no-scripts \
    && mv /tmp/composer.lock composer.lock

ENTRYPOINT ["vina-local-entrypoint"]
CMD ["apache2-foreground"]
