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
curl -s -o .env https://raw.githubusercontent.com/andrejvysny/spendly/refs/heads/main/.env.example

echo -e "\n 📦 Downloading Spendly image..."
docker compose pull

echo -e "\n 🔑 Generating application key..."
docker compose run app php artisan key:generate

echo -e "\n 🚀 Starting Spendly services..."
docker compose up -d
