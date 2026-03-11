#!/bin/bash
set -e

if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not installed or not in PATH."
    exit 1
fi

if ! docker compose version &> /dev/null; then
    echo "Error: 'docker compose' is not available. Install Docker Compose V2."
    exit 1
fi

if ! docker info &> /dev/null; then
    echo "Error: Docker daemon is not running."
    exit 1
fi

echo ""
echo "Downloading Spendly configuration..."
curl -s -o compose.yml https://raw.githubusercontent.com/andrejvysny/spendly/refs/heads/main/compose.prod.yml

echo ""
echo "Setting up environment..."
if [ ! -f .env ]; then
    # Minimal .env — all defaults are in compose.prod.yml
    cat > .env <<'EOF'
APP_KEY=
APP_URL=http://localhost
EOF
    echo "Created .env file"
else
    echo ".env file already exists"
fi

echo ""
echo "Downloading Spendly image..."
docker compose pull

echo ""
echo "Checking application key..."
if grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "Application key already exists"
else
    echo "Generating application key..."
    docker run --rm \
        --entrypoint="" \
        --user "$(id -u):$(id -g)" \
        -v "$(pwd)/.env:/app/.env" \
        ghcr.io/andrejvysny/spendly:main \
        php artisan key:generate --force
    echo "Application key generated"
fi

echo ""
echo "Starting Spendly..."
docker compose up -d

echo ""
echo "Spendly is starting at $(grep APP_URL .env 2>/dev/null | cut -d= -f2 || echo 'http://localhost')"
echo "Run 'docker logs -f spendly' to monitor startup."
