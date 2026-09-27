# Catalog API on PHP-FPM, pinned to PHP 8.3 because Lumen 11 is frozen there
# (docs/adr/0009-lumen-legacy-service.md). The build context is the repository
# root because the service pulls the shared PHP packages through Composer path repositories.
FROM chaos-playground/php-base:8.3

WORKDIR /srv/services/catalog

# Dependencies first: this layer is rebuilt only when composer files or the packages change.
COPY packages/php /srv/packages/php
COPY services/catalog/composer.json services/catalog/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY services/catalog ./
RUN composer dump-autoload --no-dev --classmap-authoritative

# Nothing to warm up: Lumen has no config or route cache, and the service writes
# nothing to disk (logs go to stderr, cache lives in Redis).
CMD ["php-fpm"]
