#!/usr/bin/env bash
# IntelliShop Local Development Server Launcher

PORT="${PORT:-8000}"
HOST="${HOST:-127.0.0.1}"

php artisan serve --host="${HOST}" --port="${PORT}"
