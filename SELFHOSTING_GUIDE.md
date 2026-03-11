# Self-Hosting Guide

Deploy Spendly with a single Docker container. The image includes FrankenPHP (web server), queue worker, and task scheduler — no external dependencies required.

## Quick Start

```bash
curl -fsSL https://raw.githubusercontent.com/andrejvysny/spendly/main/scripts/setup.sh | bash
```

This downloads the compose file, generates an app key, and starts Spendly on `http://localhost`.

## Manual Setup

### 1. Create a directory

```bash
mkdir spendly && cd spendly
```

### 2. Create `compose.yml`

```yaml
services:
    app:
        image: ghcr.io/andrejvysny/spendly:main
        container_name: spendly
        restart: unless-stopped
        stop_grace_period: 120s
        ports:
            - '80:80'
        environment:
            APP_KEY: ${APP_KEY}
            APP_URL: ${APP_URL:-http://localhost}
            APP_ENV: production
            APP_DEBUG: 'false'
            DB_CONNECTION: sqlite
            DB_DATABASE: /app/database/database.sqlite
            SESSION_DRIVER: database
            QUEUE_CONNECTION: database
            CACHE_STORE: database
            LOG_CHANNEL: stderr
            LOG_LEVEL: warning
        volumes:
            - app_database:/app/database
            - app_storage:/app/storage

volumes:
    app_database:
    app_storage:
```

### 3. Create `.env` and generate app key

```bash
echo "APP_KEY=" > .env
echo "APP_URL=http://localhost" >> .env

docker compose pull
docker run --rm --entrypoint="" \
  -v "$(pwd)/.env:/app/.env" \
  ghcr.io/andrejvysny/spendly:main \
  php artisan key:generate --force
```

### 4. Start

```bash
docker compose up -d
```

Spendly is now running at `http://localhost`. Register your first account in the browser.

## What's Inside the Container

The single container runs three services via s6-overlay:

| Service       | Description                              |
| ------------- | ---------------------------------------- |
| **Octane**    | FrankenPHP web server on port 80         |
| **Worker**    | Queue worker for background jobs         |
| **Scheduler** | Laravel task scheduler (cron equivalent) |

On first boot, the init script automatically:

- Creates the SQLite database file
- Runs migrations
- Enables WAL mode for SQLite
- Caches config, routes, views, and events

## Configuration

### Environment Variables

All configuration is via environment variables. Key options:

| Variable                | Default             | Description                          |
| ----------------------- | ------------------- | ------------------------------------ |
| `APP_KEY`               | _(required)_        | Encryption key (base64:...)          |
| `APP_URL`               | `http://localhost`  | Public URL of your instance          |
| `PORT`                  | `80`                | Host port mapping                    |
| `MAIL_MAILER`           | `log`               | Mail driver (`smtp`, `log`, etc.)    |
| `MAIL_HOST`             | —                   | SMTP host                            |
| `MAIL_PORT`             | —                   | SMTP port                            |
| `MAIL_USERNAME`         | —                   | SMTP username                        |
| `MAIL_PASSWORD`         | —                   | SMTP password                        |
| `MAIL_FROM_ADDRESS`     | `noreply@localhost` | Sender email address                 |
| `GOCARDLESS_SECRET_ID`  | —                   | GoCardless API secret ID (bank sync) |
| `GOCARDLESS_SECRET_KEY` | —                   | GoCardless API secret key            |
| `GOCARDLESS_USE_MOCK`   | `false`             | Use mock bank data                   |

### Using a Different Database

SQLite is the default and works well for personal use. To use MySQL or PostgreSQL instead:

```yaml
environment:
    DB_CONNECTION: mysql # or pgsql
    DB_HOST: db
    DB_PORT: 3306 # or 5432
    DB_DATABASE: spendly
    DB_USERNAME: spendly
    DB_PASSWORD: secret
```

The container includes drivers for SQLite, MySQL, and PostgreSQL.

### HTTPS with Automatic TLS

The built-in Caddy server can handle HTTPS automatically. Expose ports 443 and set your domain:

```yaml
ports:
    - '80:80'
    - '443:443'
    - '443:443/udp' # HTTP/3
environment:
    APP_URL: https://spendly.example.com
```

Caddy will automatically obtain and renew Let's Encrypt certificates. Add volumes to persist certificate data:

```yaml
volumes:
    - caddy_data:/data
    - caddy_config:/config
```

### Reverse Proxy (Nginx/Traefik)

If running behind a reverse proxy, keep only port 80 exposed and configure your proxy to forward to the container. Set `APP_URL` to your public URL.

### GoCardless Bank Sync

To enable automatic bank transaction sync:

1. Create a free account at [GoCardless Bank Account Data](https://bankaccountdata.gocardless.com/)
2. Generate API credentials (Secret ID + Secret Key)
3. Add to your environment:

```yaml
environment:
    GOCARDLESS_SECRET_ID: your-secret-id
    GOCARDLESS_SECRET_KEY: your-secret-key
```

## Operations

### View Logs

```bash
docker logs -f spendly
```

### Backup

SQLite database and uploads are stored in Docker volumes:

```bash
# Backup database
docker cp spendly:/app/database/database.sqlite ./backup.sqlite

# Or backup the volume directly
docker run --rm -v spendly_app_database:/data -v $(pwd):/backup alpine \
  cp /data/database.sqlite /backup/spendly-backup-$(date +%Y%m%d).sqlite
```

### Restore

```bash
docker compose down
docker run --rm -v spendly_app_database:/data -v $(pwd):/backup alpine \
  cp /backup/backup.sqlite /data/database.sqlite
docker compose up -d
```

### Update

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on container start.

### Health Check

The container includes a built-in health check hitting `/up` every 15 seconds:

```bash
docker inspect --format='{{.State.Health.Status}}' spendly
```

## Resource Requirements

- **RAM:** 256MB minimum, 512MB recommended
- **Disk:** ~1.7GB for the image + database storage
- **CPU:** 1 core minimum

Set Go memory limit to ~90% of container memory if restricting resources:

```yaml
environment:
    GOMEMLIMIT: 450MiB # for 512MB container limit
```
