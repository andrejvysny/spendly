# ML Service Setup Guide

## Overview

Spendly ML Service provides intelligent features:

- **Categorization**: auto-suggest categories (per-user TF-IDF + SGDClassifier model, keyword fallback)
- **Counterparty detection**: match/normalize merchant names from transaction descriptions
- **Recurring Detection**: identify subscription/recurring payments
- **Transfer Detection**: detect cross-account transfers

Suggestions are stored in `Transaction.metadata.ml` and never auto-applied; users accept them in the admin labeling UI.

## Deployment Modes

### Mode 1: Simple (SQLite, no ML)

Best for: single-user, self-hosted, minimal resources

```bash
docker compose up -d
```

Uses SQLite database, no ML features.

### Mode 2: Full Stack (PostgreSQL + ML)

Best for: multi-user, ML features, production

```bash
# Requires ML_SERVICE_TOKEN in .env (shared secret, any long random string)
docker compose -f compose.yml -f compose.ml.yml up -d
```

Includes:

- PostgreSQL database
- Redis (Laravel queue driver)
- Laravel queue worker (processes `RetrainMlModelJob` and other queued jobs)
- ML Service (FastAPI, reachable only on the compose network)

### Mode 3: ML Development

For developing/testing the ML service only (SQLite from repo root, mounted read-only):

```bash
cd ml/
docker compose up -d   # exposes port 8001 on the host, token defaults to "dev-token"
```

## Authentication

All ML endpoints except health checks require a bearer token. Generate one secret and set it on both sides:

```env
# Laravel .env AND ml service environment
ML_SERVICE_TOKEN=<long random string>
```

- Laravel sends `Authorization: Bearer <token>` on every call (`MlService`).
- The ML service fails closed: without a configured token, protected endpoints return 503; with a wrong token, 401.
- Health endpoints (`/`, `/ready`, `/api/v1/health`) stay public for Docker healthchecks and `MlService::isAvailable()`.

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
ML_ENABLED=true
ML_SERVICE_URL=http://ml-service:8001
ML_SERVICE_TOKEN=<shared secret>
ML_TIMEOUT=30

# Queue (required for ML retraining jobs)
QUEUE_CONNECTION=redis
REDIS_URL=redis://redis:6379
```

`QUEUE_CONNECTION=sync` also works for small installs without a worker container (jobs run inline).

### ML Service (ml/.env — see ml/.env.example)

```env
DATABASE_URL=postgresql://spendly:spendly@postgres:5432/spendly
ML_SERVICE_TOKEN=<shared secret>
ML_DATA_DIR=/app/data
LOG_LEVEL=INFO
```

Trained models persist under `ML_DATA_DIR` — the `compose.ml.yml` stack mounts the `ml_data` volume there.

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

All non-health endpoints require the bearer token.

### Health (public)

- `GET /` — health check
- `GET /ready` — readiness check
- `GET /api/v1/health` — health used by Laravel and Docker healthchecks

### v1 (consumed by Laravel `MlService`)

- `POST /api/v1/categorize` — category predictions for a user's transactions
- `POST /api/v1/detect-counterparties` — counterparty matching / merchant extraction
- `POST /api/v1/detect-recurring` — recurring patterns (`months_lookback`: 1–36, default 12)
- `POST /api/v1/detect-transfers` — cross-account transfer pair candidates
- `POST /api/v1/train/categorizer` — train the per-user categorization model
- `POST /api/v1/train/counterparty-detector` — stub (reserved)
- `POST /api/v1/train/transfer-detector` — stub (reserved)
- `POST /api/v1/discover-counterparties` — stub (reserved)

## Architecture

```
┌─────────────┐  HTTP + bearer  ┌──────────────┐
│   Laravel   │────────────────▶│  ML Service  │
│    (PHP)    │◀────────────────│   (Python)   │
└──────┬──────┘                 └──────┬───────┘
       │                               │ (read-only)
       │        ┌──────────┐           │
       └───────▶│ Postgres │◀──────────┘
                └─────┬────┘
                      │
                ┌─────┴────┐     ┌──────────────┐
                │  Redis   │◀────│ queue worker │
                └──────────┘     │  (Laravel)   │
                                 └──────────────┘
```

The ML service reads the database directly for feature extraction; all writes go through Laravel. Retraining is triggered by the `TrackManualCategorization` listener (threshold in `MlPersonalizationSetting`) and runs as a queued Laravel job.

## Development

### Running ML Service locally

```bash
cd ml/
python -m venv venv
source venv/bin/activate
pip install -r requirements-dev.txt
ML_SERVICE_TOKEN=dev-token uvicorn app.main:app --reload --port 8001
```

### Tests and static analysis

```bash
cd ml/
python -m pytest
python -m ruff check app tests
python -m mypy app
```

### Testing the API

```bash
curl -X POST http://localhost:8001/api/v1/categorize \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer dev-token" \
  -d '{"user_id": 1, "limit": 10}'
```

## Troubleshooting

### ML Service can't connect to database

- Check `DATABASE_URL` format
- Ensure PostgreSQL is healthy: `docker compose ps postgres`

### 503 "ML_SERVICE_TOKEN is not configured"

- Set the same `ML_SERVICE_TOKEN` on both the ML service and Laravel

### Retraining jobs not processing

- Check the queue worker: `docker compose ps queue-worker` / `docker compose logs queue-worker`
- Or set `QUEUE_CONNECTION=sync` for inline processing

### Category suggestions not working

- The per-user model needs ≥50 labeled transactions; train via `POST /api/v1/train/categorizer` or let auto-retrain trigger
- Check `php artisan ml:train --user=<id>` and `php artisan ml:predict`
