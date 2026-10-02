#!/usr/bin/env bash
#
# Release an update on the server:   cd /var/www/parkir/html && bash deploy/update.sh
# Also run it after editing backend/.env. Same care as install.sh: explicit php8.4, no global
# restarts, stops on the first error.
#
set -Eeuo pipefail
trap 'echo; printf "\033[1;31mGAGAL di baris %s (perintah: %s)\033[0m\n" "$LINENO" "$BASH_COMMAND" >&2; echo "Update berhenti. Kirim output ke Claude, jangan ulangi berkali-kali." >&2' ERR

PHP="${PHP:-/usr/bin/php8.4}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
DB_NAME="${DB_NAME:-parkir}"
OWNER_PW_FILE="/root/.parkir-db-owner"
export COMPOSER_ALLOW_SUPERUSER=1
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$APP_DIR/backend"

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
art() { (cd "$BACKEND_DIR" && sudo -u www-data "$PHP" artisan "$@"); }

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root." >&2; exit 1; }
[ -s "$OWNER_PW_FILE" ] || { echo "$OWNER_PW_FILE tidak ada. Jalankan deploy/install.sh dulu." >&2; exit 1; }
cd "$APP_DIR"

say "Cadangan database sebelum update"
bash deploy/backup.sh

say "git pull"
git pull --ff-only

say "composer install"
(cd "$BACKEND_DIR" && "$PHP" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction --prefer-dist)

say "Build frontend"
(cd "$BACKEND_DIR" && npm ci --no-audit --no-fund && npm run build)
chown -R www-data:www-data "$BACKEND_DIR/storage" "$BACKEND_DIR/bootstrap/cache"

say "Migrasi (sebagai pemilik skema) dan hak akses"
art config:clear >/dev/null      # a cached config would ignore the owner credentials below
(cd "$BACKEND_DIR" && sudo -u www-data env DB_USERNAME=pati_owner DB_PASSWORD="$(cat "$OWNER_PW_FILE")" "$PHP" artisan migrate --force)
(cd /tmp && sudo -u postgres psql -v ON_ERROR_STOP=1 -q -v dbname="$DB_NAME" -v owner_password=unused -v app_password=unused -f - < "$APP_DIR/docker/postgres/production-roles.sql" >/dev/null)
art identity:sync-roles

say "Cache"
art config:cache
art view:cache
art event:cache
chown -R www-data:www-data "$BACKEND_DIR/storage" "$BACKEND_DIR/bootstrap/cache"

say "Muat ulang worker dan php-fpm (graceful)"
systemctl restart parkir-queue
systemctl reload "$PHP_FPM_SERVICE"     # reload, not restart: other sites keep serving

say "Pemeriksaan"
art cash:verify-balances || true
echo "Queue worker: $(systemctl is-active parkir-queue)"
say "Selesai. Versi sekarang: $(git log --oneline -1)"
