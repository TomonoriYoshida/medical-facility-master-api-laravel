# syntax=docker/dockerfile:1

# Production image, used by compose.production.yaml for the web server, the
# queue worker and the scheduler alike. Local development uses Sail
# (compose.yaml) instead. FrankenPHP bundles Caddy, so the web container also
# terminates HTTPS itself: setting SERVER_NAME to a hostname makes Caddy obtain
# and renew a Let's Encrypt certificate automatically.
FROM dunglas/frankenphp:1-php8.5-trixie

# intl: ItaijiNormalizer (Normalizer), zip: RhbXlsxReader / bundle expanders,
# pcntl: lets queue:work stop gracefully between jobs on SIGTERM.
# mariadb-client provides the `mysql` command that `migrate` uses to load
# database/schema/mysql-schema.sql into an empty database.
RUN install-php-extensions pdo_mysql intl zip bcmath pcntl opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client unzip \
    && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# The code never changes inside a running container (deploys rebuild the
# image), so OPcache can skip checking files for changes.
COPY <<'EOF' $PHP_INI_DIR/conf.d/zz-production.ini
expose_php = Off
opcache.enable_cli = 1
opcache.validate_timestamps = 0
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
EOF

# MariaDB's client verifies the server's TLS certificate by default, but the
# bundled MySQL server only has the self-signed one it generates on first
# start. The database is reachable only on the internal Docker network.
COPY <<'EOF' /etc/mysql/conf.d/zz-client.cnf
[client]
skip-ssl
EOF

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi

# Configuration comes from environment variables at runtime (compose's
# env_file), so it can only be cached once the container starts. Not
# `optimize`: its view:cache step fails because the app has no
# resources/views (the only page, Scramble's docs UI, is a vendor view).
COPY --chmod=755 <<'EOF' /usr/local/bin/docker-entrypoint
#!/bin/sh
set -e
php artisan config:cache
php artisan route:cache
php artisan event:cache
exec "$@"
EOF

# Run as an unprivileged user that can still bind ports 80/443.
RUN useradd --create-home app \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R app:app storage bootstrap/cache /config/caddy /data/caddy

USER app

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
