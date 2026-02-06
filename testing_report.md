# E2E Testing Report: DB Wipe → GoCardless → CSV Import

**Date**: 2026-02-06
**Execution mode**: Report only (no fixes)
**Environment**: Docker Compose (`app` service), SQLite, APP_ENV=local

---

## Stage 1: Full Database Wipe (migrate:fresh)

### Command

```bash
docker compose exec app php artisan migrate:fresh
```

### Output

All tables dropped, migration table created, 32 migrations applied successfully.

### Validation

| Query | Expected | Actual | Status |
|---|---|---|---|
| `list_tables` | 31 tables (including `sqlite_sequence`) | 31 tables present | PASS |
| `SELECT COUNT(*) FROM users` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM accounts` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM transactions` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM imports` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM categories` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM merchants` | 0 | 0 | PASS |
| `SELECT * FROM migrations ORDER BY id` | 32 rows, batch=1 | 32 rows, batch=1 | PASS |

### Result: PASS

---

## Stage 2: Create One Test User

### Command

```bash
docker compose exec app php artisan tinker --execute="\App\Models\User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => bcrypt('password')]); echo 'USER_CREATED';"
```

### Output

```
USER_CREATED
```

### Validation

| Query | Expected | Actual | Status |
|---|---|---|---|
| `SELECT id, name, email, created_at FROM users` | 1 row: id=1, name=Test User, email=test@example.com | id=1, name=Test User, email=test@example.com, created_at=2026-02-06 22:20:51 | PASS |
| `SELECT COUNT(*) FROM accounts` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM transactions` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM imports` | 0 | 0 | PASS |

### Result: PASS

---

## Stage 3: GoCardless Mock Import

### Environment Check

| Variable | Expected | Actual |
|---|---|---|
| `APP_ENV` | local | local |
| `GOCARDLESS_USE_MOCK` | true (or absent, defaults true for local) | Not set (defaults to true via APP_ENV=local) |
| `GOCARDLESS_MOCK_DATA_PATH` | `sample_data/gocardless_bank_account_data` | **NOT SET** |

Default mock data path resolves to `base_path('gocardless_bank_account_data')` which does **not exist**. Fixture data is at `sample_data/gocardless_bank_account_data/`.

### Commands & Output

**Revolut:**

```bash
docker compose exec app php artisan gocardless:connect --institution=Revolut --user=1
```

```
Requisition created: mock_requisition_6986694fa95c1
  Imported: mock_account_1
  Imported: mock_account_2
Done. Imported: 2, skipped: 0.
```

**SLSP:**

```bash
docker compose exec app php artisan gocardless:connect --institution=SLSP --user=1
```

```
Requisition created: mock_requisition_69866952e6e48
  Skipped (already exists): mock_account_1
  Skipped (already exists): mock_account_2
Done. Imported: 0, skipped: 2.
```

### Validation

| Query | Expected | Actual | Status |
|---|---|---|---|
| `SELECT ... FROM accounts ORDER BY id` | 6 rows with fixture-based gocardless_account_ids | **2 rows** with generic mock IDs | **FAIL** |
| Account gocardless_account_ids | `LT683250013083708433`, `LT683250013083708433_USD`, `8851f561-...`, `SK680900...`, `SK900900...`, `SK950900...` | `mock_account_1`, `mock_account_2` | **FAIL** |
| Account IBANs | Fixture IBANs from details JSON | `LT11MOCK000000000001`, `SK11MOCK000000000002` | **FAIL** |
| `SELECT COUNT(*) FROM transactions` | 0 | 0 | PASS |
| `SELECT COUNT(*) FROM imports` | 0 | 0 | PASS |

**Accounts created:**

| id | user_id | name | iban | currency | gocardless_account_id | is_gocardless_synced |
|---|---|---|---|---|---|---|
| 1 | 1 | Mock Revolut Account | LT11MOCK000000000001 | EUR | mock_account_1 | 1 |
| 2 | 1 | Mock SLSP Account | SK11MOCK000000000002 | EUR | mock_account_2 | 1 |

### Discrepancies

1. **Only 2 accounts created** instead of expected 6.
2. **Generic mock IDs** (`mock_account_1`, `mock_account_2`) instead of fixture-based IDs.
3. **SLSP connect imported 0 accounts** — both skipped as duplicates of the generic mock IDs already created by Revolut connect.
4. **Root cause**: `GOCARDLESS_MOCK_DATA_PATH` env var not set. Default path `gocardless_bank_account_data/` doesn't exist at project root. Mock client fell back to hardcoded generic account data instead of reading fixture files from `sample_data/gocardless_bank_account_data/`.

### Result: FAIL

---

## Stage 4: CSV Import

Due to Stage 3 failure, only 2 accounts exist (id=1, id=2) instead of 6. CSV imports were executed against available accounts. The 5th CSV (SLSP SK95) was attempted but failed at auto-mapping.

### Commands & Output

**Import 1 — Revolut EUR → account 1:**

```bash
docker compose exec app php artisan import:csv sample_data/csv/Revolut/LT683250013083708433_2025-01-01_2026-02-06.csv --account=1 --user=1 --date-format="Y-m-d H:i:s"
```

```
Import completed.
  Processed: 1394
  Failed: 1
  Skipped: 0
  Total rows: 1395
```

Status: `partially_failed`

**Import 2 — Revolut USD → account 2:**

```bash
docker compose exec app php artisan import:csv sample_data/csv/Revolut/LT683250013083708433_2025-01-16_2026-02-06_USD.csv --account=2 --user=1 --date-format="Y-m-d H:i:s"
```

```
Import completed.
  Processed: 91
  Failed: 0
  Skipped: 0
  Total rows: 91
```

Status: `completed`

**Import 3 — SLSP SK68 → account 1:**

```bash
docker compose exec app php artisan import:csv "sample_data/csv/SLSP/SK6809000000005183172536_2025-01-01_2026-02-06.csv" --account=1 --user=1 --delimiter=";" --date-format=d.m.Y
```

```
Import completed.
  Processed: 161
  Failed: 514
  Skipped: 0
  Total rows: 675
```

Status: `partially_failed`

**Import 4 — SLSP SK90 → account 1:**

```bash
docker compose exec app php artisan import:csv "sample_data/csv/SLSP/SK9009000000005124514591_2025-01-01_2026-02-06.csv" --account=1 --user=1 --delimiter=";" --date-format=d.m.Y
```

```
Import completed.
  Processed: 213
  Failed: 258
  Skipped: 0
  Total rows: 471
```

Status: `partially_failed`

**Import 5 — SLSP SK95 → account 2:**

```bash
docker compose exec app php artisan import:csv "sample_data/csv/SLSP/SK9509000000005193116781_2025-01-01_2026-02-06.csv" --account=2 --user=1 --delimiter=";" --date-format=d.m.Y
```

```
Auto-detected mapping is invalid: Missing required field mapping: booked_date; Missing required field mapping: partner
```

Status: **NOT CREATED** — mapping auto-detection failed. File encoding is UTF-16LE; after conversion to UTF-8, only 1 header column was detected (delimiter mismatch post-conversion).

### Validation

**Imports table:**

| id | original_filename | status | total_rows | processed_rows | failed_rows | currency |
|---|---|---|---|---|---|---|
| 1 | LT683250013083708433_2025-01-01_2026-02-06.csv | partially_failed | 1396 | 1394 | 1 | EUR |
| 2 | LT683250013083708433_2025-01-16_2026-02-06_USD.csv | completed | 92 | 91 | 0 | EUR |
| 3 | SK6809000000005183172536_2025-01-01_2026-02-06.csv | partially_failed | 676 | 161 | 514 | EUR |
| 4 | SK9009000000005124514591_2025-01-01_2026-02-06.csv | partially_failed | 472 | 213 | 258 | EUR |

| Query | Expected | Actual | Status |
|---|---|---|---|
| Import count | 5 | **4** (SK95 not created) | **FAIL** |
| All statuses `completed` | Yes | 1 completed, 3 partially_failed | **FAIL** |
| Transactions per account | 5 accounts with transactions | **2 accounts** (1→1768, 2→91) | **FAIL** |
| Transaction date range | 2025–2026 | 2024-12-30 to 2026-02-06 | PASS (plausible) |
| Total transactions | — | 1859 | — |
| Orphaned transactions | 0 | 0 | PASS |
| All transactions belong to user_id=1 | Yes | Yes | PASS |

**Import failures breakdown (773 total):**

| import_id | error | count |
|---|---|---|
| 1 | Validation failed for row 1148 | 1 |
| 3 | Missing required field: booked_date | 512 |
| 3 | Validation failed for row 267 | 1 |
| 3 | Validation failed for row 30 | 1 |
| 4 | Missing required field: booked_date | 255 |
| 4 | Validation failed for row 456 | 1 |
| 4 | Validation failed for row 460 | 1 |
| 4 | Validation failed for row 462 | 1 |

### Discrepancies

1. **Only 4 imports created** (expected 5). SLSP SK95 failed at auto-mapping due to UTF-16LE encoding → delimiter detection failure post-conversion.
2. **SLSP imports had massive failures** — 767 rows failed with "Missing required field: booked_date". Auto-mapping partially failed for SLSP CSVs.
3. **Revolut EUR had 1 failure** — row 1148 validation failed.
4. **Revolut USD import currency** — stored as `EUR` despite being a USD CSV file (no `--currency=USD` flag passed; defaulted to account currency).
5. **Account mismatch** — due to Stage 3 failure, SLSP CSVs were imported into Revolut/SLSP generic mock accounts rather than their correct fixture-based accounts.

### Result: FAIL

---

## Summary

| Stage | Description | Result |
|---|---|---|
| 1 | migrate:fresh (no seed) | **PASS** |
| 2 | Create one test user | **PASS** |
| 3 | GoCardless mock import (Revolut + SLSP) | **FAIL** |
| 4 | CSV import (5 files) | **FAIL** |

**Stages passed**: 2 / 4
**Stages failed**: 2 / 4

### All Discrepancies

| # | Stage | Expected | Actual | Severity |
|---|---|---|---|---|
| 1 | 3 | `GOCARDLESS_MOCK_DATA_PATH` set to `sample_data/gocardless_bank_account_data` | Not set; default path `gocardless_bank_account_data/` doesn't exist | Critical |
| 2 | 3 | 6 accounts with fixture-based IDs | 2 accounts with generic mock IDs | Critical |
| 3 | 3 | SLSP creates 3 separate accounts | SLSP skipped all (duplicate generic mock IDs from Revolut) | Critical |
| 4 | 4 | 5 imports created | 4 imports (SK95 mapping failed) | High |
| 5 | 4 | SK95 CSV auto-mapping succeeds | UTF-16LE encoding → 1 header column detected → mapping invalid | High |
| 6 | 4 | SLSP rows fully processed | 767 rows failed: "Missing required field: booked_date" | High |
| 7 | 4 | All import statuses `completed` | 1 completed, 3 partially_failed | Medium |
| 8 | 4 | Revolut USD import currency = USD | Stored as EUR (no `--currency` flag; uses account default) | Low |
| 9 | 4 | Revolut EUR 0 failures | 1 failure (row 1148 validation) | Low |

### Root Cause Analysis (observations only, no fixes)

1. **Discrepancies 1–3**: The prerequisite env var `GOCARDLESS_MOCK_DATA_PATH` was not configured in `.env`. Without it, the mock client uses hardcoded generic accounts rather than reading fixture JSON files. This cascaded into Stage 4 account mismatches.

2. **Discrepancy 5**: The SLSP SK95 CSV is encoded as UTF-16LE. While the import pipeline detects and converts the encoding to UTF-8, the semicolon delimiter detection appears to fail after conversion, resulting in all content being read as a single column. This prevents auto-mapping from finding required fields.

3. **Discrepancy 6**: The SLSP CSV files have rows where the `booked_date` field mapping is missing or incorrectly auto-detected. The auto-mapper partially works (some rows process) but fails on a majority — suggesting inconsistent row formatting within the SLSP CSVs (possibly rows with different column counts or empty date fields).

4. **Discrepancy 8**: The `import:csv` command defaults currency to the account's currency (EUR) when `--currency` is not explicitly provided. The USD CSV should have been imported with `--currency=USD`.
