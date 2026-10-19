###############################################################################
# Stage 1 — Composer dependencies
###############################################################################
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

###############################################################################
# Stage 2 — Production image
###############################################################################
FROM php:8.3-cli-alpine

# Install system deps + PHP extensions
RUN apk add --no-cache \
        supervisor \
        curl \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        sqlite-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
    && rm -rf /var/cache/apk/*

# phpredis — the cache (rate limits, locks) lives in Redis.
# swoole — the Octane server: Laravel boots once per worker instead of once per
# request, which was most of the CPU under php-fpm.
# uv — Reverb's event loop. Without it ReactPHP falls back to stream_select,
# which cannot watch more than 1,024 sockets: at ~1,000 players Reverb stalled
# and every API worker waiting on a broadcast stalled with it.
RUN apk add --no-cache libuv \
    && apk add --no-cache --virtual .pecl-build $PHPIZE_DEPS libuv-dev \
    && pecl install redis-6.1.0 swoole-6.0.2 uv-0.3.0 \
    && docker-php-ext-enable redis swoole uv \
    && apk del .pecl-build

# PHP tuning
COPY docker/php.ini        /usr/local/etc/php/conf.d/99-app.ini
COPY docker/opcache.ini    /usr/local/etc/php/conf.d/10-opcache.ini

# Supervisor config
COPY docker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

# Copy application code
COPY . .

# Copy Composer vendor from stage 1
COPY --from=vendor /app/vendor ./vendor

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Expose port 80 (Octane)
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost/up || exit 1

# Cache config, routes and events at start (config needs the runtime env), as
# www-data so nothing root-owned lands in storage. Halves CPU per request; if it
# fails the app still starts, just uncached.
CMD ["sh", "-c", "su -p -s /bin/sh www-data -c 'php artisan optimize' || echo 'artisan optimize failed, running uncached' >&2; exec /usr/bin/supervisord -c /etc/supervisord.conf"]
