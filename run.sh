#!/usr/bin/env bash
# IntelliShop Local Development Server Launcher

PORT="${PORT:-8000}"
HOST="${HOST:-127.0.0.1}"

# Keep local startup isolated from production values that may be present in .env.
export APP_ENV="${APP_ENV:-local}"
export APP_DEBUG="${APP_DEBUG:-true}"
export APP_URL="${APP_URL:-http://${HOST}:${PORT}}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-database/database.sqlite}"
export SESSION_DRIVER="${SESSION_DRIVER:-file}"
export CACHE_STORE="${CACHE_STORE:-file}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export ANALYTICS_SERVICE_URL="${ANALYTICS_SERVICE_URL:-http://127.0.0.1:8001}"

if [ ! -f vendor/autoload.php ]; then
    echo "Dependencies are missing. Run composer install first." >&2
    exit 1
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f "${DB_DATABASE:-database/database.sqlite}" ]; then
    mkdir -p database
    touch "${DB_DATABASE:-database/database.sqlite}"
fi

php artisan migrate --force
php artisan intellishop:import-local-dataset

php artisan serve --host="${HOST}" --port="${PORT}" --no-reload
