# ML Service Setup Guide

## Overview

Spendly ML Service provides intelligent features:

- **Merchant Extraction**: Extract and normalize merchant names from transaction descriptions
- **Categorization**: Auto-categorize transactions
- **Recurring Detection**: Identify subscription/recurring payments
- **Transfer Detection**: Detect cross-account transfers

## Deployment Modes

### Mode 1: Simple (SQLite, no ML)

Best for: Single-user, self-hosted, minimal resources

```bash
docker compose up -d
```

Uses SQLite database, no ML features.

### Mode 2: Full Stack (PostgreSQL + ML)

Best for: Multi-user, ML features, production

```bash
docker compose -f compose.yml -f compose.ml.yml up -d
```

Includes:

- PostgreSQL database
- Redis for queues
- ML Service (FastAPI)
- Celery workers for async processing

### Mode 3: ML Development

For developing/testing ML features only:

```bash
cd ml/
docker compose up -d
```

## Environment Variables

### Laravel (.env)

```env
# SQLite (simple mode)
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

# PostgreSQL (ML mode)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=spendly
DB_USERNAME=spendly
DB_PASSWORD=spendly

# ML Service
ML_SERVICE_URL=http://ml-service:8001
ML_ENABLED=true

# Queue (required for ML)
QUEUE_CONNECTION=redis
REDIS_URL=redis://redis:6379
```

### ML Service (ml/.env)

```env
DATABASE_URL=postgresql://spendly:spendly@postgres:5432/spendly
REDIS_URL=redis://redis:6379/0
ML_WORKERS=2
LOG_LEVEL=INFO
```

## Database Migration

### From SQLite to PostgreSQL

1. Export SQLite data:

```bash
sqlite3 database/database.sqlite .dump > backup.sql
```

2. Start PostgreSQL stack:

```bash
docker compose -f compose.yml -f compose.ml.yml up -d postgres
```

3. Import data:

```bash
docker compose exec postgres psql -U spendly -d spendly < backup.sql
```

4. Run Laravel migrations:

```bash
docker compose run cli php artisan migrate
```

## API Endpoints

### Health

- `GET /` - Health check
- `GET /ready` - Readiness check

### Merchants

- `POST /merchants/extract` - Extract merchants from transactions
- `POST /merchants/extract-single` - Extract single merchant

### Categorization

- `POST /categorization/suggest` - Suggest categories for transactions
- `POST /categorization/suggest-single` - Suggest category for single transaction

### Recurring

- `POST /recurring/detect` - Detect recurring patterns

### Transfers

- `POST /transfers/detect` - Detect transfer pairs

## Architecture

```
┌─────────────┐     HTTP      ┌──────────────┐
│   Laravel   │──────────────▶│  ML Service  │
│    (PHP)    │◀──────────────│   (Python)   │
└──────┬──────┘               └──────┬───────┘
       │                             │
       │        ┌──────────┐         │
       └───────▶│ Postgres │◀────────┘
              └─────┬──────┘
                    │
              ┌─────┴──────┐
              │   Redis    │
              └────────────┘
```

## Development

### Running ML Service Locally

```bash
cd ml/
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

### Running Celery Worker

```bash
cd ml/
celery -A app.celery_app worker --loglevel=info
```

### Testing API

```bash
curl -X POST http://localhost:8001/merchants/extract \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "transactions": [
      {"id": 1, "description": "NETFLIX.COM", "counterparty_name": "NETFLIX INTERNATIONAL B.V"}
    ]
  }'
```

## Troubleshooting

### ML Service can't connect to database

- Check `DATABASE_URL` format
- Ensure PostgreSQL is healthy: `docker compose ps postgres`

### Celery tasks not processing

- Check Redis connection: `docker compose logs redis`
- Verify worker is running: `docker compose ps celery-worker`

### Category suggestions not working

- ML service needs user category data in PostgreSQL
- Run import/migration first to populate categories
