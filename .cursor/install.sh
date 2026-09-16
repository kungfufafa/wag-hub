#!/usr/bin/env bash
# Idempotent repository bootstrap for the WhatsApp Gateway Hub (Laravel 12 + Filament 4).
# Safe to run repeatedly: dependencies, database, and built assets all converge.
set -euo pipefail

cd "$(dirname "$0")/.."

# PHP dependencies (lock file is authoritative).
composer install --no-interaction --prefer-dist --no-progress

# Node dependencies for the Vite/Tailwind build.
npm ci

# Local environment file. Only created when missing so a persisted key survives.
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate the app key only once. Regenerating would break decryption of any
# already-encrypted rows (provider credentials, message bodies).
if ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

# SQLite database file used by the default configuration.
touch database/database.sqlite

# Schema and baseline management data (seeder is safe to re-run).
php artisan migrate --force
php artisan db:seed --force

# Compiled front-end assets (Filament theme + Mekaya theme).
npm run build
