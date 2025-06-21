# Build frontend assets
FROM node:20-alpine AS assets
WORKDIR /app
COPY package*.json vite.config.ts ./
COPY resources ./resources
RUN npm ci --production
RUN npm run build

# Final image using official PHP-Apache
FROM php:8.4-apache

# Set Apache document root
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

# Configure Apache
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf && \
    sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf && \
    a2enmod rewrite

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libpq-dev \
    sqlite3 \
    libsqlite3-dev \
    nano \
    apt-utils && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Copy PHP extension installer and install extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pdo_sqlite pdo_mysql mbstring bcmath zip xdebug openssl

# Install Xdebug for code coverage (optional, can be enabled via environment variable)
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create necessary directories
RUN mkdir -p /var/www/html/storage/logs && \
    mkdir -p /var/www/html/storage/framework/{sessions,views,cache} && \
    mkdir -p /var/www/html/bootstrap/cache && \
    mkdir -p /var/www/html/database

# Configure PHP to log to stderr
RUN echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/docker-php-ext-error-log.ini && \
    echo "log_errors = On" >> /usr/local/etc/php/conf.d/docker-php-ext-error-log.ini && \
    echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/docker-php-ext-error-log.ini

# Configure Xdebug (disabled by default, enabled via environment variable)
RUN echo "xdebug.mode=off" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.start_with_request=no" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Set up logging to stdout/stderr
RUN touch /var/www/html/storage/logs/laravel.log && \
    touch /var/www/html/storage/logs/php.log && \
    chown www-data:www-data /var/www/html/storage/logs/laravel.log /var/www/html/storage/logs/php.log && \
    ln -sf /dev/stdout /var/www/html/storage/logs/laravel.log && \
    ln -sf /dev/stderr /var/www/html/storage/logs/php.log

# Copy and set up entrypoint script
COPY .docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader

# Copy application files (excluding database)
COPY . .
COPY --from=assets /app/public/build ./public/build

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Switch to www-data user
USER www-data

# Expose port 80
EXPOSE 80

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Start Apache
CMD ["apache2-foreground"]



FROM php:8.4-fpm AS development

# Set working directory
WORKDIR /var/www/html
# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libpq-dev \
    sqlite3 \
    libsqlite3-dev \
    nano \
    apt-utils && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pdo_sqlite pdo_mysql mbstring bcmath zip xdebug openssl



# Test environment Dockerfile with Xdebug support
FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libpq-dev \
    sqlite3 \
    libsqlite3-dev \
    nano \
    apt-utils && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Copy PHP extension installer and install extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pdo_sqlite mbstring bcmath zip

# Install Xdebug for code coverage
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create necessary directories
RUN mkdir -p /var/www/html/storage/logs && \
    mkdir -p /var/www/html/storage/framework/{sessions,views,cache} && \
    mkdir -p /var/www/html/bootstrap/cache && \
    mkdir -p /var/www/html/database

# Configure PHP for testing
RUN echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/docker-php-ext-memory-limit.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/docker-php-ext-max-execution-time.ini

# Configure Xdebug for coverage
RUN echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Switch to www-data user
USER www-data

# Default command
CMD ["php", "artisan", "test"]



FROM node:22-alpine AS node
RUN  npm install -g npm@11.4
WORKDIR /app
COPY package*.json ./
RUN npm install

