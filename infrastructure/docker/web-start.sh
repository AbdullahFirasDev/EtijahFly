#!/bin/sh
# Start php-fpm and nginx in one container (Render web service). Caches depend on runtime env.
set -e
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Single-service hosting (e.g. Render's free tier: no pre-deploy step, no worker services).
# Both switches are off by default; render.yaml runs these as a pre-deploy step and separate workers.
# Without Redis, pair them with QUEUE_CONNECTION=database and, while there is exactly one instance,
# CACHE_STORE=file (rate limiting and locks then cost no database round trips).
if [ "${RUN_RELEASE_TASKS:-false}" = "true" ]; then
    # Blocking: the code being started must never run against an older schema.
    php artisan migrate --force
    # The rest is idempotent and slow with a distant database, so it runs in the background (the host
    # gives up if the port does not open in time) and only once per release, not on every cold start.
    php artisan platform:release-tasks --once &
fi
if [ "${RUN_WORKERS_IN_WEB:-false}" = "true" ]; then
    # Same limits as the Horizon supervisor (config/horizon.php); jobs re-dispatch themselves to retry.
    # Restarted if it exits; --max-time recycles the worker so memory stays bounded.
    (while true; do php artisan queue:work --queue=default --sleep=3 --tries=1 --timeout=60 --memory=128 --max-time=3600; sleep 5; done) &
    (while true; do php artisan schedule:work; sleep 5; done) &
fi

# /tmp can be a fresh empty filesystem at run time, so the build-time directory may be gone.
mkdir -p /tmp/nginx
sed "s/__PORT__/${PORT:-10000}/" /etc/nginx/etijahfly.conf.template > /tmp/nginx/nginx.conf
# php-fpm logs to stderr (official image config); if it dies, /up fails and the host restarts us.
php-fpm &
exec nginx -e /dev/stderr -c /tmp/nginx/nginx.conf -g 'daemon off;'
