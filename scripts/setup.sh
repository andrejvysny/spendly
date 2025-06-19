#!/bin/bash
set -e

# Check if docker compose is installed and accessible
if ! command -v docker &> /dev/null; then
    echo "❌ Error: Docker is not installed or not in PATH. Please install Docker and try again."
    exit 1
fi

if ! docker compose version &> /dev/null; then
    echo "❌ Error: 'docker compose' is not available. Please ensure you have Docker Compose V2 installed (use 'docker compose', not 'docker-compose')."
    exit 1
fi

# Check if Docker daemon is running
if ! docker info &> /dev/null; then
    echo "❌ Error: Docker daemon is not running. Please start Docker and try again."
    exit 1
fi

echo -e "\n ⤵️ Downloading Spendly configuration..."
curl -s -o compose.yml https://raw.githubusercontent.com/andrejvysny/spendly/refs/heads/main/compose.prod.yml

echo -e "\n ⚙️ Setting up Environment..."
# Only download .env.example if .env doesn't exist
if [ ! -f .env ]; then
    curl -s -o .env https://raw.githubusercontent.com/andrejvysny/spendly/refs/heads/main/.env.example
    echo "✅ Created .env file from template"
else
    echo "✅ .env file already exists"
fi

echo -e "\n 📦 Downloading Spendly image..."
docker compose pull

echo -e "\n 🔑 Checking application key..."
# Check if APP_KEY is already set in .env file
if grep -q "^APP_KEY=base64:" .env; then
    echo "✅ Application key already exists"
else
    echo "🔑 Generating application key..."
    # Generate app key using docker run directly (no TTY issues)
    docker run --rm \
        -v "$(pwd)/.env:/var/www/html/.env" \
        -v "$(pwd)/compose.yml:/var/www/html/compose.yml" \
        ghcr.io/andrejvysny/spendly:main \
        php artisan key:generate --force
    
    echo "✅ Application key generated successfully"
fi

echo -e "\n 🚀 Starting Spendly services..."
docker compose up -d
