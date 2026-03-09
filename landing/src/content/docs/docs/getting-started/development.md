---
title: Development Setup
description: Set up a local development environment for Spendly.
---

## Prerequisites

- PHP 8.3 or higher
- Node.js 20 or higher
- Composer
- Docker (recommended)

## Using Docker (Recommended)

```bash
cp .env.example .env
docker compose up -d
docker compose exec cli composer install
docker compose exec cli php artisan key:generate
docker compose exec cli php artisan migrate --seed
docker compose exec node npm install
docker compose exec node npm run dev
```

## Local Setup (without Docker)

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
npm install
npm run dev &
php artisan serve &
```

## Running Tests

```bash
# Backend tests
./vendor/bin/phpunit

# Frontend tests
npm test
```

## Development Commands

```bash
# Concurrent dev servers (artisan serve + queue + logs + vite)
composer dev

# Static analysis (level 9)
./vendor/bin/phpstan analyse

# Code formatting
./vendor/bin/pint

# Reset DB with demo data
php artisan migrate:fresh --seed

# TypeScript type check
npm run types

# Lint + auto-fix
npm run lint

# Prettier check
npm run format:check
```
