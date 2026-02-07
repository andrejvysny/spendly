---
name: E2E DB wipe GoCardless CSV
overview: Execute a full database wipe (migrate:fresh without seed), create one user manually, import all GoCardless fixture accounts from sample_data using the CLI with mock data, then run CSV import for each account using sample_data CSVs. Each stage is followed by explicit manual validation via SQLite MCP queries and success criteria. Execution is report-only: no fixes or code changes; only generate a report of actual vs expected.
todos: []
isProject: false
---

# E2E: DB wipe, one user, GoCardless mock import, CSV import

## Execution mode: report only (no fixes)

- **Do not apply any fixes or code changes.** If a command fails or validation does not match expectations, do not fix the environment, code, or data.
- **Only generate a report.** For each stage, record: what was run, what was expected, what was actually observed (MCP query results, command output), and whether the stage passed or failed. Document all discrepancies in the report; do not attempt to resolve them.

## Prerequisites

- **Database**: SQLite (default for local). Ensure `.env` has `DB_CONNECTION=sqlite` and `DB_DATABASE` pointing at the project DB (e.g. `database/database.sqlite`). The SQLite MCP server must be configured to use this same database so validation queries see the same data.
- **GoCardless mock**: Set env so the app uses **local fixture files** from sample_data:
  - `GOCARDLESS_USE_MOCK=true` (or rely on default for `APP_ENV=local`)
  - `GOCARDLESS_MOCK_DATA_PATH=sample_data/gocardless_bank_account_data` (relative to project root) or absolute path to that directory
- **No seeding**: We will **not** run `--seed` so there are no seed users or accounts.

## Data reference (for validation)

- **Fixture accounts** (from `*_details*.json` in [sample_data/gocardless_bank_account_data](sample_data/gocardless_bank_account_data)):
  - **Revolut**: `LT683250013083708433`, `LT683250013083708433_USD`, `8851f561-49db-4574-bb34-643d3a6e9a9c` (3 accounts)
  - **SLSP**: `SK6809000000005183172536`, `SK9009000000005124514591`, `SK9509000000005193116781` (3 accounts)
- **CSV files** (map by filename prefix to `gocardless_account_id`):
  - Revolut: [sample_data/csv/Revolut/LT683250013083708433_2025-01-01_2026-02-06.csv](sample_data/csv/Revolut/LT683250013083708433_2025-01-01_2026-02-06.csv), [sample_data/csv/Revolut/LT683250013083708433_2025-01-16_2026-02-06_USD.csv](sample_data/csv/Revolut/LT683250013083708433_2025-01-16_2026-02-06_USD.csv) → accounts `LT683250013083708433` and `LT683250013083708433_USD`
  - SLSP: [sample_data/csv/SLSP/](sample_data/csv/SLSP/) — `SK6809000000005183172536_*.csv`, `SK9009000000005124514591_*.csv`, `SK9509000000005193116781_*.csv` → same-id accounts
- **CLI**: [app/Console/Commands/ImportCsvCommand.php](app/Console/Commands/ImportCsvCommand.php) requires `--account=` (ID or name). Resolve by **numeric ID** after Stage 3 to avoid name collisions. Revolut CSVs: comma delimiter, date format `Y-m-d H:i:s`. SLSP: `--delimiter=";"` and `--date-format=d.m.Y`.

---

## Stage 1: Full database wipe (no seed)

### Commands

```bash
cd /Users/andrejvysny/andrejvysny/spendly
php artisan migrate:fresh
```

Do **not** pass `--seed`.

### Manual validation (SQLite MCP)

1. **List tables**
  - Use MCP `list_tables`.  
  - Expect: `migrations`, `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `import_mappings`, `import_failures`, `accounts`, `categories`, `merchants`, `imports`, `tags`, `tag_transaction`, `transaction_fingerprints`, `rule_groups`, `rules`, `condition_groups`, `rule_conditions`, `rule_actions`, `rule_execution_logs`, `import_row_edits`, `recurring_groups`, `recurring_detection_settings`, `dismissed_recurring_suggestions`, `gocardless_sync_failures`, `transactions`, plus `sqlite_sequence`.
2. **Confirm app tables are empty (no seed)**
  Run these **read_query** SELECTs and verify **0** rows (or only `migrations` has rows):
  - `SELECT COUNT(*) AS c FROM users;`
  - `SELECT COUNT(*) AS c FROM accounts;`
  - `SELECT COUNT(*) AS c FROM transactions;`
  - `SELECT COUNT(*) AS c FROM imports;`
  - `SELECT COUNT(*) AS c FROM categories;`
  - `SELECT COUNT(*) AS c FROM merchants;`
3. **Migrations**
  - `SELECT * FROM migrations ORDER BY id;`  
  - Expect one row per migration; no other data.

### Success criteria

- All migrations applied.
- Zero rows in `users`, `accounts`, `transactions`, `imports`, `categories`, `merchants`. Proceed only when this holds.

---

## Stage 2: Create one user (manual)

### Commands

Create a single user via **Tinker** (no seed):

```bash
php artisan tinker
```

In tinker:

```php
\App\Models\User::create([
    'name' => 'Test User',
    'email' => 'test@example.com',
    'password' => bcrypt('password'),
]);
exit
```

(Or use a one-off artisan command / direct SQL if you prefer; the plan assumes one user with known email for `--user=` later.)

### Manual validation (SQLite MCP)

1. **User count and row**
  - `SELECT id, name, email, created_at FROM users;`  
  - Expect **exactly 1 row**: `id=1`, `name='Test User'`, `email='test@example.com'`.
2. **No other user-dependent data**
  - `SELECT COUNT(*) AS c FROM accounts;`  → 0  
  - `SELECT COUNT(*) AS c FROM transactions;`  → 0  
  - `SELECT COUNT(*) AS c FROM imports;`  → 0

### Success criteria

- Exactly one user; no accounts, transactions, or imports. Proceed to Stage 3.

---

## Stage 3: GoCardless import (all fixture accounts from sample_data)

### Environment

Ensure mock uses sample_data fixtures:

- `GOCARDLESS_USE_MOCK=true` (or `APP_ENV=local` so default is true)
- `GOCARDLESS_MOCK_DATA_PATH=sample_data/gocardless_bank_account_data`

(If the app resolves paths from project root, relative path is fine; otherwise use absolute path.)

### Commands

Run **connect** for each institution so all linked fixture accounts are created (no manual `import-account` per ID needed):

```bash
php artisan gocardless:connect --institution=Revolut --user=1
php artisan gocardless:connect --institution=SLSP --user=1
```

Expected output: “Imported: …” for each fixture account (Revolut 3, SLSP 3). Any “Skipped (already exists)” is acceptable on re-run.

### Manual validation (SQLite MCP)

1. **Account count and list**
  - `SELECT id, user_id, name, iban, currency, gocardless_account_id, is_gocardless_synced FROM accounts ORDER BY id;`  
  - Expect **6 rows**, all `user_id=1`, `is_gocardless_synced=1`, and `gocardless_account_id` in:
    - Revolut: `LT683250013083708433`, `LT683250013083708433_USD`, `8851f561-49db-4574-bb34-643d3a6e9a9c`
    - SLSP: `SK6809000000005183172536`, `SK9009000000005124514591`, `SK9509000000005193116781`
2. **Revolut vs SLSP**
  - `SELECT id, gocardless_account_id, LEFT(name, 40) AS name, iban FROM accounts WHERE gocardless_account_id IN ('LT683250013083708433','LT683250013083708433_USD','8851f561-49db-4574-bb34-643d3a6e9a9c');`  
  - Expect 3 rows (Revolut).  
  - Same for SLSP IDs: 3 rows.
3. **No transactions/imports from this stage**
  - `SELECT COUNT(*) AS c FROM transactions;`  → 0  
  - `SELECT COUNT(*) AS c FROM imports;`  → 0
4. **Build CSV → account mapping for Stage 4**
  - `SELECT id, gocardless_account_id FROM accounts WHERE user_id = 1 ORDER BY id;`  
  - Record mapping: e.g. CSV prefix `LT683250013083708433` → that row’s `id`; `LT683250013083708433_USD` → id for `_USD` account; same for SLSP IBANs. Use these **numeric IDs** as `--account=<id>` in Stage 4.

### Success criteria

- 6 accounts, all with correct `gocardless_account_id` and `user_id=1`. Zero transactions and imports. Mapping CSV filename prefix → `accounts.id` ready for Stage 4.

---

## Stage 4: CSV import (previous year → all existing accounts, as-is)

Import sample CSVs **as-is** (no date filter) into the **existing** GoCardless-imported accounts only. One CSV per account; use `--account=<id>` from Stage 3 mapping.

### Commands (template)

Use the **numeric** `accounts.id` from Stage 3 validation. Revolut: comma delimiter, `--date-format="Y-m-d H:i:s"`. SLSP: `--delimiter=";"` and `--date-format=d.m.Y`. Default `--user=1` or explicit `--user=1`.

**Revolut (2 files)** — replace `{ID_EUR}` and `{ID_USD}` with actual ids for `LT683250013083708433` and `LT683250013083708433_USD`:

```bash
php artisan import:csv sample_data/csv/Revolut/LT683250013083708433_2025-01-01_2026-02-06.csv --account={ID_EUR} --user=1 --date-format="Y-m-d H:i:s"
php artisan import:csv sample_data/csv/Revolut/LT683250013083708433_2025-01-16_2026-02-06_USD.csv --account={ID_USD} --user=1 --date-format="Y-m-d H:i:s"
```

**SLSP (3 files)** — replace `{ID_SK68}`, `{ID_SK90}`, `{ID_SK95}` with ids for the three SLSP accounts:

```bash
php artisan import:csv sample_data/csv/SLSP/SK6809000000005183172536_2025-01-01_2026-02-06.csv --account={ID_SK68} --user=1 --delimiter=";" --date-format=d.m.Y
php artisan import:csv sample_data/csv/SLSP/SK9009000000005124514591_2025-01-01_2026-02-06.csv --account={ID_SK90} --user=1 --delimiter=";" --date-format=d.m.Y
php artisan import:csv sample_data/csv/SLSP/SK9509000000005193116781_2025-01-01_2026-02-06.csv --account={ID_SK95} --user=1 --delimiter="," --date-format=d.m.Y
```

(We do **not** import into the third Revolut account `8851f561-...` because there is no matching CSV in sample_data; that’s acceptable per “only existing accounts” and “gocardless_only”.)

### Manual validation (SQLite MCP)

1. **Imports**
  - `SELECT id, user_id, filename, original_filename, status, total_rows, processed_rows, failed_rows, currency FROM imports ORDER BY id;`  
  - Expect **5 rows** (2 Revolut + 3 SLSP). Each `status` should be `completed` (or equivalent). `processed_rows` + `failed_rows` should reflect expected row counts; `failed_rows` ideally 0 or documented.
2. **Transactions per account**
  - `SELECT account_id, COUNT(*) AS cnt FROM transactions GROUP BY account_id ORDER BY account_id;`  
  - Expect one row per imported account (5 accounts). Counts should match the CSV row counts (minus header and any skipped/duplicates).
3. **Transaction dates (previous year / as-is)**
  - `SELECT MIN(booked_at) AS min_date, MAX(booked_at) AS max_date, COUNT(*) AS c FROM transactions;`  
  - Confirm dates span the expected range (e.g. 2025–2026 from sample_data) and count is plausible.
4. **Failures**
  - `SELECT * FROM import_failures ORDER BY id LIMIT 20;`  
  - Prefer 0 rows; if any, document in the report only (no fixes).
5. **Integrity**
  - `SELECT account_id, COUNT(*) FROM transactions GROUP BY account_id;`  
  - All `account_id` values must exist in `accounts` and belong to `user_id=1`.

### Success criteria

- 5 imports completed; transactions only in the 5 accounts we imported into; no orphaned transactions; import_failures empty or explained. Proceed only after this check.

---

## Iteration and reporting

- **Iterative**: Run stages in order. After each stage, run the corresponding MCP validation and record results in the report. If any validation fails, do **not** fix or re-run; record the failure and actual vs expected in the report, then either continue to the next stage (to gather full report) or stop, as appropriate.
- **Report contents**: For each stage include: commands run (and their stdout/stderr), each validation query and its result, expected vs actual, and pass/fail. Summarise at the end: stages passed, stages failed, and all discrepancies.
- **Full wipe again**: To redo from scratch: `php artisan migrate:fresh` (no seed), then repeat from Stage 2. (Only when re-executing the flow; during report-only execution, do not change state beyond what the plan commands specify.)

---

## Summary checklist


| Stage | Action                                                      | Key validation                                                                   |
| ----- | ----------------------------------------------------------- | -------------------------------------------------------------------------------- |
| 1     | `migrate:fresh` (no seed)                                   | Tables empty; migrations present                                                 |
| 2     | Create one user (tinker)                                    | 1 user; 0 accounts/transactions/imports                                          |
| 3     | `gocardless:connect` Revolut + SLSP with mock path          | 6 accounts; correct gocardless_account_ids; 0 transactions/imports; build id map |
| 4     | `import:csv` for 5 CSVs with correct --account= and options | 5 imports completed; transactions in 5 accounts; no unexpected failures          |


No code changes are required for this flow: use existing CLI and env; validation is via SQLite MCP and the criteria above.

---

## Report (output of execution)

Generate a single report with:

1. **Per stage**: Stage number; commands run (exact invocations); command exit code and relevant stdout/stderr; each validation query and its result (e.g. table or row count); expected vs actual; **Pass** or **Fail** for that stage.
2. **Summary**: Total stages passed/failed; list of all discrepancies (expected value vs actual value, and where).
3. **No fixes**: Do not include any remediation steps or code changes—report only.