#!/usr/bin/env bash
# ECSO container entrypoint. ROLE decides what this container runs:
#   init       one-shot: secrets file, migrate, seed, storage link, first super admin (ADMIN_EMAIL/ADMIN_PASSWORD)
#   app        php-fpm + nginx on :8080
#   horizon    queue workers
#   scheduler  php artisan schedule:work
set -euo pipefail
cd /var/www/html

as_www() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }
artisan() { as_www php artisan "$@"; }

# Secrets that must survive rebuilds live in the storage volume; .env points at them. Real environment
# variables from docker-compose always win over this file (Laravel never overwrites them).
SECRETS=storage/app/docker.env
mkdir -p storage/app/public storage/framework/{cache/data,sessions,views} storage/logs
chown -R www-data:www-data storage bootstrap/cache
ln -sfn "/var/www/html/$SECRETS" .env

if [[ "$ROLE" == "init" ]]; then
    if [[ ! -s "$SECRETS" ]] || ! grep -q '^APP_KEY=base64:' "$SECRETS"; then
        rand() { php -r 'echo bin2hex(random_bytes(20));'; }
        {
            echo "APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')"
            echo "HEALTH_TOKEN=$(rand)"
            echo "FAKE_MARKETPLACE_SECRET=$(rand)"
        } > "$SECRETS"
        chown www-data:www-data "$SECRETS"
        chmod 600 "$SECRETS"
        echo "[ecso] created $SECRETS (APP_KEY, HEALTH_TOKEN)"
    fi
    artisan storage:link --force >/dev/null 2>&1 || true
    if [[ -n "${ADMIN_EMAIL:-}" && -n "${ADMIN_PASSWORD:-}" ]]; then
        # Migrates, seeds, creates the super admin once (skipped when one exists) and writes the install lock.
        artisan ecso:install --skip-env-check --admin-name="${ADMIN_NAME:-Quản trị}" \
            --admin-email="$ADMIN_EMAIL" --admin-password="$ADMIN_PASSWORD"
    else
        artisan migrate --force
        artisan db:seed --force
        echo "[ecso] no ADMIN_EMAIL/ADMIN_PASSWORD: create the super admin with"
        echo "       docker compose exec app php artisan ecso:install --skip-env-check"
    fi
    exit 0
fi

if [[ ! -s "$SECRETS" ]]; then
    echo "[ecso] $SECRETS is missing: the init service has not run." >&2
    exit 1
fi

# Caches are per container (each has its own bootstrap/cache) and built from the current env.
artisan optimize >/dev/null

case "$ROLE" in
    app)       exec supervisord -n -c /etc/supervisor/conf.d/ecso.conf ;;
    horizon)   exec setpriv --reuid=www-data --regid=www-data --init-groups php artisan horizon ;;
    scheduler) exec setpriv --reuid=www-data --regid=www-data --init-groups php artisan schedule:work ;;
    *)         echo "[ecso] unknown ROLE=$ROLE" >&2; exit 1 ;;
esac
