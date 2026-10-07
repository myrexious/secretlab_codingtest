# syntax=docker/dockerfile:1

# FrankenPHP embeds PHP inside Caddy, a Go HTTP server. One image, one process.
# No nginx, no PHP-FPM, no supervisor.
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql redis opcache zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Traefik terminates TLS. Caddy must serve plain HTTP and must not request
# a certificate of its own.
ENV SERVER_NAME=":80"

# ---------------------------------------------------------------- dev --------
# Dev dependencies plus the coverage driver. pcov is about 10x faster than
# xdebug for coverage-only runs.
FROM base AS dev

RUN install-php-extensions pcov

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --no-interaction

# --------------------------------------------------------------- prod --------
FROM base AS prod

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

# file_get_contents returns false on a 4xx or 5xx response, so this needs no
# curl binary in the image.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/health') ? 0 : 1);"
