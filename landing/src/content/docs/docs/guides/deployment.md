---
title: Deployment
description: Deploy Spendly to production with Docker, Kubernetes, cloud platforms, or bare metal.
---

## Overview

Spendly is designed to be easily self-hosted with minimal configuration:

- **Docker** — Simplest setup, great for personal use
- **Kubernetes** — Best for production, scalable
- **Cloud** — Managed services, easy scaling
- **Bare Metal** — Maximum control, custom setups

### System Requirements

**Minimum:** 1 vCPU, 1 GB RAM, 10 GB storage, Ubuntu 20.04+

**Recommended:** 2 vCPUs, 4 GB RAM, 50 GB SSD, Ubuntu 22.04 LTS

## Docker Deployment

### Quick Start

```bash
mkdir spendly && cd spendly

curl -o docker-compose.yml https://raw.githubusercontent.com/andrejvysny/spendly/main/compose.prod.yml

cat > .env << 'EOF'
APP_NAME="Spendly"
APP_KEY=base64:$(openssl rand -base64 32)
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=your-email@example.com
MAIL_FROM_NAME="Spendly"
EOF

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
            - '80:80'
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

volumes:
    app_storage:
    app_bootstrap:
    mysql_data:
    redis_data:

networks:
    spendly:
        driver: bridge
```

### SSL with Nginx

```nginx
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
apiVersion: v1
kind: Namespace
metadata:
    name: spendly
---
apiVersion: v1
kind: ConfigMap
metadata:
    name: spendly-config
    namespace: spendly
data:
    APP_NAME: 'Spendly'
    APP_ENV: 'production'
    APP_DEBUG: 'false'
    DB_CONNECTION: 'mysql'
    DB_HOST: 'spendly-mysql'
    DB_PORT: '3306'
    DB_DATABASE: 'spendly'
    CACHE_DRIVER: 'redis'
    SESSION_DRIVER: 'redis'
    REDIS_HOST: 'spendly-redis'
```

### Application Deployment

```yaml
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
```

### Ingress

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
    name: spendly-ingress
    namespace: spendly
    annotations:
        kubernetes.io/ingress.class: nginx
        cert-manager.io/cluster-issuer: letsencrypt-prod
        nginx.ingress.kubernetes.io/proxy-body-size: '100m'
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

### Deploy

```bash
kubectl apply -f namespace.yaml
kubectl apply -f configmap.yaml
kubectl apply -f secrets.yaml
kubectl apply -f mysql.yaml
kubectl apply -f redis.yaml
kubectl apply -f app.yaml
kubectl apply -f ingress.yaml

kubectl get pods -n spendly
kubectl exec -it deployment/spendly-app -n spendly -- php artisan migrate --force
```

## Bare Metal Setup

### System Preparation

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y nginx mysql-server redis-server php8.3-fpm \
  php8.3-mysql php8.3-xml php8.3-curl php8.3-mbstring \
  php8.3-zip php8.3-bcmath supervisor certbot python3-certbot-nginx

curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Application Setup

```bash
sudo mkdir -p /var/www/spendly
cd /var/www/spendly
git clone https://github.com/andrejvysny/spendly.git .
composer install --no-dev --optimize-autoloader
npm ci --production
npm run build

sudo chown -R www-data:www-data /var/www/spendly
sudo chmod -R 755 /var/www/spendly
sudo chmod -R 775 /var/www/spendly/storage
sudo chmod -R 775 /var/www/spendly/bootstrap/cache
```

### Nginx Configuration

```nginx
# /etc/nginx/sites-available/spendly
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/spendly/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }

    client_max_body_size 100M;
}
```

Enable and get SSL:

```bash
sudo ln -s /etc/nginx/sites-available/spendly /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d your-domain.com
```

### Queue Workers

```ini
# /etc/supervisor/conf.d/spendly-worker.conf
[program:spendly-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/spendly/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/spendly/storage/logs/worker.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start spendly-worker:*
```

## Reverse Proxy

### Traefik (Docker)

```yaml
services:
    traefik:
        image: traefik:v2.10
        ports:
            - '80:80'
            - '443:443'
        volumes:
            - /var/run/docker.sock:/var/run/docker.sock:ro
            - ./acme.json:/acme.json

    spendly:
        image: ghcr.io/andrejvysny/spendly:latest
        labels:
            - 'traefik.enable=true'
            - 'traefik.http.routers.spendly.rule=Host(`your-domain.com`)'
            - 'traefik.http.routers.spendly.tls=true'
            - 'traefik.http.routers.spendly.tls.certresolver=myresolver'
```

## Monitoring

### Health Checks

```bash
#!/bin/bash
curl -f http://localhost/health >/dev/null 2>&1 && echo "App: OK" || echo "App: FAILED"
```

### Log Rotation

```
# /etc/logrotate.d/spendly
/var/www/spendly/storage/logs/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    copytruncate
}
```

### Backup

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/spendly"
mkdir -p $BACKUP_DIR

# Database
mysqldump -u spendly -p'secure_password' spendly > $BACKUP_DIR/database_$DATE.sql
# Or for SQLite:
# cp database/database.sqlite $BACKUP_DIR/database_$DATE.sqlite

# Cleanup old backups (30 days)
find $BACKUP_DIR -mtime +30 -delete
```

## Security Hardening

```bash
# Firewall
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# Secure .env
chmod 600 /var/www/spendly/.env
chown www-data:www-data /var/www/spendly/.env
```

### Security Checklist

- HTTPS enabled with valid SSL certificate
- Environment variables properly secured
- Database user has minimal required permissions
- File permissions correctly set
- Debug mode disabled in production
- Firewall configured (only ports 22, 80, 443 open)
- Regular backups scheduled
