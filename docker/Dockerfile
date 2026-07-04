# App image shared by web (php artisan serve) and worker (queue:work).
FROM php:7.3-cli

# System dependencies for the PHP extensions.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions: pdo_mysql (DB), bcmath, mbstring, zip (OpenSpout pre-provisioning).
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath mbstring zip

# App-level PHP overrides.
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Composer 2 from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000
