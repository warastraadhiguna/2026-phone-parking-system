# PHP-FPM image for the Laravel backend.
# The same image runs the app (php-fpm), the queue worker and the scheduler.
FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis intl zip bcmath pcntl opcache gd \
    && apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

WORKDIR /var/www/html

# Local development target: source code is bind-mounted, nothing is baked in.
#
# Windows/macOS bind mounts expose the source as root-owned files that www-data cannot
# write (storage/, bootstrap/cache/). In this dev-only target the FPM workers therefore
# run as root, matching the CLI (artisan, composer, queue, scheduler). The production target
# below bakes the code in and runs as an unprivileged user; do not copy this setting.
FROM base AS dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && printf '[www]\nuser = root\ngroup = root\npm = dynamic\npm.max_children = 20\npm.start_servers = 4\npm.min_spare_servers = 2\npm.max_spare_servers = 6\n' > /usr/local/etc/php-fpm.d/zz-dev-user.conf
CMD ["php-fpm", "--allow-to-run-as-root"]

# ---------------------------------------------------------------------------------------------
# Production (Phase 11). Build: docker build -f docker/php/Dockerfile --target prod -t pati-parking/php:<version> .
# No secrets are baked in: configuration comes from the environment at runtime (docs/operations/deployment.md).
# ---------------------------------------------------------------------------------------------

# Frontend assets (Vite) built in a throw-away stage.
FROM node:22-alpine AS assets
WORKDIR /build
COPY backend/package.json backend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY backend/ ./
RUN npm run build

# PHP dependencies without dev packages.
FROM base AS vendor
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader

FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.validate_timestamps = 0\nopcache.memory_consumption = 192\nopcache.max_accelerated_files = 20000\n' > "$PHP_INI_DIR/conf.d/zz-prod-opcache.ini" \
    && printf '[www]\npm = dynamic\npm.max_children = 40\npm.start_servers = 8\npm.min_spare_servers = 4\npm.max_spare_servers = 12\npm.max_requests = 1000\n' > /usr/local/etc/php-fpm.d/zz-prod.conf \
    && apk del git unzip && rm -f /usr/bin/composer

COPY --chown=www-data:www-data backend/ /var/www/html/
COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor /var/www/html/vendor
COPY --chown=www-data:www-data --from=assets /build/public/build /var/www/html/public/build

# Nothing from the development checkout that could leak or break production.
# APP_ENV=build: the payment guard (rightly) refuses to boot "production" without a real gateway.
RUN rm -rf .env tests node_modules bootstrap/cache/*.php \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && APP_ENV=build php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

# FPM workers run as www-data (default pool user); the container also starts as www-data.
USER www-data
CMD ["php-fpm"]
