#!/bin/sh
set -e

# Check if variable DB_DATABASE is set and if it is not set, set it to database/database.sqlite
if [ -z "$DB_DATABASE" ]; then
    DB_DATABASE="database/database.sqlite"
fi

# Initialize SQLite database if using SQLite and database doesn't exist
if [ "$DB_CONNECTION" = "sqlite" ] && [ ! -f "$DB_DATABASE" ]; then
    echo "Initializing SQLite database..."
    # Create database directory if it doesn't exist
    mkdir -p "$(dirname "$DB_DATABASE")"
    # Create empty database file
    touch "$DB_DATABASE"
    # Set proper permissions
    chown www-data:www-data "$DB_DATABASE"
    chmod 664 "$DB_DATABASE"
fi

# Wait for database to be ready (if using database)
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database connection..."
    while ! php artisan db:monitor --timeout=1 > /dev/null 2>&1; do
        sleep 1
    done
    echo "Database connection established"
fi

# Run migrations
echo "Running database migrations..."
php artisan migrate --force

# Clear and cache configs/routes/views
echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Optimize autoloader
echo "Optimizing autoloader..."
composer dump-autoload --optimize --no-dev

# Execute main command
echo "Starting Apache..."
exec "$@"