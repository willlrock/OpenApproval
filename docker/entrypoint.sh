#!/bin/bash
set -euo pipefail

cd /var/www/html

# The key must survive container recreation and match encrypted historical data.
: "${APP_KEY:?Set a persistent APP_KEY before starting the container}"
export TEG_BACKGROUND_ENABLED=${TEG_BACKGROUND_ENABLED:-true}
case "$TEG_BACKGROUND_ENABLED" in true|false) ;; *) echo 'TEG_BACKGROUND_ENABLED must be true or false.' >&2; exit 2 ;; esac
case "${RUN_MIGRATIONS:-true}" in true|false) ;; *) echo 'RUN_MIGRATIONS must be true or false.' >&2; exit 2 ;; esac

# Wait for database
echo "⏳ Waiting for database..."
database_ready=false
for attempt in {1..60}; do
    if php artisan db:monitor --databases=pgsql > /dev/null 2>&1; then database_ready=true; break; fi
    sleep 2
done
if [ "$database_ready" != true ]; then echo 'Database unavailable after 60 attempts.' >&2; exit 1; fi
echo "✅ Database is ready"

# Run migrations
echo "🔄 Running migrations..."
if [ "${RUN_MIGRATIONS:-true}" = true ]; then
    php artisan migrate --force
    php artisan requests:privatize-files
fi

# Cache config/routes/views for performance
echo "⚡ Caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Storage link
php artisan storage:link --force 2>/dev/null || true

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache

echo "🚀 Starting services..."
exec /usr/bin/supervisord -c /etc/supervisor/teg-approval.conf
