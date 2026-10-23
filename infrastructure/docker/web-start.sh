#!/bin/sh
# Start php-fpm and nginx in one container (Render web service). Caches depend on runtime env.
set -e
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Single-service hosting (e.g. Render's free tier: no pre-deploy step, no worker services).
# Both switches are off by default; render.yaml runs these as a pre-deploy step and separate workers.
if [ "${RUN_RELEASE_TASKS:-false}" = "true" ]; then
    php artisan migrate --force
    php artisan db:seed --class=PermissionSeeder --force
    php artisan db:seed --class=ProviderSeeder --force
    php artisan platform:harden-db
    php artisan platform:storage-setup
fi
if [ "${RUN_WORKERS_IN_WEB:-false}" = "true" ]; then
    # Same limits as the Horizon supervisor (config/horizon.php); jobs re-dispatch themselves to retry.
    # Restarted if it exits; --max-time recycles the worker so memory stays bounded.
    (while true; do php artisan queue:work --queue=default --sleep=3 --tries=1 --timeout=60 --memory=128 --max-time=3600; sleep 5; done) &
    (while true; do php artisan schedule:work; sleep 5; done) &
fi

sed "s/__PORT__/${PORT:-10000}/" /etc/nginx/etijahfly.conf.template > /tmp/nginx/nginx.conf
# php-fpm logs to stderr (official image config); if it dies, /up fails and the host restarts us.
php-fpm &
exec nginx -e /dev/stderr -c /tmp/nginx/nginx.conf -g 'daemon off;'
