ARG PHP_VERSION
FROM php:$PHP_VERSION
RUN apt-get update \
    && apt-get install -y libffi-dev git unzip \
    && docker-php-source extract \
    && docker-php-ext-install ffi \
    && docker-php-source delete
WORKDIR /usr/src/z-engine
RUN curl -sS https://getcomposer.org/installer | php && mv ./composer.phar /usr/local/bin/composer
COPY . /usr/src/z-engine
RUN composer install