#!/usr/bin/env bash
#
# Daily backup (installed into /etc/cron.d/parkir by deploy/install.sh):
#   - PostgreSQL dump (custom format), kept KEEP_DAYS days
#   - private files (attendant photos, settlement proofs) mirrored, never deleted
#   - the .env (holds APP_KEY; a restore needs it), last 5 copies
#
# This is a copy on the SAME server: it protects against mistakes and a bad deploy, NOT against
# losing the server. Copy /var/backups/parkir off-site before real money data depends on it.
# Restore: docs/operations/backup-restore.md
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$APP_DIR/backend"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/parkir}"
KEEP_DAYS="${KEEP_DAYS:-14}"
DB_NAME="${DB_NAME:-parkir}"
STAMP="$(date +%Y%m%d-%H%M%S)"

umask 077
mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/files" "$BACKUP_DIR/env"

(cd /tmp && sudo -u postgres pg_dump -Fc "$DB_NAME") > "$BACKUP_DIR/db/${DB_NAME}-${STAMP}.dump"
[ -s "$BACKUP_DIR/db/${DB_NAME}-${STAMP}.dump" ] || { echo "Dump kosong!" >&2; exit 1; }
find "$BACKUP_DIR/db" -name "${DB_NAME}-*.dump" -mtime +"$KEEP_DAYS" -delete

if [ -d "$BACKEND_DIR/storage/app/private" ]; then
  if command -v rsync >/dev/null; then
    rsync -a --exclude 'exports/' "$BACKEND_DIR/storage/app/private/" "$BACKUP_DIR/files/"
  else
    cp -au "$BACKEND_DIR/storage/app/private/." "$BACKUP_DIR/files/"
  fi
fi

cp "$BACKEND_DIR/.env" "$BACKUP_DIR/env/env-${STAMP}"
ls -1t "$BACKUP_DIR"/env/env-* | tail -n +6 | xargs -r rm -f

echo "$(date -Is) backup OK: $(du -sh "$BACKUP_DIR" | cut -f1) total"
