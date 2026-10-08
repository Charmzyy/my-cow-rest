# ---------------------------------------------------------------------------
# my-cow-rest: Laravel 9 API image
# Build:  docker build -t cow-rest:dev .
# Run:    needs a .env at RUN time (env_file / --env-file); none is baked in.
# ---------------------------------------------------------------------------

# 1. Base image
# cli variant = just the `php` binary; `artisan serve` (PHP's built-in server) is our web server.
# (fpm would need nginx in front: production-style, a later exercise.)
# Pin further to php:8.2.<patch>-cli-bookworm for fully repeatable builds (Docker Hub → Tags).
# Named "base": docker-compose.test.yml stops here (PHP + extensions + composer) and mounts the code.
FROM php:8.2-cli-bookworm AS base

# 2. System packages (Debian, via apt)
# libzip-dev: needed to compile the PHP zip extension. unzip: Composer unpacks packages with it.
# update + install + cleanup in ONE RUN: a later `rm` can't shrink an earlier layer.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev unzip \
 && rm -rf /var/lib/apt/lists/*

# 3. PHP extensions (via docker-php-ext-install)
# Only what `php -m` lacked: pdo_mysql (DB_CONNECTION=mysql), zip (Composer).
RUN docker-php-ext-install pdo_mysql zip

# 4. Composer: copy the binary out of the official image (no curl | php)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ---- Production stage (the last stage, so a plain `docker build .` / CI publish builds this) ----
FROM base AS prod

# 5a. Dependencies FIRST, code later (layer caching).
# This layer only rebuilds when composer.json/lock change, not on every controller edit.
#   --no-dev          skip phpunit/faker/etc.
#   --no-scripts      artisan isn't here yet, so the package:discover script would fail
#   --no-autoloader   app/ isn't here yet; build the autoloader after the code is copied
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader

# 5b. Now the code (.dockerignore keeps out .env, vendor, logs, uploads, .git)
COPY . .

# Build the optimised autoloader. This also fires post-autoload-dump → artisan package:discover.
# storage:link creates public/storage → storage/app/public, so uploaded images get a URL.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && php artisan storage:link

# 6. Permissions: www-data (uid 33, already in the php image) owns ONLY what Laravel writes to.
# Code stays root-owned = read-only for the app. chown must happen before USER (only root can chown).
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data

# 7. Port + start command
# EXPOSE is documentation only; publishing happens with -p / compose `ports:`.
# Exec form (JSON array): php is PID 1 and receives SIGTERM, so `docker stop` is instant.
EXPOSE 8001
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8001"]
