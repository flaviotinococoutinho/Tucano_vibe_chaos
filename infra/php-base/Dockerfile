# Base image for the PHP services: official PHP-FPM on Alpine plus every
# extension the services use. Services build FROM this image, so the slow
# extension compilation happens once per PHP version.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-alpine

ARG EXTENSIONS="apcu bcmath intl mongodb opcache pcntl pdo_mysql pdo_pgsql rdkafka redis sockets zip"
COPY --from=mlocati/php-extension-installer:2.12.0 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions ${EXTENSIONS}

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY conf.d/zz-tucano.ini /usr/local/etc/php/conf.d/zz-tucano.ini
COPY php-fpm.d/zz-tucano.conf /usr/local/etc/php-fpm.d/zz-tucano.conf

# Images carry the code, so OPcache never needs to stat files again.
# The dev overlay flips this to 1 to pick up edits without restarts.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

WORKDIR /srv
