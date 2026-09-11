#!/usr/bin/env bash
set -euo pipefail

# Laravel Forge deploy script for Pelevo API.
# Site root should be the `api` directory (or set WEB_DIRECTORY=public).
# Paste into Forge → Site → Deployments → Deploy Script.

cd "$FORGE_SITE_PATH" || cd "$(dirname "$0")/../.."

php=$(command -v php8.3 || command -v php)
composer=$(command -v composer)

echo "==> Installing PHP dependencies"
$composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "==> Installing frontend dependencies"
npm ci --ignore-scripts
npm run build

echo "==> Caching configuration"
$php artisan optimize:clear
$php artisan config:cache
$php artisan route:cache
$php artisan view:cache
$php artisan event:cache

echo "==> Running migrations"
$php artisan migrate --force

echo "==> Ensuring public storage link"
$php artisan storage:link || true

echo "==> Restarting Horizon"
$php artisan horizon:terminate || true

echo "==> Deploy complete"
