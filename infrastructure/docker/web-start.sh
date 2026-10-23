#!/bin/sh
# Start php-fpm and nginx in one container (Render web service). Caches depend on runtime env.
set -e
php artisan config:cache
php artisan route:cache
php artisan event:cache
sed "s/__PORT__/${PORT:-10000}/" /etc/nginx/etijahfly.conf.template > /tmp/nginx/nginx.conf
# php-fpm logs to stderr (official image config); if it dies, /up fails and the host restarts us.
php-fpm &
exec nginx -e /dev/stderr -c /tmp/nginx/nginx.conf -g 'daemon off;'
