#!/bin/sh
set -eu

# Recreate writable directories when storage is supplied as a volume.
mkdir -p storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

exec docker-php-entrypoint "$@"
