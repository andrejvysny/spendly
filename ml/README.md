# ML Service for Spendly

FastAPI microservice providing ML predictions for the Laravel app. Synchronous HTTP only — no Celery/Redis; async work (retraining jobs) is queued on the Laravel side.

## Modules

- **model_training**: per-user TF-IDF + SGDClassifier categorizer (train/predict/persist)
- **categorization**: category suggestion (user model → global model → keyword fallback)
- **merchant_extraction**: extract and normalize merchant names from bank descriptions
- **recurring_detection**: identify recurring/subscription payments (heuristic)
- **transfer_detection**: detect cross-account transfer pairs (heuristic)
- **personalization**: per-user merchant→category frequency vectors

## Database access

The service reads Spendly's database directly (SQLite file or PostgreSQL). Writes always go through Laravel; predictions are returned over HTTP and stored by Laravel in transaction metadata.

## API

Laravel consumes `/api/v1/*` (see `app/api/v1.py`). All endpoints except health require a bearer token:

```
Authorization: Bearer <ML_SERVICE_TOKEN>
```

- `GET /`, `GET /ready`, `GET /api/v1/health` — health (public)
- `POST /api/v1/categorize` — category predictions
- `POST /api/v1/detect-counterparties` — counterparty matching
- `POST /api/v1/detect-recurring` — recurring patterns (`months_lookback` 1–36)
- `POST /api/v1/detect-transfers` — transfer pair candidates
- `POST /api/v1/train/categorizer` — train the per-user model
- `POST /api/v1/train/counterparty-detector`, `POST /api/v1/train/transfer-detector`, `POST /api/v1/discover-counterparties` — stubs, reserved

## Quick start

```bash
# Standalone dev harness (SQLite from repo root, mounted read-only)
cd ml/
docker compose up -d

# Full stack (root of repo): app + postgres + redis + queue worker + ml-service
docker compose -f compose.yml -f compose.ml.yml up -d
```

## Environment variables

See `.env.example`:

- `DATABASE_URL` — SQLite (`sqlite:////app/db/database.sqlite`) or PostgreSQL URL
- `ML_SERVICE_TOKEN` — shared secret; must match Laravel's `ML_SERVICE_TOKEN`. Unset ⇒ protected endpoints return 503.
- `ML_DATA_DIR` — root for model artifacts (`models/`) and vectors (`vectors/`); mount a volume here to persist trained models
- `LOG_LEVEL`

## Development

```bash
pip install -r requirements-dev.txt
python -m pytest          # tests (tests/, schema fixture mirrors real migrations)
python -m ruff check app tests
python -m mypy app
```
