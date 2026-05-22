# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Spendly — open-source self-hosted personal finance tracker. Laravel 12 + React 19/TypeScript + Inertia.js. SQLite default. Bank sync via GoCardless. Active development, no v1.0 yet. PHP 8.3+. License: GPLv3.

## Commands

```bash
# Dev servers (concurrent: artisan serve + queue + logs + vite)
composer dev                              # local: runs all 4 services
docker compose run --rm cli composer dev       # Docker equivalent

# Backend (local shortcuts via composer scripts)
composer test                             # all tests (clears config first)
composer phpstan                          # static analysis (level 9)
composer pint                             # code formatting
php artisan test --filter=ClassName       # single test class
php artisan migrate:fresh --seed          # reset DB with demo data

# Backend (Docker — prefix PHP/Composer commands)
# Compose files: compose.yml (base), compose.dev.yml, compose.ml.yml (Python ML), compose.prod.yml
docker compose up -d                                          # SQLite mode, no ML
docker compose -f compose.yml -f compose.ml.yml up -d         # full stack with ML service
docker compose run --rm cli php artisan test
docker compose run --rm cli composer phpstan
docker compose run --rm cli composer pint
./scripts/dev.sh                          # full Docker dev setup
./scripts/test.sh                         # full test suite in container

# Frontend
npm run dev                               # vite dev server (port 5173)
npm run build                             # production build
npm run test                              # jest (watch mode)
npm test -- path/to/file                  # single test file
npm run types                             # tsc --noEmit
npm run lint                              # eslint + auto-fix
npm run format                            # prettier write
npm run format:check                      # prettier check only
```

**After PHP changes**: run phpstan + pint (or tests).
**After TS/React changes**: run types + lint (or tests).
Prefer targeted test runs for speed.

## Architecture

### Backend (app/)

**Pattern**: Controllers → Services → Repositories (with contracts/interfaces).

- `Controllers/` — thin, delegate to services. Inertia pages via `Inertia::render('page/name', [...])` where page name matches `resources/js/pages/` path. Routes split across `routes/web.php` (main app) and `routes/settings.php` (settings/GoCardless).
- `Services/` — business logic. Key subsystems:
    - `GoCardless/` (14 services) — bank sync, token management, mock/production client factories via `BankDataClientInterface`
    - `RuleEngine/` — `RuleEngine`, `ConditionEvaluator`, `ActionExecutor` with enums for conditions/actions/triggers
    - `TransactionImport/` — CSV import pipeline: parse → validate → deduplicate → persist
    - `RecurringDetectionService` — pattern matching for recurring transactions
    - `TransferDetectionService` — rule-based + ML transfer pair detection across accounts
    - `BudgetService` — budget progress tracking, spending aggregation per category/period
    - `ExchangeRateService` — multi-currency support via ECB/Frankfurter rates
    - `MlService` (HTTP client to Python FastAPI) + `MlSuggestionService` (writes ML predictions into `Transaction.metadata.ml`). Per-user opt-in via `MlPersonalizationSetting` model. `TrackManualCategorization` listener counts manual category changes; threshold triggers `RetrainMlModelJob`.
- `Repositories/` — 23 interfaces in `Contracts/Repositories/`, 21 concrete implementations. Concerns: `WithUserScope`, `WithOrdering`, `Paginating`.
- `Models/` — 26 Eloquent models. `BelongsToUser` trait for soft multi-tenancy (all user-facing tables have `user_id`). Transaction fingerprinting (SHA256) for deduplication. Key enums: `AnalyticsPeriod`, `CounterpartyType`, `Currency`.
- `Policies/` — authorization via `$this->authorize()`. Base: `OwnedByUserPolicy`.
- `Http/Middleware/SuperAdminMiddleware` — gates `/admin/*` routes via `users.is_superadmin` boolean. Admin controllers in `Http/Controllers/Admin/`, frontend in `resources/js/pages/admin/`.
- `Providers/` — DI bindings: `RepositoryServiceProvider`, `GoCardlessServiceProvider`, `RuleEngineServiceProvider`.

### Scheduled Tasks (bootstrap/app.php)

- `gocardless:sync-all` — every 4 hours
- `gocardless:retry-failures` — every 30 minutes
- `recurring:detect` — daily
- `exchange-rates:fetch` — daily at 06:00
- Queue pruning (failed jobs/batches) — every 72 hours

### Frontend (resources/js/)

- `pages/` — Inertia page components (dashboard, accounts, transactions, analytics, import, rules, budgets, settings, etc.)
- `components/ui/` — 46+ shadcn/ui components (Radix UI primitives)
- `components/` — domain components (accounts, transactions, rules, charts, Import, budgets)
- `hooks/`, `layouts/`, `types/`, `utils/`, `lib/`
- Path alias: `@/` → `resources/js/`
- Inertia: `Head`, `router`, `usePage` from `@inertiajs/react`; type page props from controller payload
- `cn()` utility + CVA for component variants; icons via `lucide-react`
- Charts: Chart.js + react-chartjs-2

### Key Subsystems

**Import wizard**: upload → configure → map → clean → confirm/process. Controller: `ImportWizardController`. Frontend: `resources/js/pages/import/`. Field auto-mapping via pattern matching in `resources/js/utils/FieldMappingService.ts`. CLI: `php artisan import:csv <file> --account=<id|name> [--user=] [--mapping=] [--delimiter=] [--currency=] [--date-format=]`

**Rule Engine**: Models in `app/Models/RuleEngine/` (Rule, RuleGroup, ConditionGroup, RuleCondition, RuleAction). Enums: `ConditionField`, `ConditionOperator`, `ActionType`, `Trigger`. Events: `TransactionCreated`/`TransactionUpdated` → listener `ProcessTransactionRules`. Rules processed via Laravel Pipeline pattern by priority order, respecting stop conditions. Jobs process rules asynchronously via queues. Audit logging in `rule_execution_logs`.

**GoCardless**: Production vs mock via client factories. Mock enabled by `GOCARDLESS_USE_MOCK=true`. Fixture data in `sample_data/gocardless_bank_account_data/`.

**Multi-currency**: `ExchangeRate` model with ECB rates via Frankfurter API. Transactions store both original and native amounts. `ExchangeRateService` fetches/caches rates; `exchange-rates:fetch` scheduled daily.

**ML Service** (optional): Standalone Python service in `ml/` (FastAPI). Spendly calls via `App\Services\MlService`. Two deployment modes — SQLite-only (no ML) or full stack with `compose.ml.yml`. Per-user toggle via `MlPersonalizationSetting`. Manual categorizations tracked by `TrackManualCategorization` listener; retraining via `RetrainMlModelJob`. Dataset export: `php artisan ml:export-dataset`. See `docs/ML_SERVICE.md`.

**FrankenPHP**: Production runtime uses FrankenPHP worker mode (`public/frankenphp-worker.php`) instead of FPM. Image built via `.docker/Dockerfile` with s6-overlay supervising worker + queue.

### CLI Commands (for testing/automation)

Quick reference (see `AGENTS.md` for full options, examples, and GoCardless CLI table):

```bash
# CSV Import (without web wizard)
php artisan import:csv <file> --account=<id|name> [--user=] [--mapping=] [--delimiter=] [--currency=] [--date-format=]

# GoCardless (mock mode by default in dev)
docker compose run --rm cli php artisan gocardless:institutions --country=sk
docker compose run --rm cli php artisan gocardless:connect --institution=SLSP --user=3
docker compose run --rm cli php artisan gocardless:sync --account=1 --user=3
docker compose run --rm cli php artisan gocardless:sync-all
```

Sample data: `sample_data/csv/` (Revolut, SLSP), `sample_data/gocardless_bank_account_data/`. With seeded DB use `--user=3` for demo user.

### Additional Context

- `docs/ai/` — architecture deep-dives. Consult before significant subsystem changes:
    - `GoCardless_Architecture.md`, `RULE_ENGINE.md`, `CSV_Service_Architecture.md` — core subsystem designs
    - `RECURRING_PAYMENTS.md`, `FULLTEXT_SEARCH.md`, `BUDGETING_FEATURE_REQUEST.md` — feature specs
    - `REACT_TESTING.md`, `LARAVEL_TESTING.md` — testing patterns
    - `FRONTEND_IMPLEMENTATION_SUMMARY.md` — React/Inertia UI overview
- `docs/repository-migration/` — ongoing migration from direct Eloquent to repository pattern (plan, audit, coverage matrix).
- `docs/ML_SERVICE.md` — Python ML engine architecture (categorization, merchant extraction, transfer detection).
- `docs/ML_INTERN.md` — HuggingFace ml-intern setup (separate sub-project under `ml-intern/`).
- `AGENTS.md` (repo root) — AI assistant guidelines, CLI command reference, coding conventions.
- Folder-scoped `AGENTS.md` files: `app/Services/AGENTS.md`, `app/Repositories/AGENTS.md`, `resources/js/pages/AGENTS.md` — read these before editing within those folders.
- `.cursor/rules/` — 9 domain-specific rule files (GoCardless, Rule Engine, Import Wizard, testing, etc.).
- Out-of-scope sub-projects (do not modify when working on the main app): `ml/` (Python ML service, separate Docker), `ml-intern/` (HF agent), `spendly-research/` (analysis on third-party tools).

## Conventions

### PHP/Laravel

- `declare(strict_types=1)` in all PHP files
- PSR-12 coding standards
- Form Requests for validation, keep controllers thin
- Dependency injection over facades
- Conventional commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`

### React/TypeScript

- Functional components with TypeScript interfaces for props
- Prettier: single quotes, tabWidth 4, printWidth 150, plugins: organize-imports + tailwindcss (tailwind functions: `cn`, `clsx`)
- shadcn/ui + Radix UI for components, Tailwind CSS 4 for styling
- React Hook Form + Zod for forms

### Branching

- `main` — production, `develop` — integration
- Feature branches from `develop`: `feature/github-issue-id`, `fix/github-issue-id`
- PRs target `develop`

## Testing

- **PHPUnit**: `tests/Feature/` (HTTP/DB integration) + `tests/Unit/` (isolated). In-memory SQLite. Factories for test data. Tests in `sandbox` group are excluded by default.
- **Jest**: `resources/js/` with ts-jest, jsdom env, @testing-library/react. Module alias `@/`.
- Test fixtures: `tests/fixtures/` (CSV samples), `tests/Support/` (helpers).

## Protected Directories

Do NOT modify: `vendor/`, `node_modules/`, `public/`, `storage/`, `bootstrap/`, `.docker/`, `.github/` (without explicit permission). Critical files requiring permission: `.env`, `composer.json`/`.lock`, `package.json`/`.lock`, Docker configs, existing migrations.

## Environment

Key env vars: `DB_CONNECTION=sqlite`, `GOCARDLESS_USE_MOCK=true` (dev), `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`. See `.env.example` for full list. GoCardless credentials stored encrypted on User model (4 fields with `encrypted` cast).

## Data Conventions

- Monetary values: `decimal(15, 2)` in DB. Transactions store `amount` (original) + `native_amount`/`native_currency` for multi-currency.
- Currency codes: ISO 4217 (3-char string, e.g. `EUR`, `USD`).
- All user-facing resources scoped by `user_id` — use `BelongsToUser` trait on models, `WithUserScope` concern on repositories.
- Transaction deduplication: SHA256 fingerprint over normalized fields. Never bypass when importing.
- `ml/` — Python ML engine (categorization, merchant extraction). Separate `requirements.txt`, runs independently as its own Docker service.
