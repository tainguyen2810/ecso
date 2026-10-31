#!/usr/bin/env bash
# End-to-end test of the web setup wizard against a fresh, uninstalled copy of this checkout.
#
# Needs: built assets (public/build), vendor/, an EMPTY MySQL database and a running Redis.
#   INSTALL_DB_DATABASE=ecso_wizard INSTALL_DB_USERNAME=root INSTALL_DB_PASSWORD=root scripts/e2e-install.sh
set -euo pipefail

: "${INSTALL_DB_DATABASE:?empty database name}"
: "${INSTALL_DB_USERNAME:?database user}"
export INSTALL_DB_HOST="${INSTALL_DB_HOST:-127.0.0.1}" INSTALL_DB_PORT="${INSTALL_DB_PORT:-3306}" INSTALL_DB_PASSWORD="${INSTALL_DB_PASSWORD:-}"
PORT="${INSTALL_PORT:-8001}"
SRC="$(cd "$(dirname "$0")/.." && pwd)"
FRESH="$(mktemp -d)/ecso"

# A copy without .env, logs, caches or installer markers: what a user uploads to aaPanel.
mkdir -p "$FRESH"
tar -C "$SRC" --exclude=./vendor --exclude=./node_modules --exclude=./.git --exclude=./.env --exclude=./storage/logs/*.log \
    --exclude=./storage/app/installed.lock --exclude=./storage/app/install-token.txt \
    --exclude=./storage/framework/sessions/* --exclude=./storage/framework/views/* --exclude=./bootstrap/cache/*.php \
    --exclude=./public/storage --exclude=./test-results --exclude=./docs -cf - . | tar -C "$FRESH" -xf -
# vendor/ is identical and large (installed from source in some environments): link it instead of copying.
ln -s "$SRC/vendor" "$FRESH/vendor"

# Variables from the CI job or the developer's shell would override the fresh .env; start clean.
env -i PATH="$PATH" HOME="$HOME" php "$FRESH/artisan" serve --host=127.0.0.1 --port="$PORT" > "$FRESH/serve.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
sleep 3

INSTALL_BASE_URL="http://127.0.0.1:$PORT" INSTALL_TOKEN_FILE="$FRESH/storage/app/install-token.txt" \
    npx playwright test e2e/install.spec.ts

echo "Fresh copy: $FRESH"
test -f "$FRESH/.env" && test -f "$FRESH/storage/app/installed.lock"
