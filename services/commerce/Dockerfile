# Commerce API on PHP-FPM. The build context is the repository root because
# the service pulls the shared PHP packages through Composer path repositories.
ARG PHP_VERSION=8.4
FROM chaos-playground/php-base:${PHP_VERSION}

WORKDIR /srv/services/commerce

# Dependencies first: this layer is rebuilt only when composer files or the packages change.
COPY packages/php /srv/packages/php
COPY services/commerce/composer.json services/commerce/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY services/commerce ./
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache

# Config and routes are cached when the container starts, not at build time:
# caching here would freeze whatever environment variables the build happened to have.
CMD ["sh", "-c", "php artisan config:cache -q && php artisan route:cache -q && php artisan event:cache -q && exec php-fpm"]
