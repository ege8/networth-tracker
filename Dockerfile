FROM php:8.3-apache

# mbstring icin oniguruma kutuphanesi gerekli (Debian'da libonig-dev)
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mbstring
