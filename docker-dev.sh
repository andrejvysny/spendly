#!/bin/bash

# Script to set up development environment with Docker

echo "🚀 Setting up development environment..."

# Build all services
docker-compose build

# Install dependencies
echo "📦 Installing PHP dependencies..."
docker-compose run --rm cli composer install

echo "📦 Installing Node.js dependencies..."
docker-compose run --rm node npm install

# Build frontend assets
echo "🔨 Building frontend assets..."
docker-compose run --rm node npm run build

# Set up Laravel
echo "⚙️ Setting up Laravel..."
docker-compose run --rm cli php artisan key:generate
docker-compose run --rm cli php artisan config:cache
docker-compose run --rm cli php artisan route:cache
docker-compose run --rm cli php artisan view:cache

# Set permissions
echo "🔐 Setting permissions..."
docker-compose run --rm cli chmod -R 775 storage bootstrap/cache

echo "✅ Development environment ready!"
echo "🌐 Laravel app: http://localhost:80"
echo "📊 Node.js dev server: http://localhost:3000"
echo "🧪 Run tests: ./docker-test.sh" 