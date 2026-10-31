#!/usr/bin/env bash
# Zero-downtime release (DEPLOYMENT-AAPANEL.md §6). Run as the deploy user (www), not root:
#   scripts/deploy.sh /path/to/ecso-build.tar.gz
# The tarball is the CI build: code + vendor (composer install --no-dev -o) + public/build. Layout:
#   $BASE/releases/<timestamp>  $BASE/shared/.env  $BASE/shared/storage  $BASE/current -> releases/<timestamp>
set -euo pipefail
umask 022

BASE="${BASE:-/www/wwwroot/ecso}"
ARCHIVE="${1:?usage: deploy.sh <build.tar.gz>}"
KEEP="${KEEP_RELEASES:-5}"
PHP="${PHP_BIN:-php}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/health}"

if [[ "$(id -u)" == "0" ]]; then echo "Do not deploy as root." >&2; exit 1; fi

RELEASE="$BASE/releases/$(date -u +%Y%m%d%H%M%S)"
PREVIOUS="$(readlink -f "$BASE/current" 2>/dev/null || true)"
mkdir -p "$RELEASE" "$BASE/shared/storage"
tar -xzf "$ARCHIVE" -C "$RELEASE"

# Shared state.
rm -rf "$RELEASE/storage"
ln -sfn "$BASE/shared/storage" "$RELEASE/storage"
ln -sfn "$BASE/shared/.env" "$RELEASE/.env"
mkdir -p "$BASE/shared/storage"/{app/public,framework/{cache,sessions,views},logs}
# Writable dirs for the PHP user only (never 777).
chmod -R u+rwX,g+rwX,o-w "$BASE/shared/storage" "$RELEASE/bootstrap/cache"

cd "$RELEASE"
"$PHP" artisan ecso:check-env --production
"$PHP" artisan down --retry=15 --render="errors::503" || true
"$PHP" artisan migrate --force
"$PHP" artisan config:cache && "$PHP" artisan route:cache && "$PHP" artisan view:cache && "$PHP" artisan event:cache
"$PHP" artisan storage:link --force >/dev/null 2>&1 || true

# Atomic switch.
ln -sfn "$RELEASE" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
"$PHP" artisan up
# Workers restart on the new code (Supervisor brings Horizon back).
"$PHP" artisan horizon:terminate || true
# PHP-FPM opcache: reload if the deploy user is allowed to (aaPanel: /etc/init.d/php-fpm-83 reload).
if [[ -n "${FPM_RELOAD_CMD:-}" ]]; then eval "$FPM_RELOAD_CMD"; fi

if ! curl -fsS --max-time 10 "$HEALTH_URL" >/dev/null; then
    echo "Health check failed — rolling back to $PREVIOUS" >&2
    if [[ -n "$PREVIOUS" ]]; then
        ln -sfn "$PREVIOUS" "$BASE/current.tmp" && mv -Tf "$BASE/current.tmp" "$BASE/current"
        "$PHP" "$PREVIOUS/artisan" horizon:terminate || true
    fi
    exit 1
fi

# Keep the last $KEEP releases.
ls -1dt "$BASE"/releases/* | tail -n +"$((KEEP + 1))" | xargs -r rm -rf
echo "Deployed $RELEASE"
