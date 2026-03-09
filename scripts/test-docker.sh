#!/bin/sh
set -e

IMAGE="spendly-test"
PORT="${1:-8080}"

echo "=== Building production image ==="
docker build --target production -t "$IMAGE" -f .docker/Dockerfile .

APP_KEY="base64:$(openssl rand -base64 32)"

echo ""
echo "=== Starting Spendly on http://localhost:$PORT ==="
echo "APP_KEY: $APP_KEY"
echo "Press Ctrl+C to stop."
echo ""

docker run --rm -p "$PORT:80" \
  -e APP_NAME=Spendly \
  -e APP_ENV=production \
  -e APP_KEY="$APP_KEY" \
  -e APP_DEBUG=false \
  -e APP_URL="http://localhost:$PORT" \
  -e LOG_CHANNEL=stderr \
  -e LOG_LEVEL=debug \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/var/www/html/database/database.sqlite \
  -e SESSION_DRIVER=database \
  -e SESSION_LIFETIME=120 \
  -e SESSION_SECURE_COOKIE=false \
  -e CACHE_STORE=database \
  -e QUEUE_CONNECTION=database \
  -e FILESYSTEM_DISK=local \
  -e BROADCAST_CONNECTION=log \
  -e MAIL_MAILER=log \
  -e GOCARDLESS_USE_MOCK=false \
  "$IMAGE"
