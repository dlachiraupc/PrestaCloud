FROM php:8.2-apache

# Mismo PHP 8.2 que el hosting. Extensiones mínimas: PDO/mysqli para MySQL 5.7, zip para Composer.
# El núcleo de cálculo (src/Core) no usa extensiones: trabaja con enteros (céntimos).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip \
    && rm -rf /var/lib/apt/lists/*

# La raíz web es public/ (único punto de entrada). .htaccess necesita mod_rewrite y AllowOverride.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite headers expires deflate \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-local.ini

WORKDIR /var/www/html
