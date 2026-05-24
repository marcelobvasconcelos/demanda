FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zlib1g-dev \
        libzip-dev \
        libssl-dev \
        pkg-config \
        cmake \
        make \
        autoconf \
        g++ \
        ca-certificates \
        curl \
        libcurl4-openssl-dev \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && docker-php-ext-install pdo pdo_mysql curl zip \
    && pecl channel-update pecl.php.net \
    && pecl install grpc protobuf || true \
    && docker-php-ext-enable grpc protobuf || true \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

ENV GOOGLE_APPLICATION_CREDENTIALS=/etc/secrets/firebase_credenciais.json

COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY src/ ./

EXPOSE 80
