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
FROM php:8.3-fpm-alpine

# Install system deps + PHP extensions
RUN apk add --no-cache \
        nginx \
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

# phpredis — the cache (rate limits, locks) lives in Redis
RUN apk add --no-cache --virtual .redis-build $PHPIZE_DEPS \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && apk del .redis-build

# PHP tuning
COPY docker/php.ini        /usr/local/etc/php/conf.d/99-app.ini
COPY docker/opcache.ini    /usr/local/etc/php/conf.d/10-opcache.ini
COPY docker/php-fpm.conf   /usr/local/etc/php-fpm.d/zz-pool.conf

# Nginx config
COPY docker/nginx.conf     /etc/nginx/nginx.conf

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

# Expose port 80 (nginx)
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost/up || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
