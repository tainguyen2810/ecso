#!/usr/bin/env bash
# ECSO database restore (Phase 8). Restores into a TARGET database (default: a separate drill database), never
# silently over production:
#   scripts/restore-db.sh <backup file> [target db]
# Restoring over the live database requires CONFIRM_RESTORE_OVER_LIVE=yes and the app in maintenance mode.
# After restore it runs the inventory ledger check (ecso:inventory:verify) against the restored database.
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
FILE="${1:?usage: restore-db.sh <backup file> [target db]}"
env_value() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"; LIVE_DB="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"; DB_PASSWORD="$(env_value DB_PASSWORD)"
TARGET="${2:-${LIVE_DB}_restore_drill}"

if [[ "$TARGET" == "$LIVE_DB" && "${CONFIRM_RESTORE_OVER_LIVE:-}" != "yes" ]]; then
    echo "Refusing to restore over the live database '$LIVE_DB'. Set CONFIRM_RESTORE_OVER_LIVE=yes (after php artisan down)." >&2
    exit 1
fi

if [[ -f "$FILE.sha256" ]]; then sha256sum -c "$FILE.sha256"; else echo "WARNING: no checksum file next to $FILE" >&2; fi

CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "${DB_PORT:-3306}" "$DB_USERNAME" "$DB_PASSWORD" > "$CNF"

mysql --defaults-extra-file="$CNF" -e "CREATE DATABASE IF NOT EXISTS \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
if [[ "$FILE" == *.enc ]]; then
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$FILE" -pass "file:${BACKUP_ENCRYPTION_KEY_FILE:?set BACKUP_ENCRYPTION_KEY_FILE}" | gunzip | mysql --defaults-extra-file="$CNF" "$TARGET"
else
    gunzip -c "$FILE" | mysql --defaults-extra-file="$CNF" "$TARGET"
fi
echo "Restored into $TARGET"

# Integrity: the inventory ledger must still reconcile with balances in the restored copy.
(cd "$APP_DIR" && DB_DATABASE="$TARGET" php artisan ecso:inventory:verify)
echo "Restore drill OK: $TARGET"
