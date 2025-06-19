# Application Key Generation in Docker Setup

## Overview

This document explains how the application key generation works in the Docker setup and the changes made to ensure the key is properly saved to the host filesystem.

## Problem

The original `setup.sh` script had an issue where the application key was generated inside the Docker container but wasn't saved back to the host's `.env` file. This caused the app key to be regenerated on every container restart, which could lead to:

- Session data loss
- Encrypted data becoming inaccessible
- Inconsistent application state

Additionally, when running the script via `curl | bash`, users encountered a "the input device is not a TTY" error when using `docker compose run`.

## Solution

### 1. Volume Mounting in `compose.prod.yml`

Added a volume mount to allow the container to write to the host's `.env` file:

```yaml
volumes:
  - ./.env:/var/www/html/.env
```

This ensures that when the container generates the app key, it writes directly to the host filesystem.

### 2. Improved Setup Script

The `scripts/setup.sh` script was updated to:

- Check if `.env` file already exists before downloading
- Check if `APP_KEY` is already set before generating
- Use `docker run` directly instead of `docker compose run` to avoid TTY issues
- Bypass the entrypoint script to avoid unnecessary initialization steps
- Provide better error handling and user feedback

### 3. Key Generation Process

The app key generation now works as follows:

1. **Check existing key**: Script checks if `APP_KEY=base64:` already exists in `.env`
2. **Direct container execution**: Uses `docker run` with `.env` file mounted and entrypoint bypassed
3. **Generate key**: Runs `php artisan key:generate --force` in container
4. **Verify**: Confirms key was generated successfully

## Usage

### First-time Setup

```bash
# Download and run setup script
curl -sSL https://raw.githubusercontent.com/andrejvysny/spendly/main/scripts/setup.sh | bash

# Or run locally if you have the repository
./scripts/setup.sh
```

This will:
- Download the latest compose configuration
- Create `.env` file from template
- Generate application key (fast, without full initialization)
- Start the application (with full initialization via entrypoint)

### Manual Key Generation

If you need to regenerate the key manually:

```bash
# Generate key using docker run (no TTY issues, no entrypoint)
docker run --rm \
    --entrypoint="" \
    -v "$(pwd)/.env:/var/www/html/.env" \
    -v "$(pwd)/compose.yml:/var/www/html/compose.yml" \
    ghcr.io/andrejvysny/spendly:main \
    php artisan key:generate --force
```

## Security Considerations

- The `.env` file contains sensitive information and should be kept secure
- The app key should never be committed to version control
- The key generation process uses `--force` flag to overwrite existing keys if needed

## Troubleshooting

### TTY Error

If you encounter "the input device is not a TTY" error:

- The script now uses `docker run` instead of `docker compose run`
- This approach doesn't require TTY allocation
- Works perfectly in non-interactive environments like `curl | bash`

### Key Not Generated

If the app key is not being generated:

1. Check that the `.env` file exists and is writable
2. Verify Docker has permission to mount the `.env` file
3. Check Docker logs for any errors during key generation
4. Ensure the container has write permissions to `/var/www/html/.env`

### Permission Issues

If you encounter permission issues:

```bash
# Ensure .env file is writable
chmod 644 .env

# Check Docker volume permissions
docker run --rm --entrypoint="" -v "$(pwd)/.env:/var/www/html/.env" ghcr.io/andrejvysny/spendly:main ls -la /var/www/html/.env
```

### Container Restart Issues

If the app key is being regenerated on restart:

1. Verify the `.env` file volume mount is working
2. Check that the key is actually saved to the host `.env` file
3. Ensure the container is reading from the mounted `.env` file

## File Changes Summary

- `compose.prod.yml`: Added `.env` volume mount
- `scripts/setup.sh`: Improved key generation logic using `docker run` with entrypoint bypass
- `docs/APP_KEY_GENERATION.md`: This documentation file

## Technical Details

### Docker Commands Used

- `docker run --rm`: Run container and remove it when done
- `--entrypoint=""`: Bypass the container's entrypoint script
- `-v "$(pwd)/.env:/var/www/html/.env"`: Mount host `.env` file to container
- `-v "$(pwd)/compose.yml:/var/www/html/compose.yml"`: Mount compose file for context
- `ghcr.io/andrejvysny/spendly:main`: Use the official Spendly image

### Why `docker run` instead of `docker compose run`?

- **No TTY issues**: `docker run` doesn't try to allocate a pseudo-TTY by default
- **Simpler**: No need for temporary compose override files
- **More reliable**: Works consistently across different environments
- **Better for automation**: Perfect for scripts and CI/CD pipelines

### Why `--entrypoint=""`?

- **Fast execution**: Bypasses the full initialization process (migrations, caching, etc.)
- **Focused purpose**: Only runs the specific command needed (key generation)
- **Efficiency**: Avoids unnecessary database setup and configuration caching
- **Clean separation**: Key generation is separate from application startup

### Environment Variables

The key generation process respects the following environment variables:
- `APP_KEY`: The Laravel application key (base64 encoded)
- `APP_ENV`: Application environment (local, production, etc.)
- `DB_CONNECTION`: Database connection type (sqlite, mysql, etc.)

## Performance Comparison

### Before (with entrypoint):
- Runs database migrations
- Caches configuration, routes, and views
- Optimizes autoloader
- Discovers packages
- Then generates app key
- **Total time**: ~30-60 seconds

### After (bypassing entrypoint):
- Directly generates app key
- **Total time**: ~2-5 seconds 