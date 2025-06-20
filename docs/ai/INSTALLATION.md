# Installation Guide

This guide covers different methods to install and run Spendly.

## Table of Contents

- [Quick Start (Docker)](#quick-start-docker)
- [Local Development Setup](#local-development-setup)
- [Production Deployment](#production-deployment)
- [Configuration](#configuration)
- [Troubleshooting](#troubleshooting)

## Quick Start (Docker)

The fastest way to get Spendly running is with Docker:

```bash
# Pull and run the latest image
docker run -p 80:80 ghcr.io/andrejvysny/spendly:pre-release
```

Visit `http://localhost` to access Spendly.

### Using Docker Compose (Recommended)

1. **Download the compose file**:
   ```bash
   curl -o compose.prod.yml https://raw.githubusercontent.com/andrejvysny/spendly/main/compose.prod.yml
   ```

2. **Configure environment variables**:
   ```bash
   # Create .env file
   cat > .env << EOF
   APP_KEY=$(openssl rand -base64 32)
   MAIL_HOST=your-smtp-host
   MAIL_PORT=587
   MAIL_USERNAME=your-email@example.com
   MAIL_PASSWORD=your-password
   MAIL_FROM_ADDRESS=your-email@example.com
   EOF
   ```

3. **Start the application**:
   ```bash
   docker compose -f compose.prod.yml up -d
   ```

## Local Development Setup

### Prerequisites

- **PHP 8.3+** with extensions: BCMath, Ctype, Fileinfo, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML
- **Node.js 20+** and npm
- **Composer**
- **Database**: SQLite (default), MySQL 8.0+, or PostgreSQL 13+

### Installation Steps

1. **Clone the repository**:
   ```bash
   git clone https://github.com/andrejvysny/spendly.git
   cd spendly
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install
   ```

3. **Install Node.js dependencies**:
   ```bash
   npm install
   ```

4. **Configure environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure database** (edit `.env`):
   ```env
   # For SQLite (default, easiest for development)
   DB_CONNECTION=sqlite
   DB_DATABASE=/absolute/path/to/database.sqlite
   
   # For MySQL
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=spendly
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

6. **Run database migrations**:
   ```bash
   php artisan migrate
   ```

7. **Seed the database** (optional):
   ```bash
   php artisan db:seed
   ```

8. **Build frontend assets**:
   ```bash
   npm run build
   ```

9. **Start development servers**:
   ```bash
   # Terminal 1: Laravel development server
   php artisan serve
   
   # Terminal 2: Vite development server (for hot reloading)
   npm run dev
   ```

Visit `http://localhost:8000` to access Spendly.

## Production Deployment

### Docker Production Setup

1. **Build the image**:
   ```bash
   docker build -t spendly:latest -f .docker/Dockerfile .
   ```

2. **Run with proper configuration**:
   ```bash
   docker run -d \
     --name spendly \
     -p 80:80 \
     -e APP_ENV=production \
     -e APP_DEBUG=false \
     -e APP_KEY=your-generated-key \
     -e DB_CONNECTION=sqlite \
     -v spendly_data:/var/www/html/storage \
     spendly:latest
   ```

### Manual Production Setup

1. **Server requirements**:
   - Ubuntu 20.04+ or CentOS 8+
   - Nginx or Apache
   - PHP 8.3+ with FPM
   - Supervisor (for queue workers)
   - SSL certificate (Let's Encrypt recommended)

2. **Install dependencies**:
   ```bash
   # Ubuntu/Debian
   sudo apt update
   sudo apt install php8.3-fpm php8.3-mysql php8.3-xml php8.3-curl php8.3-mbstring php8.3-zip nginx supervisor
   
   # Install Composer
   curl -sS https://getcomposer.org/installer | php
   sudo mv composer.phar /usr/local/bin/composer
   
   # Install Node.js
   curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
   sudo apt-get install -y nodejs
   ```

3. **Deploy application**:
   ```bash
   cd /var/www
   git clone https://github.com/andrejvysny/spendly.git
   cd spendly
   composer install --no-dev --optimize-autoloader
   npm ci --production
   npm run build
   ```

4. **Configure environment**:
   ```bash
   cp .env.example .env
   # Edit .env with production values
   php artisan key:generate
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. **Set permissions**:
   ```bash
   sudo chown -R www-data:www-data /var/www/spendly
   sudo chmod -R 755 /var/www/spendly
   sudo chmod -R 775 /var/www/spendly/storage
   sudo chmod -R 775 /var/www/spendly/bootstrap/cache
   ```

6. **Configure Nginx**:
   ```nginx
   server {
       listen 80;
       server_name your-domain.com;
       root /var/www/spendly/public;
       index index.php;
   
       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }
   
       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
           fastcgi_index index.php;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           include fastcgi_params;
       }
   
       location ~ /\.ht {
           deny all;
       }
   }
   ```

7. **Setup SSL with Let's Encrypt**:
   ```bash
   sudo apt install certbot python3-certbot-nginx
   sudo certbot --nginx -d your-domain.com
   ```

## Configuration

### Essential Environment Variables

```env
# Application
APP_NAME="Spendly"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=spendly
DB_USERNAME=spendly_user
DB_PASSWORD=secure_password

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=noreply@your-domain.com

# GoCardless (for bank imports)
GOCARDLESS_ACCESS_TOKEN=your_access_token
GOCARDLESS_SECRET_ID=your_secret_id
GOCARDLESS_SECRET_KEY=your_secret_key
```

### GoCardless Setup

1. **Create GoCardless account**:
   - Visit [GoCardless Bank Account Data](https://bankaccountdata.gocardless.com/)
   - Sign up for an account
   - Complete the verification process

2. **Get API credentials**:
   - Navigate to API Keys section
   - Generate new credentials
   - Add them to your `.env` file

3. **Configure webhook** (optional):
   ```env
   GOCARDLESS_WEBHOOK_SECRET=your_webhook_secret
   ```

### Database Performance Tuning

For production with MySQL:

```sql
-- my.cnf optimizations
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
query_cache_type = 1
query_cache_size = 64M
```

### Queue Configuration

Setup Laravel queues for background processing:

1. **Configure supervisor**:
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

2. **Start supervisor**:
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start spendly-worker:*
   ```

## Troubleshooting

### Common Issues

1. **Permission errors**:
   ```bash
   sudo chown -R www-data:www-data storage bootstrap/cache
   sudo chmod -R 775 storage bootstrap/cache
   ```

2. **Database connection issues**:
   ```bash
   # Test database connection
   php artisan tinker
   > DB::connection()->getPdo();
   ```

3. **Memory limit errors**:
   ```ini
   ; php.ini
   memory_limit = 512M
   max_execution_time = 300
   ```

4. **NPM build failures**:
   ```bash
   # Clear cache and reinstall
   rm -rf node_modules package-lock.json
   npm cache clean --force
   npm install
   ```

### Performance Issues

1. **Enable Laravel optimizations**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```

2. **Database indexing**:
   ```bash
   php artisan migrate --force
   ```

3. **Redis caching** (recommended for production):
   ```env
   CACHE_DRIVER=redis
   SESSION_DRIVER=redis
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379
   ```

### Logs and Debugging

1. **Application logs**:
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Web server logs**:
   ```bash
   # Nginx
   tail -f /var/log/nginx/error.log
   
   # Apache
   tail -f /var/log/apache2/error.log
   ```

3. **Database slow query log**:
   ```sql
   SET GLOBAL slow_query_log = 'ON';
   SET GLOBAL long_query_time = 2;
   ```

### Security Checklist

- [ ] HTTPS enabled with valid SSL certificate
- [ ] Environment variables properly secured
- [ ] Database user has minimal required permissions
- [ ] File permissions correctly set
- [ ] Debug mode disabled in production
- [ ] Error reporting configured properly
- [ ] Firewall configured (only ports 22, 80, 443 open)
- [ ] Regular backups scheduled
- [ ] GoCardless webhook URL uses HTTPS
- [ ] Application keys rotated regularly

### Backup Strategy

1. **Database backup**:
   ```bash
   # MySQL
   mysqldump -u username -p spendly > backup_$(date +%Y%m%d).sql
   
   # SQLite
   cp database/database.sqlite backup_$(date +%Y%m%d).sqlite
   ```

2. **Application files**:
   ```bash
   tar -czf spendly_backup_$(date +%Y%m%d).tar.gz \
     --exclude=node_modules \
     --exclude=vendor \
     --exclude=storage/logs \
     /var/www/spendly
   ```

3. **Automated backups**:
   ```bash
   # Add to crontab
   0 2 * * * /path/to/backup-script.sh
   ```

## Support

- **Documentation**: [docs.spendly.app](https://docs.spendly.app)
- **GitHub Issues**: [Report bugs or request features](https://github.com/andrejvysny/spendly/issues)
- **Security Issues**: security@spendly.app
- **Community**: [Discord Server](https://discord.gg/spendly) 