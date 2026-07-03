# Immagine app condivisa da web (php artisan serve) e worker (queue:work).
FROM php:7.3-cli

# Dipendenze di sistema per le estensioni PHP.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Estensioni PHP: pdo_mysql (DB), bcmath, mbstring, zip (pre-provisioning OpenSpout).
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath mbstring zip

# Override PHP applicativi.
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Composer 2 dall'immagine ufficiale.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000
