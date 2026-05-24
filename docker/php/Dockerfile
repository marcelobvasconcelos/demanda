FROM php:8.2-apache

# Instala dependências necessárias e extensões PHP
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
    && docker-php-ext-install pdo pdo_mysql curl zip \
    && pecl channel-update pecl.php.net \
    && pecl install grpc protobuf || true \
    && docker-php-ext-enable grpc protobuf || true \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

ENV GOOGLE_APPLICATION_CREDENTIALS=/var/www/html/firebase_credenciais.json

EXPOSE 80

# Usar bind-mount de ./src para código durante desenvolvimento
