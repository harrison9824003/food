# Shared base image for php-fpm, queue worker, and scheduler

FROM php:8.2-fpm-alpine AS base

# Permanent runtime libraries needed by PHP extensions at runtime
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    linux-headers \
    libpng \
    libjpeg-turbo \
    libwebp \
    freetype \
    libzip \
    oniguruma \
    icu-libs

# Build and install PHP extensions in one step.
# $PHPIZE_DEPS is added under .build-deps so gcc/autoconf survive the
# docker-php-ext-install cleanup of its own .phpize-deps-configure virtual
# package, allowing pecl to compile redis afterward.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# ── Development ────────────────────────────────────────────────────────────────
FROM base AS development

COPY php.ini /usr/local/etc/php/conf.d/99-custom.ini

USER www-data

CMD ["php-fpm"]

# ── Production ─────────────────────────────────────────────────────────────────
FROM base AS production

COPY php.ini /usr/local/etc/php/conf.d/99-custom.ini

COPY --chown=www-data:www-data . /var/www/backend

USER www-data

CMD ["php-fpm"]
