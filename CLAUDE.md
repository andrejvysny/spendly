# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Spendly — open-source self-hosted personal finance tracker. Laravel 12 + React 19/TypeScript + Inertia.js. SQLite default. Bank sync via GoCardless. Active development, no v1.0 yet.

## Commands

```bash
# Dev servers (concurrent: artisan serve + queue + logs + vite)
composer dev

# Backend
php artisan test                          # all tests
php artisan test --filter=ClassName       # single test class
./vendor/bin/phpstan analyse              # static analysis (level 9)
./vendor/bin/pint                         # code formatting

# Frontend
npm run dev                               # vite dev server
npm run test                              # jest (watch mode)
npm test -- path/to/file                  # single test file
npm run types                             # tsc --noEmit
npm run lint                              # eslint + auto-fix
npm run format:check                      # prettier check

# Docker
docker compose up -d
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
- `Repositories/` — 21 repos implementing interfaces from `Contracts/Repositories/`. Concerns: `WithUserScope`, `WithOrdering`, `Paginating`.
- `Models/` — 26 Eloquent models. `BelongsToUser` trait for soft multi-tenancy. Transaction fingerprinting (SHA256) for deduplication.
- `Policies/` — authorization via `$this->authorize()`.
- `Providers/` — DI bindings: `RepositoryServiceProvider`, `GoCardlessServiceProvider`, `RuleEngineServiceProvider`.

### Frontend (resources/js/)

- `pages/` — Inertia page components (dashboard, accounts, transactions, analytics, import, rules, settings, etc.)
- `components/ui/` — 46+ shadcn/ui components
- `components/` — domain components (accounts, transactions, rules, charts, Import)
- `hooks/`, `layouts/`, `types/`, `utils/`, `lib/`
- Path alias: `@/` → `resources/js/`
- Inertia: `Head`, `router`, `usePage` from `@inertiajs/react`; type page props from controller payload

### Key Subsystems

**Import wizard**: upload → configure → map → clean → confirm/process. Controller: `ImportWizardController`. Frontend: `resources/js/pages/import/`. CLI: `php artisan import:csv <file> --account=<id|name> [--user=] [--mapping=] [--delimiter=] [--currency=] [--date-format=]`

**Rule Engine**: Models in `app/Models/RuleEngine/` (Rule, RuleGroup, ConditionGroup, RuleCondition, RuleAction). Enums: `ConditionField`, `ConditionOperator`, `ActionType`, `Trigger`. Events: `TransactionCreated`/`TransactionUpdated` → listener `ProcessTransactionRules`.

**GoCardless**: Production vs mock via client factories. Mock enabled by `GOCARDLESS_USE_MOCK=true`. CLI commands for all flows (see AGENTS.md). Fixture data in `sample_data/gocardless_bank_account_data/`.

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
