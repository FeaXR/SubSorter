#!/bin/sh
set -e

cd /var/www/html

# Generate an APP_KEY once and keep it in the data volume. The stored Google
# token is encrypted with it, so it must survive container rebuilds.
if [ -z "$APP_KEY" ]; then
    if [ ! -s /data/app_key ]; then
        php artisan key:generate --show > /data/app_key
    fi
    APP_KEY="$(cat /data/app_key)"
    export APP_KEY
fi

if [ "$DB_CONNECTION" = "sqlite" ] && [ ! -f "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
fi

chown -R www-data:www-data /data storage bootstrap/cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    runuser -u www-data -- php artisan migrate --force
fi

exec "$@"
