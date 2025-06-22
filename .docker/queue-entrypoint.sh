#!/bin/bash
set -e

echo "🚀 Starting Laravel Queue Worker..."

# Wait for database to be ready (optional - depends on your setup)
echo "⏳ Waiting for database connection..."
until php artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null; do
    echo "Database not ready, waiting 5 seconds..."
    sleep 5
done

echo "✅ Database connection established"

# Clear and cache Laravel configuration for better performance
echo "🔧 Optimizing Laravel configuration..."
php artisan config:cache 2>/dev/null || echo "⚠️  Config cache failed, continuing..."
php artisan route:cache 2>/dev/null || echo "⚠️  Route cache failed, continuing..."
php artisan view:cache 2>/dev/null || echo "⚠️  View cache failed, continuing..."

# Ensure storage permissions
echo "🔐 Setting up storage permissions..."
chmod -R 775 storage bootstrap/cache

# Function to handle graceful shutdown
graceful_shutdown() {
    echo "🛑 Received shutdown signal, stopping workers gracefully..."
    supervisorctl stop all
    echo "✅ All workers stopped"
    exit 0
}

# Trap SIGTERM and SIGINT for graceful shutdown
trap graceful_shutdown SIGTERM SIGINT

echo "✅ Queue worker initialization complete"
echo "📊 Starting Supervisor to manage queue workers..."

# Execute the command passed to the script
exec "$@" 