FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    zip \
    unzip \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY ./php.ini "$PHP_INI_DIR/conf.d/"
RUN rm -rf /usr/local/etc/php-fpm.d/www.conf

# Usuario no root alineado con el UID del host para evitar que los archivos
# generados dentro del contenedor (storage/, vendor/) queden como root.
RUN groupadd -g "1000" app 2>/dev/null || true \
    && useradd -u "1000" -g "1000" -m -s /bin/bash app 2>/dev/null || true

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]