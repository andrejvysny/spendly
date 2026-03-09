---
title: Installation
description: Get Spendly running with Docker or a local development setup.
---

## Quick Start (Docker)

The fastest way to get Spendly running:

```bash
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

    ```ini
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

1. **Server requirements**: Ubuntu 20.04+, Nginx or Apache, PHP 8.3+ with FPM, Supervisor, SSL certificate

2. **Install dependencies**:

    ```bash
    sudo apt update
    sudo apt install php8.3-fpm php8.3-mysql php8.3-xml php8.3-curl php8.3-mbstring php8.3-zip nginx supervisor
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
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

4. **Configure**:

    ```bash
    cp .env.example .env
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

For detailed production deployment options, see the [Deployment Guide](/docs/guides/deployment/).

## Configuration

### Essential Environment Variables

```ini
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

1. Visit [GoCardless Bank Account Data](https://bankaccountdata.gocardless.com/) and sign up
2. Complete verification, then navigate to API Keys
3. Generate credentials and add them to `.env`

### Queue Configuration

Setup Laravel queues for background processing:

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

## Troubleshooting

### Common Issues

1. **Permission errors**:

    ```bash
    sudo chown -R www-data:www-data storage bootstrap/cache
    sudo chmod -R 775 storage bootstrap/cache
    ```

2. **Database connection issues**:

    ```bash
    php artisan tinker
    > DB::connection()->getPdo();
    ```

3. **Memory limit errors** — set in `php.ini`:

    ```ini
    memory_limit = 512M
    max_execution_time = 300
    ```

4. **NPM build failures**:
    ```bash
    rm -rf node_modules package-lock.json
    npm cache clean --force
    npm install
    ```
