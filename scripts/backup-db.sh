#!/usr/bin/env bash
# ECSO database backup (Phase 8). Run as the deploy user from cron / aaPanel, never as root over SSH by hand.
#   scripts/backup-db.sh /www/backup/ecso
# Reads DB_* from the app .env. Produces <dir>/ecso-<db>-<UTC timestamp>.sql.gz[.enc] and a .sha256 file.
# Encryption: set BACKUP_ENCRYPTION_KEY_FILE to a file holding a passphrase (chmod 600) to encrypt with
# openssl AES-256-CBC + PBKDF2 before the file leaves the server. Retention: KEEP_DAYS (default 14).
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
OUT_DIR="${1:?usage: backup-db.sh <output dir>}"
KEEP_DAYS="${KEEP_DAYS:-14}"

env_value() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"; DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"; DB_PASSWORD="$(env_value DB_PASSWORD)"

mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/ecso-${DB_DATABASE}-${STAMP}.sql.gz"

# Password via a temporary option file, not the command line (visible in `ps`).
CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "${DB_PORT:-3306}" "$DB_USERNAME" "$DB_PASSWORD" > "$CNF"

# --single-transaction: consistent InnoDB snapshot without locking writers.
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --events \
    --set-gtid-purged=OFF --no-tablespaces "$DB_DATABASE" | gzip -9 > "$FILE"

if [[ -n "${BACKUP_ENCRYPTION_KEY_FILE:-}" ]]; then
    openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -in "$FILE" -out "$FILE.enc" -pass "file:$BACKUP_ENCRYPTION_KEY_FILE"
    rm -f "$FILE"; FILE="$FILE.enc"
fi
sha256sum "$FILE" > "$FILE.sha256"

find "$OUT_DIR" -name 'ecso-*.sql.gz*' -mtime +"$KEEP_DAYS" -delete
echo "Backup OK: $FILE ($(du -h "$FILE" | cut -f1))"
