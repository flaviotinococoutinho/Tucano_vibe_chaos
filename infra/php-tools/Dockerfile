# Tooling image to run Composer, PHPUnit, PHPStan and Pint on the PHP packages
# without installing PHP on the host. Services have their own images.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

COPY --from=mlocati/php-extension-installer:2.12.0 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions apcu pcov \
    && echo "apc.enable_cli=1" > /usr/local/etc/php/conf.d/zz-apcu-cli.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer/cache

WORKDIR /app
