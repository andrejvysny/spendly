---
title: Architecture Overview
description: High-level architecture of Spendly — backend, frontend, and key subsystems.
---

## Stack

- **Backend**: Laravel 12 (PHP 8.3+)
- **Frontend**: React 19 + TypeScript + Inertia.js
- **Database**: SQLite (default), MySQL, PostgreSQL
- **UI**: shadcn/ui + Radix UI + Tailwind CSS
- **Bank Sync**: GoCardless Bank Account Data API
- **Search**: SQLite FTS5

## Backend Pattern

**Controllers → Services → Repositories** (with contracts/interfaces)

### Controllers (`app/Http/Controllers/`)

Thin controllers that delegate to services. Inertia pages via `Inertia::render('page/name', [...])` where the page name matches `resources/js/pages/` path.

### Services (`app/Services/`)

Business logic layer. Key subsystems:

- **GoCardless/** (14 services) — bank sync, token management, mock/production client factories
- **RuleEngine/** — `RuleEngine`, `ConditionEvaluator`, `ActionExecutor` with enums for conditions/actions/triggers
- **TransactionImport/** — CSV import pipeline: parse → validate → deduplicate → persist
- **RecurringDetectionService** — pattern matching for recurring transactions

### Repositories (`app/Repositories/`)

21 repositories implementing interfaces from `app/Contracts/Repositories/`. Shared concerns: `WithUserScope`, `WithOrdering`, `Paginating`.

### Models (`app/Models/`)

26 Eloquent models. `BelongsToUser` trait for soft multi-tenancy (all user-facing tables have `user_id`). Transaction fingerprinting (SHA256) for deduplication.

### Providers (`app/Providers/`)

DI bindings: `RepositoryServiceProvider`, `GoCardlessServiceProvider`, `RuleEngineServiceProvider`.

## Frontend

### Directory Structure

```
resources/js/
├── pages/          # Inertia page components
├── components/
│   ├── ui/         # 46+ shadcn/ui components
│   └── ...         # Domain components (accounts, transactions, rules, charts)
├── hooks/
├── layouts/
├── types/
├── utils/
└── lib/
```

- Path alias: `@/` → `resources/js/`
- Inertia: `Head`, `router`, `usePage` from `@inertiajs/react`
- Forms: React Hook Form + Zod
- Styling: Tailwind CSS with shadcn/ui components

## Key Subsystems

### Import Wizard

Upload → Configure → Map → Clean → Confirm/Process

Controller: `ImportWizardController`. Frontend: `resources/js/pages/import/`. Auto-mapping via `FieldMappingService.ts`.

CLI: `php artisan import:csv <file> --account=<id|name>`

### Rule Engine

Models: `Rule`, `RuleGroup`, `ConditionGroup`, `RuleCondition`, `RuleAction`. Enums: `ConditionField`, `ConditionOperator`, `ActionType`, `Trigger`.

Events: `TransactionCreated`/`TransactionUpdated` → listener `ProcessTransactionRules`. Processed via Laravel Pipeline pattern by priority order, with stop conditions. Jobs run asynchronously. Audit logging in `rule_execution_logs`.

### GoCardless

Production vs mock via client factories. Mock enabled by `GOCARDLESS_USE_MOCK=true`. Fixture data in `sample_data/gocardless_bank_account_data/`.

### Recurring Detection

Detects subscriptions from transaction history. Groups by payee, infers interval (weekly/monthly/quarterly/yearly), checks amount consistency. Results are suggestions until user confirms.

## Data Conventions

- **Monetary values**: `decimal(15, 2)` in database
- **Currency codes**: ISO 4217 (3-char string, e.g., `EUR`, `USD`)
- **User scoping**: All resources scoped by `user_id` — `BelongsToUser` trait on models, `WithUserScope` concern on repositories
- **Authorization**: Policy-based via `$this->authorize()`

## Environment

Key env vars:

```ini
DB_CONNECTION=sqlite
GOCARDLESS_USE_MOCK=true   # dev only
QUEUE_CONNECTION=database
SESSION_DRIVER=database
```
