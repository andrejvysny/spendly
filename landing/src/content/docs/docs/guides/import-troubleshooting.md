---
title: Import Troubleshooting
description: Managing failed and skipped transactions during CSV imports.
---

## Overview

Spendly tracks every failed or skipped transaction during CSV imports, enabling manual review and resolution.

## Import Status Types

| Status                         | Meaning                                |
| ------------------------------ | -------------------------------------- |
| `completed`                    | All transactions imported successfully |
| `completed_skipped_duplicates` | Success with minor duplicate skips     |
| `partially_failed`             | Some failures or >10% skipped          |
| `failed`                       | All transactions failed                |

## Error Types

| Error Type          | When Used                       | Examples                           |
| ------------------- | ------------------------------- | ---------------------------------- |
| `validation_failed` | Data validation errors          | Missing fields, invalid formats    |
| `duplicate`         | Duplicate transaction detection | Same transaction already exists    |
| `processing_error`  | Business logic errors           | Account not found, currency issues |
| `parsing_error`     | CSV parsing issues              | Malformed CSV, encoding problems   |

## Reviewing Failures

### Web Interface

Navigate to **Imports > [your import] > Review Failures** to access the failure review page:

- **Statistics dashboard** — Total failures, pending review, by error type
- **Search and filter** — By error type, status, or text search
- **Expandable cards** — Raw CSV data, error details, review notes
- **Bulk operations** — Mark multiple failures as reviewed/ignored
- **Create transaction** — Pre-filled form from failed CSV row data

### API Endpoints

```
GET    /api/imports/{import}/failures          # List with filters
GET    /api/imports/{import}/failures/stats     # Statistics
GET    /api/imports/{import}/failures/{id}      # Single failure detail
PATCH  /api/imports/{import}/failures/{id}/reviewed  # Mark reviewed
PATCH  /api/imports/{import}/failures/{id}/resolved  # Mark resolved
PATCH  /api/imports/{import}/failures/{id}/ignored   # Mark ignored
PATCH  /api/imports/{import}/failures/bulk      # Bulk update
GET    /api/imports/{import}/failures/export    # CSV export
```

### Status Workflow

| Status     | Badge | Action                                 |
| ---------- | ----- | -------------------------------------- |
| `pending`  | Gray  | Create transaction or mark as reviewed |
| `reviewed` | Blue  | Acknowledged by user                   |
| `resolved` | Green | Issue fixed (transaction created)      |
| `ignored`  | Gray  | Intentionally skipped                  |

## Creating Transactions from Failures

The failure review page includes a "Create Transaction" action that:

1. Pre-fills the form with data from the failed CSV row
2. Supports multiple header formats (e.g., `amount`/`betrag`/`value`)
3. Validates with Zod schema
4. Automatically marks the failure as resolved after successful creation

## Bulk Operations

```json
PATCH /api/imports/{import}/failures/bulk
{
  "failure_ids": [1, 2, 3],
  "action": "reviewed|resolved|ignored",
  "notes": "Optional bulk notes"
}
```

## Performance Notes

- Failures are processed in batches of 100
- Bulk insert operations for database efficiency
- CSV export uses chunked processing (100 records)
- Strategic indexes on `(import_id, error_type)`, `(import_id, status)`, and `(status, created_at)`
