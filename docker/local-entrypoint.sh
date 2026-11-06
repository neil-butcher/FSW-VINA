#!/bin/sh
set -e

cp -a /opt/vina-assets/. /var/www/html/public/build/
exec docker-php-entrypoint "$@"
