# Stage 1: Frontend assets
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# Stage 2: Composer deps
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist --ignore-platform-reqs --no-scripts

# Stage 3: Production (FrankenPHP + s6-overlay)
FROM dunglas/frankenphp:1-php8.4-bookworm AS production

# System packages: sqlite3 (WAL pragma), curl (healthcheck + s6 download)
RUN apt-get update && apt-get install -y --no-install-recommends sqlite3 curl xz-utils && \
    rm -rf /var/lib/apt/lists/*

# s6-overlay install (multi-arch)
ARG S6_OVERLAY_VERSION=3.2.0.2
RUN case "$(dpkg --print-architecture)" in \
        amd64) S6_ARCH="x86_64" ;; \
        arm64) S6_ARCH="aarch64" ;; \
        *) echo "Unsupported arch" && exit 1 ;; \
    esac && \
    curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz" -o /tmp/s6-overlay-noarch.tar.xz && \
    curl -fsSL "https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-${S6_ARCH}.tar.xz" -o /tmp/s6-overlay-arch.tar.xz && \
    tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz && \
    tar -C / -Jxpf /tmp/s6-overlay-arch.tar.xz && \
    rm /tmp/s6-overlay-*.tar.xz

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN install-php-extensions \
    pdo_sqlite \
    pdo_mysql \
    pdo_pgsql \
    mbstring \
    bcmath \
    zip \
    intl \
    opcache \
    pcntl \
    redis

WORKDIR /app

# Create necessary directories
RUN mkdir -p storage/logs \
    storage/framework/{sessions,views,cache} \
    bootstrap/cache \
    database

# Copy deps and assets from build stages
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Copy application files
COPY . .

# Clear any dev-cached package discovery
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# PHP config
COPY .docker/php/php.ini "$PHP_INI_DIR/conf.d/99-custom.ini"
COPY .docker/php/opcache.ini "$PHP_INI_DIR/conf.d/99-opcache.ini"

# Caddyfile
COPY .docker/Caddyfile /etc/caddy/Caddyfile

# s6-overlay service definitions
COPY .docker/s6-overlay/ /etc/s6-overlay/
RUN chmod +x /etc/s6-overlay/scripts/init-app \
    /etc/s6-overlay/s6-rc.d/svc-octane/run \
    /etc/s6-overlay/s6-rc.d/svc-worker/run \
    /etc/s6-overlay/s6-rc.d/svc-scheduler/run

# Set permissions
RUN chown -R www-data:www-data /app && \
    chmod -R 775 storage bootstrap/cache database

HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:80/up || exit 1

EXPOSE 80 443 443/udp

# s6-overlay: 90s graceful shutdown (must exceed worker --timeout=90)
ENV S6_KILL_GRACETIME=90000
# s6-overlay: pass container env to services
ENV S6_KEEP_ENV=1

ENTRYPOINT ["/init"]

# Stage 4: Development (no s6-overlay)
FROM production AS development
RUN install-php-extensions xdebug
ENV APP_ENV=local
ENTRYPOINT []
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=80", "--admin-port=2019", "--watch"]

# Stage 5: Testing
FROM dunglas/frankenphp:1-php8.4-bookworm AS testing

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN install-php-extensions \
    pdo_sqlite \
    mbstring \
    bcmath \
    zip \
    opcache \
    pcntl \
    xdebug

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

RUN mkdir -p storage/logs \
    storage/framework/{sessions,views,cache} \
    bootstrap/cache \
    database

RUN echo "xdebug.mode=coverage" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.start_with_request=yes" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini"

RUN echo "memory_limit = 512M" >> "$PHP_INI_DIR/conf.d/99-testing.ini" && \
    echo "max_execution_time = 300" >> "$PHP_INI_DIR/conf.d/99-testing.ini"

COPY . .

RUN chown -R www-data:www-data /app && \
    chmod -R 775 storage bootstrap/cache database

CMD ["php", "artisan", "test"]

# Stage 6: Node (dev tooling)
FROM node:22-alpine AS node
RUN npm install -g npm@11.4
WORKDIR /app
COPY package*.json ./
RUN npm install
