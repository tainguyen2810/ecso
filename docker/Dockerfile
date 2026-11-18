# ECSO image: PHP-FPM 8.3 (Ubuntu 24.04) + Nginx in one container. The same image runs the web app, Horizon and the scheduler
# (ROLE=app|horizon|scheduler|init), see docker-compose.yml.

# 1) Frontend assets
FROM node:22-bookworm-slim AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY tsconfig.json vite.config.ts ./
COPY resources ./resources
COPY public ./public
RUN npx vite build

# 2) PHP dependencies (no dev packages, optimized autoloader)
FROM composer:2 AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# 3) Runtime: Ubuntu 24.04 LTS ships PHP 8.3 with every extension ECSO needs (security updates until 2029),
#    so nothing is compiled during the build.
FROM ubuntu:24.04
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl tzdata nginx supervisor mysql-client \
      php8.3-fpm php8.3-cli php8.3-bcmath php8.3-curl php8.3-intl php8.3-mbstring php8.3-mysql \
      php8.3-redis php8.3-xml php8.3-zip php8.3-gd php8.3-opcache \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default /etc/php/8.3/fpm/pool.d/www.conf \
 && ln -sf /usr/sbin/php-fpm8.3 /usr/local/sbin/php-fpm \
 && mkdir -p /run/php

COPY docker/php.ini /etc/php/8.3/fpm/conf.d/99-ecso.ini
COPY docker/php-cli.ini /etc/php/8.3/cli/conf.d/99-ecso.ini
COPY docker/fpm-pool.conf /etc/php/8.3/fpm/pool.d/ecso.conf
COPY docker/nginx.conf /etc/nginx/conf.d/ecso.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/ecso.conf
COPY docker/entrypoint.sh /usr/local/bin/ecso-entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /src/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /src/public/build ./public/build
RUN rm -rf docker tests e2e loadtest node_modules .git .env \
 && mkdir -p bootstrap/cache \
 && chown -R www-data:www-data bootstrap/cache \
 && chmod +x /usr/local/bin/ecso-entrypoint \
 && php artisan package:discover --ansi

ENV ROLE=app \
    PHP_FPM_MAX_CHILDREN=16
EXPOSE 8080
ENTRYPOINT ["ecso-entrypoint"]
