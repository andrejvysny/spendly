---
title: App Key Generation
description: How the application key generation works in the Docker setup.
---

## Overview

The Laravel application key is used for encryption. In Docker, the key must be properly saved to the host filesystem to persist across container restarts.

## Setup Script

The easiest way to generate the key:

```bash
# Download and run setup script
curl -sSL https://raw.githubusercontent.com/andrejvysny/spendly/main/scripts/setup.sh | bash

# Or run locally
./scripts/setup.sh
```

This will:

- Download the latest compose configuration
- Create `.env` file from template
- Generate application key (fast, without full initialization)
- Start the application

## Manual Key Generation

```bash
docker run --rm \
    --entrypoint="" \
    --user "$(id -u):$(id -g)" \
    -v "$(pwd)/.env:/var/www/html/.env" \
    -v "$(pwd)/compose.yml:/var/www/html/compose.yml" \
    ghcr.io/andrejvysny/spendly:main \
    php artisan key:generate --force
```

### Why these flags?

- `--entrypoint=""` — Bypass the full initialization (migrations, caching), making it run in ~2-5 seconds instead of 30-60
- `--user "$(id -u):$(id -g)"` — Run as your user for proper file ownership
- Volume mounts — Write directly to host `.env` file

## Docker Volume Mount

The `compose.prod.yml` includes a volume mount so the container writes to the host:

```yaml
volumes:
    - ./.env:/var/www/html/.env
```

## Troubleshooting

### Permission Denied

The script uses `--user "$(id -u):$(id -g)"` to run as your user. If issues persist:

```bash
chmod 644 .env
```

### TTY Error

The script uses `docker run` instead of `docker compose run` to avoid TTY allocation issues. This works in non-interactive environments like `curl | bash`.

### Key Not Generated

1. Check `.env` exists and is writable
2. Verify Docker has permission to mount the file
3. Check Docker logs for errors

### Key Regenerated on Restart

Verify the `.env` volume mount is working and the key is actually saved to the host file.

## Security Notes

- The `.env` file contains sensitive information — keep it secure
- Never commit the app key to version control
- Running as current user UID/GID follows the principle of least privilege
