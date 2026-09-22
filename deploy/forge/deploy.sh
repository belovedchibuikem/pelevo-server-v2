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

# Zero-downtime Forge purges previous releases immediately after this script.
# Horizon workers still executing in the old tree make `rm` fail with
# "Directory not empty" and Forge marks the whole deploy failed.
echo "==> Stopping Horizon so the previous release can be deleted"
$php artisan horizon:terminate || true
sleep 2
pkill -f "artisan horizon" || true
sleep 2

echo "==> Deploy complete"
