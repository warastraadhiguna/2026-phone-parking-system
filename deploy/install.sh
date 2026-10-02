#!/usr/bin/env bash
#
# First-time install of the Pati Parking backend + Control Center on an Ubuntu server that
# ALREADY has nginx, php8.4-fpm, PostgreSQL and Redis. Written for a SHARED, LIVE server: it
# only adds (a new nginx site, a new database, a new systemd unit, a new cron file), never
# edits an existing site, never restarts anything global, and stops at the first surprise.
#
#   git clone https://github.com/warastraadhiguna/2026-phone-parking-system.git /var/www/parkir/html
#   cd /var/www/parkir/html && bash deploy/install.sh
#
# Safe to re-run: an existing .env, database, vhost and admin are left alone.
#
set -Eeuo pipefail

trap 'echo; printf "\033[1;31mGAGAL di baris %s (perintah: %s)\033[0m\n" "$LINENO" "$BASH_COMMAND" >&2; echo "Tidak ada langkah berikutnya yang dijalankan. Kirim seluruh output di atas ke Claude." >&2' ERR

# ---- settings (override with env vars if needed) ---------------------------
APP_DOMAIN="${APP_DOMAIN:-parking.wan-client.com}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$APP_DIR/backend"
PHP="${PHP:-/usr/bin/php8.4}"                       # explicit: this server runs 5.6 ... 8.4 side by side
PHP_FPM_SOCK="${PHP_FPM_SOCK:-/run/php/php8.4-fpm.sock}"
DB_NAME="${DB_NAME:-parkir}"
REDIS_DB_NUM="${REDIS_DB_NUM:-4}"                   # AMA uses 0/1; keep this app's keys apart
REDIS_CACHE_DB_NUM="${REDIS_CACHE_DB_NUM:-5}"
OWNER_PW_FILE="/root/.parkir-db-owner"              # password of the migration role (root only)
OTHER_SITE_CHECK="${OTHER_SITE_CHECK:-fitbull.id}"  # an existing site we re-test at the end
export COMPOSER_ALLOW_SUPERUSER=1

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!! %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mBERHENTI: %s\033[0m\n' "$*" >&2; exit 1; }
pgsu() { (cd /tmp && sudo -u postgres "$@"); }
# artisan as www-data, so files it writes (logs, cache) are readable by php-fpm
art()  { (cd "$BACKEND_DIR" && sudo -u www-data "$PHP" artisan "$@"); }
# artisan with the schema-owner role: only for migrations
art_owner() { (cd "$BACKEND_DIR" && sudo -u www-data env DB_USERNAME=pati_owner DB_PASSWORD="$(cat "$OWNER_PW_FILE")" "$PHP" artisan "$@"); }
randpw() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-32; }
roles_sql() { pgsu psql -v ON_ERROR_STOP=1 -q -v dbname="$DB_NAME" -v owner_password="$1" -v app_password="$2" -f - < "$APP_DIR/docker/postgres/production-roles.sql" >/dev/null; }

cd "$APP_DIR"
git config core.fileMode false 2>/dev/null || true

# ---- 0. preflight: look before touching anything ---------------------------
say "0/9  Pengecekan awal (belum mengubah apa pun)"

[ "$(id -u)" -eq 0 ] || die "Jalankan sebagai root (su, lalu ulangi)."
[ -f "$BACKEND_DIR/artisan" ] && [ -f deploy/nginx-vhost.conf.template ] || die "Jalankan dari dalam folder repo yang sudah di-clone."

[ -x "$PHP" ] || die "$PHP tidak ditemukan."
"$PHP" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || die "PHP terlalu lama: $("$PHP" -v | head -1). Butuh 8.4."
mods="$("$PHP" -m)"
for ext in pdo_pgsql pgsql redis gd zip mbstring xml bcmath curl fileinfo intl; do
  grep -qix "$ext" <<<"$mods" || die "Ekstensi PHP '$ext' belum ada di $PHP."
done
echo "PHP     : $("$PHP" -v | head -1)"

COMPOSER_BIN="$(command -v composer || true)"
[ -n "$COMPOSER_BIN" ] || die "composer tidak ditemukan."
head -c 200 "$COMPOSER_BIN" | head -1 | grep -q 'php' || die "$COMPOSER_BIN bukan phar composer."
command -v node >/dev/null && command -v npm >/dev/null || die "node/npm tidak ditemukan."
[ "$(node -p 'process.versions.node.split(".")[0]')" -ge 20 ] || die "Node $(node -v) terlalu lama (butuh 20+)."
echo "Node    : $(node -v) / npm $(npm -v)"

PG_MAJOR="$(pgsu psql -tAc 'show server_version_num' | cut -c1-2)"
[ "$PG_MAJOR" -ge 15 ] || die "PostgreSQL $PG_MAJOR terlalu lama (butuh 15+)."
echo "Postgres: versi $PG_MAJOR"
[ "$(redis-cli ping 2>/dev/null)" = "PONG" ] || die "Redis tidak menjawab PONG."
for n in "$REDIS_DB_NUM" "$REDIS_CACHE_DB_NUM"; do
  if [ ! -f "$BACKEND_DIR/.env" ] && [ "$(redis-cli -n "$n" dbsize | tr -d '\r')" != "0" ]; then
    die "Redis DB $n sudah berisi data aplikasi lain. Jalankan ulang dengan REDIS_DB_NUM=.. REDIS_CACHE_DB_NUM=.. yang kosong."
  fi
done
echo "Redis   : OK (DB $REDIS_DB_NUM dan $REDIS_CACHE_DB_NUM untuk aplikasi ini)"

[ -S "$PHP_FPM_SOCK" ] || die "Socket $PHP_FPM_SOCK tidak ada (php-fpm belum jalan?)."
nginx -t >/dev/null 2>&1 || { nginx -t || true; die "Konfigurasi nginx SUDAH bermasalah sebelum kita menyentuhnya. Perbaiki itu dulu."; }
echo "Nginx   : konfigurasi sekarang valid"

escaped="${APP_DOMAIN//./\\.}"
clash="$(grep -RlE "server_name[^;]*[[:space:]]${escaped}([[:space:];])" /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null \
         | grep -vxF "/etc/nginx/sites-enabled/${APP_DOMAIN}" || true)"
[ -z "$clash" ] || die "Domain ${APP_DOMAIN} sudah dipakai di nginx oleh: ${clash}"

if [ ! -f "$BACKEND_DIR/.env" ]; then
  for r in pati_owner pati_app; do
    [ "$(pgsu psql -tAc "select 1 from pg_roles where rolname='$r'")" != "1" ] || die "Role database '$r' sudah ada tetapi .env belum ada. Hubungi Claude sebelum lanjut."
  done
fi

avail_kb="$(df --output=avail -k "$APP_DIR" | tail -1 | tr -d ' ')"
[ "$avail_kb" -gt 3000000 ] || die "Ruang disk kurang dari 3 GB."
echo "Disk    : $((avail_kb / 1024 / 1024)) GB kosong"
echo "Memori  : $(free -m | awk '/^Mem:/ {print $7}') MB tersedia"

# ---- ask everything now, so the long part runs unattended ------------------
say "Data untuk Super Admin pertama"
read -r -p "Email (untuk notifikasi sertifikat HTTPS): " ADMIN_EMAIL
[[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "Format email tidak valid."
read -r -p "Username Super Admin (huruf kecil/angka/titik, mis. admin.pati): " ADMIN_USERNAME
[[ "$ADMIN_USERNAME" =~ ^[a-z0-9][a-z0-9._-]{2,49}$ ]] || die "Username tidak valid."
read -r -p "Nama lengkap Super Admin: " ADMIN_NAME
[ -n "$ADMIN_NAME" ] || die "Nama tidak boleh kosong."

# ---- 1. database + roles + .env ----------------------------------------------
say "1/9  Database dan file .env"
if [ "$(pgsu psql -tAc "select 1 from pg_database where datname='${DB_NAME}'")" != "1" ]; then
  pgsu createdb "$DB_NAME"
  echo "Database '${DB_NAME}' dibuat."
fi
pgsu psql -d "$DB_NAME" -q -c 'CREATE EXTENSION IF NOT EXISTS btree_gist'

if [ -f "$BACKEND_DIR/.env" ]; then
  warn ".env sudah ada, dipakai apa adanya."
  [ -s "$OWNER_PW_FILE" ] || die "$OWNER_PW_FILE tidak ada padahal .env ada. Hubungi Claude."
else
  OWNER_PASS="$(randpw)"; APP_PASS="$(randpw)"
  # Two roles: pati_owner (migrations only) and pati_app (runtime; cannot alter the schema,
  # cannot change or delete the ledger and audit trail). See docker/postgres/production-roles.sql.
  roles_sql "$OWNER_PASS" "$APP_PASS"
  umask 077; printf '%s' "$OWNER_PASS" > "$OWNER_PW_FILE"; umask 022
  chmod 600 "$OWNER_PW_FILE"
  PGPASSWORD="$APP_PASS" psql -h 127.0.0.1 -U pati_app -d "$DB_NAME" -tAc "select 1" | grep -q 1 \
    || die "Login database lewat password gagal (cek pg_hba.conf)."
  cat > "$BACKEND_DIR/.env" <<EOF
APP_NAME="Parkir Pati"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${APP_DOMAIN}

APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

# Redacted JSON lines, rotated by /etc/logrotate.d/parkir
LOG_CHANNEL=stack
LOG_STACK=structured
LOG_STRUCTURED_STREAM=${BACKEND_DIR}/storage/logs/app.log
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=pati_app
DB_PASSWORD='${APP_PASS}'
DB_CONNECT_TIMEOUT=3

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
CACHE_STORE=redis
CACHE_PREFIX=parkir_cache_

# Redis is shared with other applications on this server: own prefix and own DB numbers.
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_PREFIX=parkir_
REDIS_DB=${REDIS_DB_NUM}
REDIS_CACHE_DB=${REDIS_CACHE_DB_NUM}
REDIS_TIMEOUT=2
REDIS_READ_TIMEOUT=2

# nginx talks to php-fpm directly (no proxy in front): trust no forwarded headers.
TRUSTED_PROXIES=

IDENTITY_ACCESS_TOKEN_TTL_MINUTES=60
IDENTITY_REFRESH_TOKEN_TTL_DAYS=30
IDENTITY_REFRESH_REUSE_GRACE_SECONDS=60

# QRIS: Midtrans SANDBOX until production is approved in writing. Fill in the sandbox server
# key (Midtrans dashboard > Settings > Access Keys), then: bash deploy/update.sh
PAYMENT_GATEWAY=midtrans
MIDTRANS_ENVIRONMENT=sandbox
MIDTRANS_PRODUCTION_APPROVED=false
MIDTRANS_SERVER_KEY=
MIDTRANS_QRIS_ACQUIRER="airpay shopee"

REPORTING_MAP_TILE_URL=https://tile.openstreetmap.org/{z}/{x}/{y}.png
REPORTING_MAP_ATTRIBUTION="© OpenStreetMap contributors"

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@${APP_DOMAIN}"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"
EOF
  chown root:www-data "$BACKEND_DIR/.env"
  chmod 640 "$BACKEND_DIR/.env"
  echo "Password database dibuat acak dan langsung ditulis ke .env (tidak ditampilkan)."
fi

# ---- 2. PHP dependencies ------------------------------------------------------
say "2/9  composer install (beberapa menit)"
(cd "$BACKEND_DIR" && "$PHP" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --prefer-dist)

# ---- 3. frontend --------------------------------------------------------------
say "3/9  Build frontend (npm ci + vite build, beberapa menit)"
(cd "$BACKEND_DIR" && npm ci --no-audit --no-fund && npm run build)
[ -f "$BACKEND_DIR/public/build/manifest.json" ] || die "Build frontend tidak menghasilkan public/build/manifest.json."

# Everything above ran as root; php-fpm and the commands below run as www-data.
cd "$BACKEND_DIR"
mkdir -p storage/app/private storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# ---- 4. app key, migrations, roles --------------------------------------------
say "4/9  Kunci aplikasi, migrasi, hak akses"
art config:clear >/dev/null
if ! grep -q '^APP_KEY=.\+' .env; then
  KEY="$("$PHP" artisan key:generate --show)"
  sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" .env
fi
art_owner migrate --force
roles_sql unused unused          # roles exist already: this re-applies the grants to the new tables
art identity:sync-roles

# ---- 5. first Super Admin -----------------------------------------------------
say "5/9  Akun Super Admin pertama"
staff="$(pgsu psql -d "$DB_NAME" -tAc "select count(*) from users where account_type='STAFF'")"
if [ "$staff" = "0" ]; then
  echo "Anda akan diminta mengetik password (tidak terlihat). Minimal 10 karakter, huruf dan angka."
  for attempt in 1 2 3; do
    if art identity:create-super-admin "$ADMIN_USERNAME" "$ADMIN_NAME" --email="$ADMIN_EMAIL"; then break; fi
    warn "Belum berhasil (percobaan $attempt/3), ulangi."
  done
  [ "$(pgsu psql -d "$DB_NAME" -tAc "select count(*) from users where account_type='STAFF'")" != "0" ] || die "Super Admin belum terbentuk."
else
  warn "Sudah ada $staff akun staf, tidak membuat Super Admin baru."
fi

# ---- 6. caches ----------------------------------------------------------------
say "6/9  Cache konfigurasi"
art config:cache
art view:cache
art event:cache

# ---- 7. nginx vhost (new file only; existing sites untouched) ----------------
say "7/9  Nginx: situs baru ${APP_DOMAIN}"
VHOST="/etc/nginx/sites-available/${APP_DOMAIN}"
ENABLED="/etc/nginx/sites-enabled/${APP_DOMAIN}"
BACKUP="/root/nginx-backup-$(date +%Y%m%d-%H%M%S).tar.gz"
tar czf "$BACKUP" -C / etc/nginx && echo "Cadangan konfigurasi nginx: $BACKUP"

if [ -e "$VHOST" ]; then
  warn "$VHOST sudah ada, tidak ditimpa."
else
  sed -e "s|__DOMAIN__|${APP_DOMAIN}|g" \
      -e "s|__BACKEND_DIR__|${BACKEND_DIR}|g" \
      -e "s|__PHP_FPM_SOCK__|${PHP_FPM_SOCK}|g" \
      "$APP_DIR/deploy/nginx-vhost.conf.template" > "$VHOST"
fi
ln -sfn "$VHOST" "$ENABLED"
if nginx -t; then
  systemctl reload nginx        # graceful: existing connections keep being served
else
  rm -f "$ENABLED"              # take our site back out so the other sites are untouched
  nginx -t || true
  die "Konfigurasi nginx baru ditolak. Situs parkir dicabut lagi; situs lain tidak terpengaruh."
fi

# ---- 8. HTTPS -----------------------------------------------------------------
say "8/9  HTTPS (Let's Encrypt)"
SERVER_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' | head -1 || true)"
DNS_IP="$(getent ahostsv4 "$APP_DOMAIN" 2>/dev/null | awk '{print $1; exit}' || true)"
echo "IP server : ${SERVER_IP:-?}"
echo "DNS ${APP_DOMAIN} -> ${DNS_IP:-belum ada}"
CERTBOT_CMD="certbot --nginx -d ${APP_DOMAIN} --agree-tos --no-eff-email -m ${ADMIN_EMAIL} --redirect"
if [ -n "$DNS_IP" ] && [ "$DNS_IP" = "$SERVER_IP" ]; then
  certbot --nginx -d "$APP_DOMAIN" --non-interactive --agree-tos --no-eff-email -m "$ADMIN_EMAIL" --redirect \
    || warn "certbot gagal. Ulangi nanti: $CERTBOT_CMD"
else
  warn "DNS ${APP_DOMAIN} belum mengarah ke server ini, jadi HTTPS dilewati. Login baru bisa setelah HTTPS aktif."
  warn "Setelah record A ${APP_DOMAIN} -> ${SERVER_IP:-IP-server} aktif, jalankan:  $CERTBOT_CMD"
fi

# ---- 9. background worker, scheduler, backups, log rotation --------------------
say "9/9  Queue worker, scheduler, backup harian"
sed -e "s|__BACKEND_DIR__|${BACKEND_DIR}|g" -e "s|__PHP__|${PHP}|g" "$APP_DIR/deploy/parkir-queue.service.template" > /etc/systemd/system/parkir-queue.service
systemctl daemon-reload
systemctl enable --now parkir-queue
sleep 3

cat > /etc/cron.d/parkir <<EOF
# Pati Parking, installed by deploy/install.sh
* * * * * www-data cd ${BACKEND_DIR} && ${PHP} artisan schedule:run >> /dev/null 2>&1
45 2 * * * root bash ${APP_DIR}/deploy/backup.sh >> /var/log/parkir-backup.log 2>&1
EOF
chmod 644 /etc/cron.d/parkir

cat > /etc/logrotate.d/parkir <<EOF
${BACKEND_DIR}/storage/logs/*.log {
    daily
    rotate 30
    compress
    missingok
    notifempty
    copytruncate
    su www-data www-data
}
EOF

# ---- verification ---------------------------------------------------------------
say "Pemeriksaan akhir"
code() { curl -s -o /dev/null -m 30 -w '%{http_code}' -H "Host: $1" "http://127.0.0.1$2" || true; }
echo "Parkir /login            : HTTP $(code "$APP_DOMAIN" /login)   [200, atau 301 jika HTTPS aktif]"
echo "Parkir /api/v1/health/ready : HTTP $(code "$APP_DOMAIN" /api/v1/health/ready)"
echo "Situs lain (${OTHER_SITE_CHECK})   : HTTP $(code "$OTHER_SITE_CHECK" /)   [harus sama seperti sebelum instalasi]"
echo "Queue worker             : $(systemctl is-active parkir-queue)"
art cash:verify-balances || true
if ss -ltn 2>/dev/null | awk '$4 ~ /:(5432|6379)$/ && $4 !~ /^(127\.0\.0\.1|\[::1\]|::1)/ {found=1} END {exit !found}'; then
  warn "Postgres/Redis mendengarkan di alamat selain localhost. Periksa firewall/konfigurasi!"
else
  echo "Postgres & Redis         : hanya localhost (aman)"
fi

cat <<EOF

$(printf '\033[1;32m')Selesai.$(printf '\033[0m')
  Control Center : https://${APP_DOMAIN}   (login dengan username ${ADMIN_USERNAME})
  Log aplikasi   : ${BACKEND_DIR}/storage/logs/app.log
  Cadangan harian: /var/backups/parkir  (mulai 02:45)
  Update nanti   : cd ${APP_DIR} && bash deploy/update.sh
  QRIS           : isi MIDTRANS_SERVER_KEY (sandbox) di ${BACKEND_DIR}/.env, lalu bash deploy/update.sh
EOF
