# Laravel Octane with FrankenPHP Dockerized Setup

This project uses Laravel Octane with FrankenPHP for high-performance PHP application serving in production. FrankenPHP is a modern application server that combines the speed of a Go server with PHP's JIT compilation capabilities.

## Features

- **Laravel Octane**: Keeps your application in memory between requests for maximum performance
- **FrankenPHP**: Modern PHP runtime and application server supporting HTTP/3 and TLS
- **Production Ready**: Optimized PHP and OPcache settings with JIT compilation enabled
- **Small Image Size**: Alpine-based for minimal container footprint
- **Centralized Logging**: All logs forwarded to stdout/stderr for Docker logs integration
- **Process Management**: Uses supervisord for reliable process management and auto-restart

## Requirements

- Docker and Docker Compose
- Port 8000 for HTTP and 443 for HTTPS 

## Usage

### Build and Start

```bash
# Build and start containers
docker-compose up -d

# View logs
docker-compose logs -f app

# Check container status
docker-compose ps
```

### Environment Configuration

The application uses environment variables from the Docker Compose file. The essential settings are:

```
APP_ENV=production 
LOG_CHANNEL=stderr
OCTANE_SERVER=frankenphp
DB_CONNECTION=pgsql
DB_HOST=db
```

## How FrankenPHP Works with Octane

FrankenPHP is integrated with Laravel Octane to provide several benefits:

1. **In-Memory Application**: Octane boots your application once and keeps it in memory between requests
2. **Coroutine Support**: Concurrent request handling for improved throughput
3. **HTTP/3 and TLS**: Built-in HTTP/3 support with automatic TLS certificate generation
4. **Worker Management**: Automatic scaling of workers based on server load

## Process Management with Supervisor

The application uses supervisord to manage processes:

1. **Automatic Restarts**: If Octane crashes, it will be automatically restarted
2. **Process Monitoring**: Supervisor watches the Octane process and ensures it remains running
3. **Graceful Shutdown**: Proper termination of processes during container shutdown
4. **Centralized Logging**: All process logs are directed to Docker logs

You can view supervisor status with:

```bash
docker-compose exec app supervisorctl status
```

## Performance Tuning

### Worker Configuration

The number of workers is set to auto-detect based on CPU cores. You can adjust this in the supervisord.conf file:

```
--workers=N --task-workers=M
```

Recommended settings:
- `workers`: Set to CPU cores or `auto` for automatic detection
- `task-workers`: Set to ~25% of workers for background tasks

### OPcache and JIT

The configuration includes optimized OPcache settings with JIT compilation enabled:

```
opcache.jit=1255
opcache.jit_buffer_size=100M
```

These settings provide significant performance improvements for PHP code execution.

## Persistence and Volumes

The setup includes volumes for:

1. PostgreSQL data for database persistence
2. Storage/public directory for uploaded files

## Health Checks

The application has a built-in health check endpoint at `/health` that returns a 200 status code, used by Docker to monitor the container health.

## Troubleshooting

### Common Issues

1. **Memory Limits**: If you encounter memory issues, adjust the `memory_limit` in `.docker/php/php.ini`
2. **Database Connection**: Ensure PostgreSQL is running and accessible
3. **File Permissions**: If you encounter file permission issues, the container runs as www-data
4. **Process Issues**: Check supervisor logs for process-related problems

### Checking Logs

```bash
# Application logs
docker-compose logs -f app

# Database logs
docker-compose logs -f db

# Supervisor process logs
docker-compose exec app supervisorctl tail octane
``` 