# Application Key Generation in Docker Setup

## Overview

This document explains how the application key generation works in the Docker setup and the changes made to ensure the key is properly saved to the host filesystem.

## Problem

The original `setup.sh` script had an issue where the application key was generated inside the Docker container but wasn't saved back to the host's `.env` file. This caused the app key to be regenerated on every container restart, which could lead to:

- Session data loss
- Encrypted data becoming inaccessible
- Inconsistent application state

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
- Use a temporary Docker Compose override to mount the `.env` file during key generation
- Provide better error handling and user feedback

### 3. Key Generation Process

The app key generation now works as follows:

1. **Check existing key**: Script checks if `APP_KEY=base64:` already exists in `.env`
2. **Create temporary override**: Creates `docker-compose.keygen.yml` with `.env` volume mount
3. **Generate key**: Runs `php artisan key:generate --force` in container
4. **Clean up**: Removes temporary compose file
5. **Verify**: Confirms key was generated successfully

## Usage

### First-time Setup

```bash
./scripts/setup.sh
```

This will:
- Download the latest compose configuration
- Create `.env` file from template
- Generate application key
- Start the application

### Testing Key Generation

```bash
./scripts/test-keygen.sh
```

This will test the key generation process in isolation.

### Manual Key Generation

If you need to regenerate the key manually:

```bash
# Create temporary compose override
cat > docker-compose.keygen.yml << EOF
services:
  app:
    volumes:
      - ./.env:/var/www/html/.env
EOF

# Generate key
docker compose -f compose.yml -f docker-compose.keygen.yml run --rm app php artisan key:generate --force

# Clean up
rm docker-compose.keygen.yml
```

## Security Considerations

- The `.env` file contains sensitive information and should be kept secure
- The app key should never be committed to version control
- The key generation process uses `--force` flag to overwrite existing keys if needed
- The temporary compose files are cleaned up after use

## Troubleshooting

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
docker compose run --rm app ls -la /var/www/html/.env
```

### Container Restart Issues

If the app key is being regenerated on restart:

1. Verify the `.env` file volume mount is working
2. Check that the key is actually saved to the host `.env` file
3. Ensure the container is reading from the mounted `.env` file

## File Changes Summary

- `compose.prod.yml`: Added `.env` volume mount
- `scripts/setup.sh`: Improved key generation logic
- `scripts/test-keygen.sh`: Added test script for validation
- `docs/APP_KEY_GENERATION.md`: This documentation file 