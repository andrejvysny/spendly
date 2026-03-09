---
title: CSV Import
description: Import transactions from CSV files with smart field mapping.
---

## Overview

The CSV import system follows a layered architecture:

```
Controllers → Services → Mappers → Configuration
```

### Key Components

- **Controllers** — Handle HTTP requests and coordinate operations
- **Services** — Core business logic for data processing
- **Mappers** — Transform data between formats
- **Configuration** — Import settings and column mappings

## Import Wizard

The web-based import wizard walks through five steps:

1. **Upload** — Select a CSV file
2. **Configure** — Set delimiter, headers, content type
3. **Map** — Assign CSV columns to transaction fields (date, amount, description, etc.)
4. **Clean** — Preview and validate data
5. **Confirm/Process** — Import transactions into the database

### Field Auto-Mapping

The `FieldMappingService` automatically detects common CSV column names and maps them to transaction fields using pattern matching.

## CLI Import

Import without the web wizard:

```bash
php artisan import:csv <file> --account=<id|name> \
    [--user=<id>] \
    [--mapping=<json>] \
    [--delimiter=<char>] \
    [--currency=<code>] \
    [--date-format=<format>]
```

**Examples:**

```bash
# Import with account name
php artisan import:csv transactions.csv --account="Main Checking"

# Import with specific mapping
php artisan import:csv bank_export.csv --account=1 --delimiter=";" --currency=EUR

# Import for a specific user
php artisan import:csv data.csv --account=1 --user=3
```

Sample CSV files are available in `sample_data/csv/` (Revolut, SLSP formats).

## Configuration Options

### File Format Settings

- **Delimiter**: comma, semicolon, tab
- **Headers**: whether the first row contains column headers
- **Content type**: CSV or CAMT format

### Column Role Assignment

Map CSV columns to transaction fields:

```json
{
    "roles": {
        "column1": "date",
        "column2": "amount",
        "column3": "description"
    },
    "do_mapping": true
}
```

## Error Handling

The import system tracks all failures:

- **validation_failed** — Missing fields, invalid formats
- **duplicate** — Transaction already exists (SHA256 fingerprinting)
- **processing_error** — Business logic errors (account not found, currency issues)
- **parsing_error** — Malformed CSV, encoding problems

Failed transactions are stored in the `import_failures` table for manual review. See [Import Troubleshooting](/docs/guides/import-troubleshooting/) for details.

## Deduplication

Transactions are deduplicated using SHA256 fingerprinting. The fingerprint is computed from key fields (date, amount, description, account) to prevent importing the same transaction twice.

## Extension Points

- **Custom Mappers** — Implement `MapperInterface` and register in the service container
- **Custom Validators** — Extend the base validator with additional validation rules
- **Custom Transformers** — Implement transformation interface for custom data formats
