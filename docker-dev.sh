#!/bin/bash

# Script to set up development environment with Docker

echo "🚀 Setting up development environment..."

# Build all services
docker-compose build
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to build Docker services. Exiting."
    exit 1
fi

# Install dependencies
echo "📦 Installing PHP dependencies..."
docker-compose run --rm cli composer install
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to install PHP dependencies. Exiting."
    exit 1
fi

echo "📦 Installing Node.js dependencies..."
docker-compose run --rm node npm install
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to install Node.js dependencies. Exiting."
    exit 1
fi

# Build frontend assets
echo "🔨 Building frontend assets..."
docker-compose run --rm node npm run build
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to build frontend assets. Exiting."
    exit 1
fi

# Set up Laravel
echo "⚙️ Setting up Laravel..."
docker-compose run --rm cli php artisan key:generate
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to generate Laravel app key. Exiting."
    exit 1
fi
docker-compose run --rm cli php artisan config:cache
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to cache Laravel config. Exiting."
    exit 1
fi
docker-compose run --rm cli php artisan route:cache
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to cache Laravel routes. Exiting."
    exit 1
fi
docker-compose run --rm cli php artisan view:cache
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to cache Laravel views. Exiting."
    exit 1
fi

# Set permissions
echo "🔐 Setting permissions..."
docker-compose run --rm cli chmod -R 775 storage bootstrap/cache
if [ $? -ne 0 ]; then
    echo "❌ Error: Failed to set permissions. Exiting."
    exit 1
fi

echo "✅ Development environment ready!"
echo "🌐 Laravel app: http://localhost:80"
echo "📊 Node.js dev server: http://localhost:3000"
echo "🧪 Run tests: ./docker-test.sh" 