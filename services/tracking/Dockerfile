# Tracking on Swoole: one long-running PHP process serving HTTP and WebSocket.
# The build context is the repository root because the service pulls the shared
# PHP packages through Composer path repositories.
FROM chaos-playground/php-base:8.4

# Without io_uring: Docker's default seccomp profile blocks those system calls,
# and a Swoole built with them fails to start inside a container.
RUN IPE_SWOOLE_WITHOUT_IOURING=1 install-php-extensions swoole-6.2.3

WORKDIR /srv/services/tracking

# Dependencies first: this layer is rebuilt only when composer files or the packages change.
COPY packages/php /srv/packages/php
COPY services/tracking/composer.json services/tracking/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY services/tracking ./
RUN composer dump-autoload --no-dev --classmap-authoritative

USER www-data
EXPOSE 9501

# The base image stops PHP-FPM with SIGQUIT, which Swoole ignores: docker stop
# would wait out its timeout and kill the server. SIGTERM makes it drain first.
STOPSIGNAL SIGTERM
CMD ["php", "bin/server.php"]
