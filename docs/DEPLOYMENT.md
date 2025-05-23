# Self-Hosting Spendly - Deployment Guide

This guide covers various methods to deploy and self-host Spendly for production use.

## Table of Contents

- [Overview](#overview)
- [Docker Deployment](#docker-deployment)
- [Kubernetes Deployment](#kubernetes-deployment)
- [Cloud Deployments](#cloud-deployments)
- [Bare Metal Setup](#bare-metal-setup)
- [Reverse Proxy Configuration](#reverse-proxy-configuration)
- [Monitoring and Maintenance](#monitoring-and-maintenance)
- [Scaling](#scaling)
- [Backup and Recovery](#backup-and-recovery)

## Overview

Spendly is designed to be easily self-hosted with minimal configuration. Choose the deployment method that best fits your infrastructure:

- **Docker** - Simplest setup, great for personal use
- **Kubernetes** - Best for production, scalable
- **Cloud** - Managed services, easy scaling
- **Bare Metal** - Maximum control, custom setups

### System Requirements

**Minimum:**
- 1 vCPU
- 1 GB RAM
- 10 GB storage
- Ubuntu 20.04+ or equivalent

**Recommended:**
- 2 vCPUs
- 4 GB RAM
- 50 GB SSD storage
- Ubuntu 22.04 LTS

## Docker Deployment

### Quick Start

The fastest way to deploy Spendly:

```bash
# Create directory for Spendly
mkdir spendly && cd spendly

# Download docker-compose file
curl -o docker-compose.yml https://raw.githubusercontent.com/andrejvysny/spendly/main/compose.prod.yml

# Create environment file
cat > .env << 'EOF'
APP_NAME="Spendly"
APP_KEY=base64:$(openssl rand -base64 32)
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database (SQLite by default)
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite

# Mail configuration
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=your-email@example.com
MAIL_FROM_NAME="Spendly"

# GoCardless configuration
GOCARDLESS_ACCESS_TOKEN=your_access_token
GOCARDLESS_SECRET_ID=your_secret_id
GOCARDLESS_SECRET_KEY=your_secret_key
EOF

# Start Spendly
docker compose up -d
```

### Advanced Docker Setup

For production with external database:

```yaml
# docker-compose.prod.yml
version: '3.8'

services:
  app:
    image: ghcr.io/andrejvysny/spendly:latest
    container_name: spendly
    restart: unless-stopped
    ports:
      - "80:80"
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - DB_CONNECTION=mysql
      - DB_HOST=db
      - DB_DATABASE=spendly
      - DB_USERNAME=spendly
      - DB_PASSWORD=secure_password
    volumes:
      - app_storage:/var/www/html/storage
      - app_bootstrap:/var/www/html/bootstrap/cache
    depends_on:
      - db
      - redis
    networks:
      - spendly

  db:
    image: mysql:8.0
    container_name: spendly-mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: root_password
      MYSQL_DATABASE: spendly
      MYSQL_USER: spendly
      MYSQL_PASSWORD: secure_password
    volumes:
      - mysql_data:/var/lib/mysql
    ports:
      - "3306:3306"
    networks:
      - spendly

  redis:
    image: redis:7-alpine
    container_name: spendly-redis
    restart: unless-stopped
    volumes:
      - redis_data:/data
    networks:
      - spendly

  nginx:
    image: nginx:alpine
    container_name: spendly-nginx
    restart: unless-stopped
    ports:
      - "443:443"
      - "80:80"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - ./ssl:/etc/nginx/ssl:ro
    depends_on:
      - app
    networks:
      - spendly

volumes:
  app_storage:
  app_bootstrap:
  mysql_data:
  redis_data:

networks:
  spendly:
    driver: bridge
```

### SSL Configuration

Create nginx configuration with SSL:

```nginx
# nginx.conf
events {
    worker_connections 1024;
}

http {
    upstream spendly {
        server app:80;
    }

    server {
        listen 80;
        server_name your-domain.com;
        return 301 https://$server_name$request_uri;
    }

    server {
        listen 443 ssl http2;
        server_name your-domain.com;

        ssl_certificate /etc/nginx/ssl/cert.pem;
        ssl_certificate_key /etc/nginx/ssl/key.pem;
        ssl_protocols TLSv1.2 TLSv1.3;
        ssl_ciphers HIGH:!aNULL:!MD5;

        client_max_body_size 100M;

        location / {
            proxy_pass http://spendly;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
        }
    }
}
```

## Kubernetes Deployment

### Namespace and ConfigMap

```yaml
# namespace.yaml
apiVersion: v1
kind: Namespace
metadata:
  name: spendly
---
# configmap.yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: spendly-config
  namespace: spendly
data:
  APP_NAME: "Spendly"
  APP_ENV: "production"
  APP_DEBUG: "false"
  DB_CONNECTION: "mysql"
  DB_HOST: "spendly-mysql"
  DB_PORT: "3306"
  DB_DATABASE: "spendly"
  CACHE_DRIVER: "redis"
  SESSION_DRIVER: "redis"
  REDIS_HOST: "spendly-redis"
```

### Secrets

```yaml
# secrets.yaml
apiVersion: v1
kind: Secret
metadata:
  name: spendly-secrets
  namespace: spendly
type: Opaque
data:
  APP_KEY: <base64-encoded-app-key>
  DB_USERNAME: <base64-encoded-db-username>
  DB_PASSWORD: <base64-encoded-db-password>
  GOCARDLESS_ACCESS_TOKEN: <base64-encoded-token>
  GOCARDLESS_SECRET_ID: <base64-encoded-secret-id>
  GOCARDLESS_SECRET_KEY: <base64-encoded-secret-key>
```

### MySQL Deployment

```yaml
# mysql.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: spendly-mysql
  namespace: spendly
spec:
  replicas: 1
  selector:
    matchLabels:
      app: spendly-mysql
  template:
    metadata:
      labels:
        app: spendly-mysql
    spec:
      containers:
      - name: mysql
        image: mysql:8.0
        env:
        - name: MYSQL_ROOT_PASSWORD
          value: "root_password"
        - name: MYSQL_DATABASE
          value: "spendly"
        - name: MYSQL_USER
          valueFrom:
            secretKeyRef:
              name: spendly-secrets
              key: DB_USERNAME
        - name: MYSQL_PASSWORD
          valueFrom:
            secretKeyRef:
              name: spendly-secrets
              key: DB_PASSWORD
        ports:
        - containerPort: 3306
        volumeMounts:
        - name: mysql-storage
          mountPath: /var/lib/mysql
      volumes:
      - name: mysql-storage
        persistentVolumeClaim:
          claimName: mysql-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: spendly-mysql
  namespace: spendly
spec:
  selector:
    app: spendly-mysql
  ports:
  - port: 3306
    targetPort: 3306
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: mysql-pvc
  namespace: spendly
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 20Gi
```

### Redis Deployment

```yaml
# redis.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: spendly-redis
  namespace: spendly
spec:
  replicas: 1
  selector:
    matchLabels:
      app: spendly-redis
  template:
    metadata:
      labels:
        app: spendly-redis
    spec:
      containers:
      - name: redis
        image: redis:7-alpine
        ports:
        - containerPort: 6379
        volumeMounts:
        - name: redis-storage
          mountPath: /data
      volumes:
      - name: redis-storage
        persistentVolumeClaim:
          claimName: redis-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: spendly-redis
  namespace: spendly
spec:
  selector:
    app: spendly-redis
  ports:
  - port: 6379
    targetPort: 6379
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: redis-pvc
  namespace: spendly
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
```

### Spendly Application

```yaml
# app.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: spendly-app
  namespace: spendly
spec:
  replicas: 3
  selector:
    matchLabels:
      app: spendly-app
  template:
    metadata:
      labels:
        app: spendly-app
    spec:
      containers:
      - name: spendly
        image: ghcr.io/andrejvysny/spendly:latest
        ports:
        - containerPort: 80
        envFrom:
        - configMapRef:
            name: spendly-config
        - secretRef:
            name: spendly-secrets
        volumeMounts:
        - name: app-storage
          mountPath: /var/www/html/storage
        livenessProbe:
          httpGet:
            path: /health
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /health
            port: 80
          initialDelaySeconds: 5
          periodSeconds: 5
      volumes:
      - name: app-storage
        persistentVolumeClaim:
          claimName: app-storage-pvc
---
apiVersion: v1
kind: Service
metadata:
  name: spendly-app
  namespace: spendly
spec:
  selector:
    app: spendly-app
  ports:
  - port: 80
    targetPort: 80
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: app-storage-pvc
  namespace: spendly
spec:
  accessModes:
    - ReadWriteMany
  resources:
    requests:
      storage: 10Gi
```

### Ingress

```yaml
# ingress.yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: spendly-ingress
  namespace: spendly
  annotations:
    kubernetes.io/ingress.class: nginx
    cert-manager.io/cluster-issuer: letsencrypt-prod
    nginx.ingress.kubernetes.io/proxy-body-size: "100m"
spec:
  tls:
  - hosts:
    - your-domain.com
    secretName: spendly-tls
  rules:
  - host: your-domain.com
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: spendly-app
            port:
              number: 80
```

### Deploy to Kubernetes

```bash
# Apply all configurations
kubectl apply -f namespace.yaml
kubectl apply -f configmap.yaml
kubectl apply -f secrets.yaml
kubectl apply -f mysql.yaml
kubectl apply -f redis.yaml
kubectl apply -f app.yaml
kubectl apply -f ingress.yaml

# Check deployment status
kubectl get pods -n spendly
kubectl get services -n spendly
kubectl get ingress -n spendly

# Run database migrations
kubectl exec -it deployment/spendly-app -n spendly -- php artisan migrate --force
```

## Cloud Deployments

### AWS ECS with Fargate

```yaml
# ecs-task-definition.json
{
  "family": "spendly",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "512",
  "memory": "1024",
  "executionRoleArn": "arn:aws:iam::ACCOUNT:role/ecsTaskExecutionRole",
  "taskRoleArn": "arn:aws:iam::ACCOUNT:role/ecsTaskRole",
  "containerDefinitions": [
    {
      "name": "spendly",
      "image": "ghcr.io/andrejvysny/spendly:latest",
      "portMappings": [
        {
          "containerPort": 80,
          "protocol": "tcp"
        }
      ],
      "environment": [
        {
          "name": "APP_ENV",
          "value": "production"
        },
        {
          "name": "DB_CONNECTION",
          "value": "mysql"
        },
        {
          "name": "DB_HOST",
          "value": "spendly-db.cluster-xxx.us-east-1.rds.amazonaws.com"
        }
      ],
      "secrets": [
        {
          "name": "APP_KEY",
          "valueFrom": "arn:aws:secretsmanager:us-east-1:ACCOUNT:secret:spendly/app-key"
        },
        {
          "name": "DB_PASSWORD",
          "valueFrom": "arn:aws:secretsmanager:us-east-1:ACCOUNT:secret:spendly/db-password"
        }
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/ecs/spendly",
          "awslogs-region": "us-east-1",
          "awslogs-stream-prefix": "ecs"
        }
      }
    }
  ]
}
```

### DigitalOcean App Platform

```yaml
# .do/app.yaml
name: spendly
services:
- name: web
  source_dir: /
  github:
    repo: andrejvysny/spendly
    branch: main
    deploy_on_push: true
  run_command: /var/www/html/entrypoint.sh
  environment_slug: docker
  instance_count: 1
  instance_size_slug: basic-xxs
  env:
  - key: APP_ENV
    value: production
  - key: APP_DEBUG
    value: "false"
  - key: DB_CONNECTION
    value: mysql
  - key: APP_KEY
    scope: RUN_AND_BUILD_TIME
    type: SECRET
  - key: DB_PASSWORD
    scope: RUN_AND_BUILD_TIME
    type: SECRET
databases:
- name: spendly-db
  engine: MYSQL
  version: "8"
  size: db-s-1vcpu-1gb
  num_nodes: 1
```

## Bare Metal Setup

### System Preparation

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y nginx mysql-server redis-server php8.3-fpm \
  php8.3-mysql php8.3-xml php8.3-curl php8.3-mbstring \
  php8.3-zip php8.3-bcmath supervisor certbot python3-certbot-nginx

# Install Node.js and npm
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Application Setup

```bash
# Create application directory
sudo mkdir -p /var/www/spendly
sudo chown $USER:$USER /var/www/spendly

# Clone and setup application
cd /var/www/spendly
git clone https://github.com/andrejvysny/spendly.git .
composer install --no-dev --optimize-autoloader
npm ci --production
npm run build

# Set permissions
sudo chown -R www-data:www-data /var/www/spendly
sudo chmod -R 755 /var/www/spendly
sudo chmod -R 775 /var/www/spendly/storage
sudo chmod -R 775 /var/www/spendly/bootstrap/cache
```

### Database Setup

```bash
# Secure MySQL installation
sudo mysql_secure_installation

# Create database and user
sudo mysql -e "CREATE DATABASE spendly;"
sudo mysql -e "CREATE USER 'spendly'@'localhost' IDENTIFIED BY 'secure_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON spendly.* TO 'spendly'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

### Nginx Configuration

```nginx
# /etc/nginx/sites-available/spendly
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/spendly/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;

    # Handle requests
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # File upload size
    client_max_body_size 100M;
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/spendly /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL Certificate

```bash
# Get SSL certificate
sudo certbot --nginx -d your-domain.com

# Auto-renewal
sudo crontab -e
# Add: 0 12 * * * /usr/bin/certbot renew --quiet
```

### Queue Workers

```ini
# /etc/supervisor/conf.d/spendly-worker.conf
[program:spendly-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/spendly/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/spendly/storage/logs/worker.log
stopwaitsecs=3600
```

Start the workers:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start spendly-worker:*
```

## Reverse Proxy Configuration

### Cloudflare

For additional security and performance:

1. **DNS Setup**:
   - Add A record pointing to your server IP
   - Enable Cloudflare proxy (orange cloud)

2. **SSL/TLS Settings**:
   - Set to "Full (strict)"
   - Enable "Always Use HTTPS"

3. **Security Rules**:
   ```
   # Rate limiting
   (http.request.uri.path contains "/api/" and ip.src ne YOUR_IP)
   Then: Rate limit 100 requests per minute
   
   # Bot protection
   (cf.bot_management.score lt 30)
   Then: Challenge (Managed Challenge)
   ```

### Traefik (Docker)

```yaml
# traefik.yml
version: '3.8'

services:
  traefik:
    image: traefik:v2.10
    container_name: traefik
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./traefik.yml:/traefik.yml:ro
      - ./acme.json:/acme.json
    networks:
      - web

  spendly:
    image: ghcr.io/andrejvysny/spendly:latest
    container_name: spendly
    restart: unless-stopped
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.spendly.rule=Host(`your-domain.com`)"
      - "traefik.http.routers.spendly.tls=true"
      - "traefik.http.routers.spendly.tls.certresolver=myresolver"
    networks:
      - web

networks:
  web:
    external: true
```

## Monitoring and Maintenance

### Health Checks

Create monitoring scripts:

```bash
#!/bin/bash
# health-check.sh

# Check application response
if curl -f http://localhost/health >/dev/null 2>&1; then
    echo "Application: OK"
else
    echo "Application: FAILED"
    exit 1
fi

# Check database connection
if php /var/www/spendly/artisan tinker --execute="DB::connection()->getPdo();" >/dev/null 2>&1; then
    echo "Database: OK"
else
    echo "Database: FAILED"
    exit 1
fi

# Check disk space
DISK_USAGE=$(df /var/www/spendly | awk 'NR==2 {print $5}' | sed 's/%//')
if [ $DISK_USAGE -gt 80 ]; then
    echo "Disk usage high: ${DISK_USAGE}%"
    exit 1
fi

echo "All checks passed"
```

### Log Management

```bash
# Log rotation
sudo tee /etc/logrotate.d/spendly << EOF
/var/www/spendly/storage/logs/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    copytruncate
}
EOF
```

### Backup Scripts

```bash
#!/bin/bash
# backup.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/spendly"
mkdir -p $BACKUP_DIR

# Database backup
mysqldump -u spendly -p'secure_password' spendly > $BACKUP_DIR/database_$DATE.sql

# Application files backup
tar -czf $BACKUP_DIR/files_$DATE.tar.gz \
    --exclude=storage/logs \
    --exclude=node_modules \
    --exclude=vendor \
    /var/www/spendly

# Cleanup old backups (keep 30 days)
find $BACKUP_DIR -name "*.sql" -mtime +30 -delete
find $BACKUP_DIR -name "*.tar.gz" -mtime +30 -delete

echo "Backup completed: $DATE"
```

Add to crontab:

```bash
# Daily backups at 2 AM
0 2 * * * /usr/local/bin/backup.sh >> /var/log/spendly-backup.log 2>&1
```

## Scaling

### Horizontal Scaling

For high-traffic deployments:

1. **Load Balancer**: Use nginx, HAProxy, or cloud load balancer
2. **Database**: Read replicas or clustering
3. **Cache**: Redis cluster or managed cache
4. **Storage**: Shared storage (NFS, S3, etc.)

### Database Scaling

```sql
-- Read replica configuration
CREATE USER 'spendly_read'@'%' IDENTIFIED BY 'read_password';
GRANT SELECT ON spendly.* TO 'spendly_read'@'%';

-- Add to .env
DB_HOST_READ=read-replica-host
DB_USERNAME_READ=spendly_read
DB_PASSWORD_READ=read_password
```

### Performance Optimization

```bash
# Enable Laravel optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Enable OPcache
echo "opcache.enable=1" >> /etc/php/8.3/fpm/php.ini
echo "opcache.memory_consumption=256" >> /etc/php/8.3/fpm/php.ini
echo "opcache.max_accelerated_files=20000" >> /etc/php/8.3/fpm/php.ini

# Restart PHP-FPM
sudo systemctl restart php8.3-fpm
```

## Backup and Recovery

### Automated Backup Strategy

```yaml
# k8s-backup-cronjob.yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: spendly-backup
  namespace: spendly
spec:
  schedule: "0 2 * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: backup
            image: mysql:8.0
            command:
            - /bin/bash
            - -c
            - |
              mysqldump -h spendly-mysql -u spendly -p$DB_PASSWORD spendly > /backup/spendly_$(date +%Y%m%d).sql
              # Upload to S3 or other storage
            env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: spendly-secrets
                  key: DB_PASSWORD
            volumeMounts:
            - name: backup-storage
              mountPath: /backup
          volumes:
          - name: backup-storage
            persistentVolumeClaim:
              claimName: backup-pvc
          restartPolicy: OnFailure
```

### Disaster Recovery

```bash
#!/bin/bash
# restore.sh

BACKUP_FILE=$1
if [ -z "$BACKUP_FILE" ]; then
    echo "Usage: $0 <backup_file>"
    exit 1
fi

# Stop application
docker compose down

# Restore database
mysql -u spendly -p'secure_password' spendly < $BACKUP_FILE

# Start application
docker compose up -d

echo "Restore completed from $BACKUP_FILE"
```

## Security Hardening

### Firewall Configuration

```bash
# UFW setup
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### Fail2Ban

```ini
# /etc/fail2ban/jail.local
[spendly]
enabled = true
port = http,https
filter = spendly
logpath = /var/www/spendly/storage/logs/laravel.log
maxretry = 5
bantime = 3600
```

### Environment Security

```bash
# Secure .env file
chmod 600 /var/www/spendly/.env
chown www-data:www-data /var/www/spendly/.env

# Regular security updates
sudo apt update && sudo apt upgrade -y
```

This completes the comprehensive deployment guide. Choose the method that best fits your infrastructure and requirements. 