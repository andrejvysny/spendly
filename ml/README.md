# ML Service for Spendly

## Architecture

FastAPI-based microservice with Celery for async task processing.

### Modules

- **merchant_extraction**: Extract and normalize merchant names from transaction descriptions
- **categorization**: Auto-categorize transactions using ML
- **recurring_detection**: Identify recurring/subscription payments
- **transfer_detection**: Detect cross-account transfers

### Database Access

ML service connects directly to PostgreSQL for read operations. Writes go through Laravel API.

## Quick Start

```bash
# Development
docker compose up ml-service celery-worker postgres

# Production
docker compose -f compose.prod.yml up -d
```

## Environment Variables

- `DATABASE_URL`: PostgreSQL connection string
- `REDIS_URL`: Redis for Celery broker
- `ML_WORKERS`: Number of Celery workers (default: 2)
