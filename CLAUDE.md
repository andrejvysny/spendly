# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Spendly — open-source self-hosted personal finance tracker. Laravel 12 + React 19/TypeScript + Inertia.js. SQLite default. Bank sync via GoCardless. Active development, no v1.0 yet. PHP 8.3+.

## Commands

```bash
# Dev servers (concurrent: artisan serve + queue + logs + vite)
docker compose run cli composer dev

# Backend
docker compose run cli php artisan test                          # all tests
docker compose run cli php artisan test --filter=ClassName       # single test class
docker compose run cli ./vendor/bin/phpstan analyse              # static analysis (level 9)
docker compose run cli ./vendor/bin/pint                         # code formatting
docker compose run cli php artisan migrate:fresh --seed          # reset DB with demo data

# Frontend
npm run dev                               # vite dev server
npm run build                             # production build
npm run test                              # jest (watch mode)
npm test -- path/to/file                  # single test file
npm run types                             # tsc --noEmit
npm run lint                              # eslint + auto-fix
npm run format:check                      # prettier check

# Docker (prefix PHP/Composer commands when using Docker)
docker compose up -d
docker compose run cli php artisan [command]
docker compose run cli ./vendor/bin/phpstan analyse
./scripts/dev.sh                          # full Docker dev setup
./scripts/test.sh                         # full test suite in container
```

**After PHP changes**: run phpstan + pint (or tests).
**After TS/React changes**: run types + lint (or tests).
Prefer targeted test runs for speed.

## Architecture

### Backend (app/)

**Pattern**: Controllers → Services → Repositories (with contracts/interfaces).

- `Controllers/` — thin, delegate to services. Inertia pages via `Inertia::render('page/name', [...])` where page name matches `resources/js/pages/` path.
- `Services/` — business logic. Key subsystems:
    - `GoCardless/` (14 services) — bank sync, token management, mock/production client factories via `BankDataClientInterface`
    - `RuleEngine/` — `RuleEngine`, `ConditionEvaluator`, `ActionExecutor` with enums for conditions/actions/triggers
    - `TransactionImport/` — CSV import pipeline: parse → validate → deduplicate → persist
    - `RecurringDetectionService` — pattern matching for recurring transactions
    - `TransferDetectionService` — rule-based + ML transfer pair detection across accounts
    - `BudgetService` — budget progress tracking, spending aggregation per category/period
    - `MlService` — Python ML engine client (transaction categorization, transfer detection)
    - `TransactionImport/FieldMappingService.ts` — auto-mapping columns, reusable import mappings per bank
- `Repositories/` — 21 repos implementing interfaces from `Contracts/Repositories/`. Concerns: `WithUserScope`, `WithOrdering`, `Paginating`.
- `Models/` — 26 Eloquent models. `BelongsToUser` trait for soft multi-tenancy (all user-facing tables have `user_id`). Transaction fingerprinting (SHA256) for deduplication.
- `Policies/` — authorization via `$this->authorize()`.
- `Providers/` — DI bindings: `RepositoryServiceProvider`, `GoCardlessServiceProvider`, `RuleEngineServiceProvider`.

### Frontend (resources/js/)

- `pages/` — Inertia page components (dashboard, accounts, transactions, analytics, import, rules, settings, etc.)
- `components/ui/` — 46+ shadcn/ui components
- `components/` — domain components (accounts, transactions, rules, charts, Import)
- `hooks/`, `layouts/`, `types/`, `utils/`, `lib/`
- Path alias: `@/` → `resources/js/`
- Inertia: `Head`, `router`, `usePage` from `@inertiajs/react`; type page props from controller payload
- `cn()` utility + CVA for component variants; icons via `lucide-react`

### Key Subsystems

**Import wizard**: upload → configure → map → clean → confirm/process. Controller: `ImportWizardController`. Frontend: `resources/js/pages/import/`. Field auto-mapping via pattern matching in `FieldMappingService.ts`. CLI: `php artisan import:csv <file> --account=<id|name> [--user=] [--mapping=] [--delimiter=] [--currency=] [--date-format=]`

**Rule Engine**: Models in `app/Models/RuleEngine/` (Rule, RuleGroup, ConditionGroup, RuleCondition, RuleAction). Enums: `ConditionField`, `ConditionOperator`, `ActionType`, `Trigger`. Events: `TransactionCreated`/`TransactionUpdated` → listener `ProcessTransactionRules`.

**Rule Engine pipeline**: Rules processed via Laravel Pipeline pattern by priority order, respecting stop conditions. Jobs process rules asynchronously via queues. Audit logging tracks all executions in `rule_execution_logs`.

**GoCardless**: Production vs mock via client factories. Mock enabled by `GOCARDLESS_USE_MOCK=true`. Fixture data in `sample_data/gocardless_bank_account_data/`.

### CLI Commands (for testing/automation)

Quick reference (see AGENTS.md for full options, examples, and GoCardless CLI table):

```bash
# CSV Import (without web wizard)
php artisan import:csv <file> --account=<id|name> [--user=] [--mapping=] [--delimiter=] [--currency=] [--date-format=]

# GoCardless (mock mode by default in dev)
docker compose run cli php artisan gocardless:institutions --country=sk
docker compose run cli php artisan gocardless:connect --institution=SLSP --user=3
docker compose run cli php artisan gocardless:sync --account=1 --user=3
docker compose run cli php artisan gocardless:sync-all
```

Sample data: `sample_data/csv/` (Revolut, SLSP), `sample_data/gocardless_bank_account_data/`. With seeded DB use `--user=3` for demo user.

### Detailed Subsystem Docs

`docs/ai/` contains architecture deep-dives: `GoCardless_Architecture.md`, `RULE_ENGINE.md`, `CSV_Service_Architecture.md`, `RECURRING_PAYMENTS.md`, `REACT_TESTING.md`, `LARAVEL_TESTING.md`. Consult these before making significant changes to a subsystem.

## Conventions

### PHP/Laravel

- `declare(strict_types=1)` in all PHP files
- PSR-12 coding standards
- Form Requests for validation, keep controllers thin
- Dependency injection over facades
- Conventional commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`

### React/TypeScript

- Functional components with TypeScript interfaces for props
- Prettier: single quotes, tabWidth 4, printWidth 150, plugins: organize-imports + tailwindcss
- shadcn/ui + Radix UI for components, Tailwind CSS for styling
- React Hook Form + Zod for forms

### Branching

- `main` — production, `develop` — integration
- Feature branches from `develop`: `feature/github-issue-id`, `fix/github-issue-id`
- PRs target `develop`

## Testing

- **PHPUnit**: `tests/Feature/` (HTTP/DB integration) + `tests/Unit/` (isolated). In-memory SQLite. Factories for test data.
- **Jest**: `resources/js/` with ts-jest, jsdom env, @testing-library/react. Module alias `@/`.
- Test fixtures: `tests/fixtures/` (CSV samples), `tests/Support/` (helpers).

## Protected Directories

Do NOT modify: `vendor/`, `node_modules/`, `public/`, `storage/`, `bootstrap/`, `.docker/`, `.github/` (without explicit permission). Critical files requiring permission: `.env`, `composer.json`/`.lock`, `package.json`/`.lock`, Docker configs, existing migrations.

## Environment

Key env vars: `DB_CONNECTION=sqlite`, `GOCARDLESS_USE_MOCK=true` (dev), `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`. See `.env.example` for full list. GoCardless credentials stored on User model (tokens, secret keys).

## Data Conventions

- Monetary values: `decimal(15, 2)` in DB. Use appropriate precision in calculations.
- Currency codes: ISO 4217 (3-char string, e.g. `EUR`, `USD`).
- All user-facing resources scoped by `user_id` — use `BelongsToUser` trait on models, `WithUserScope` concern on repositories.
- `ml/` — Python ML engine (transaction classification). Separate `requirements.txt`, runs independently.

## Deep-Dive Docs

`docs/ai/` contains detailed architecture docs for subsystems (GoCardless, Rule Engine, Budgeting) — consult before complex changes.
