# Laravel Queue Workers in Docker

This document explains the Laravel queue worker setup for reliable job processing in production.

## 🏗️ Architecture Overview

The queue worker system uses **Supervisor** to manage multiple Laravel queue worker processes, ensuring reliability and automatic restarts.

### Key Components:
- **Docker Target**: `queue-worker` in `.docker/Dockerfile`
- **Process Manager**: Supervisor for reliability
- **Multiple Workers**: Different queues with priority handling
- **Health Monitoring**: Built-in health checks
- **Graceful Shutdowns**: Proper signal handling

## 🚀 Quick Start

### Start Queue Workers
```bash
# Start all services including queue workers
docker-compose up -d

# Start only queue workers
docker-compose up -d queue-worker

# View queue worker logs
docker-compose logs -f queue-worker
```

### Monitor Workers
```bash
# Check worker status
docker-compose exec queue-worker supervisorctl status

# Access Supervisor web interface
open http://localhost:9001
# Login: admin / admin
```

## 📋 Configuration

### Queue Configuration (`config/queue.php`)
```php
'database' => [
    'driver' => 'database',
    'after_commit' => env('QUEUE_AFTER_COMMIT', false), // ✅ Transaction safety
],
```

### Environment Variables
```env
# Queue Configuration
QUEUE_CONNECTION=database
QUEUE_AFTER_COMMIT=true          # Wait for DB commits before dispatching
LOG_CHANNEL=stderr               # Docker-friendly logging

# Worker Configuration
QUEUE_WORKER_MEMORY=128          # Memory limit in MB
QUEUE_WORKER_TIMEOUT=60          # Job timeout in seconds
QUEUE_WORKER_MAX_TIME=3600       # Worker restart after 1 hour
```

## 🔧 Worker Configuration

### Supervisor Programs

#### 1. General Queue Workers (2 processes)
```ini
[program:laravel-queue]
command=php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=128 --timeout=60
numprocs=2
```

#### 2. Default Queue (1 process)
```ini
[program:laravel-queue-default]
command=php artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600 --memory=128 --timeout=60
numprocs=1
```

#### 3. High Priority Queue (1 process)
```ini
[program:laravel-queue-high]
command=php artisan queue:work --queue=high --sleep=1 --tries=3 --max-time=1800 --memory=128 --timeout=60
numprocs=1
```

### Command Parameters Explained:
- `--sleep=3`: Wait 3 seconds when no jobs available
- `--tries=3`: Retry failed jobs 3 times
- `--max-time=3600`: Restart worker after 1 hour (prevents memory leaks)
- `--memory=128`: Restart if memory usage exceeds 128MB
- `--timeout=60`: Kill job if it runs longer than 60 seconds

## 🛡️ Production Best Practices

### 1. Database Transaction Safety
```php
// ✅ Jobs dispatched AFTER database commits
ProcessRulesJob::dispatch($user, [], null, null, $transactionIds, false);

// ✅ Using after_commit in queue config
'after_commit' => env('QUEUE_AFTER_COMMIT', true)
```

### 2. Memory Management
- Workers automatically restart when memory limit is reached
- Maximum runtime prevents memory leaks in long-running processes
- OPcache enabled for better performance

### 3. Graceful Shutdowns
```bash
# Graceful shutdown (finishes current jobs)
docker-compose stop queue-worker

# Force shutdown
docker-compose kill queue-worker
```

### 4. Health Monitoring
```yaml
healthcheck:
  test: ["CMD", "supervisorctl", "status"]
  interval: 30s
  timeout: 10s
  retries: 3
```

## 📊 Monitoring & Debugging

### Check Worker Status
```bash
# All supervisor processes
docker-compose exec queue-worker supervisorctl status

# Specific process
docker-compose exec queue-worker supervisorctl status laravel-queue:*
```

### View Logs
```bash
# Real-time logs
docker-compose logs -f queue-worker

# Laravel logs inside container
docker-compose exec queue-worker tail -f storage/logs/laravel.log
```

### Queue Management
```bash
# Check queue status
docker-compose exec app php artisan queue:work --once

# Clear failed jobs
docker-compose exec app php artisan queue:flush

# Restart workers (picks up code changes)
docker-compose exec app php artisan queue:restart
```

## 🔄 Queue Priority System

### Queue Types:
1. **`high`** - Priority jobs (ProcessRulesJob for imports)
2. **`default`** - Standard jobs
3. **`low`** - Background tasks

### Dispatching to Specific Queues:
```php
// High priority (processed first)
ProcessRulesJob::dispatch($user, [], null, null, $transactionIds, false)
    ->onQueue('high');

// Default queue
SomeJob::dispatch($data);

// Low priority
BackgroundTask::dispatch($data)->onQueue('low');
```

## 🚨 Troubleshooting

### Common Issues:

#### 1. Workers Not Processing Jobs
```bash
# Check if workers are running
docker-compose exec queue-worker supervisorctl status

# Restart workers
docker-compose exec queue-worker supervisorctl restart all
```

#### 2. Database Connection Issues
```bash
# Check database connectivity
docker-compose exec queue-worker php artisan tinker --execute="DB::connection()->getPdo();"
```

#### 3. Memory Issues
```bash
# Check memory usage
docker-compose exec queue-worker supervisorctl tail laravel-queue stderr

# Increase memory limit in supervisor config
command=php artisan queue:work --memory=256
```

#### 4. Jobs Failing Due to Transaction Issues
```env
# Enable after_commit in environment
QUEUE_AFTER_COMMIT=true
```

### Performance Tuning:

#### Scale Workers:
```yaml
# In docker-compose.yml
queue-worker:
  deploy:
    replicas: 3  # Run 3 instances
```

#### Optimize for High Throughput:
```ini
# Reduce sleep time for busy queues
command=php artisan queue:work --sleep=1 --tries=1 --max-time=1800
```

## 📈 Production Deployment

### Docker Swarm / Kubernetes
```yaml
# docker-compose.prod.yml
services:
  queue-worker:
    deploy:
      replicas: 3
      resources:
        limits:
          memory: 512M
        reservations:
          memory: 256M
```

### Environment-Specific Configuration
```env
# Production
QUEUE_CONNECTION=redis
QUEUE_AFTER_COMMIT=true
REDIS_QUEUE_CONNECTION=default

# Development
QUEUE_CONNECTION=database
QUEUE_AFTER_COMMIT=false
```

This setup ensures reliable, scalable queue processing that follows Laravel best practices and handles the database transaction safety issues mentioned in the Laravel documentation. 