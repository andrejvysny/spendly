# AGENTS.md - Services Layer

## Overview

Business logic layer for Spendly. 64 PHP files across 15 subdirectories. Controllers delegate here.

## Structure

```
app/Services/
├── BudgetService.php           # Budget progress tracking
├── ExchangeRateService.php     # ECB/Frankfurter multi-currency
├── RecurringDetectionService.php
├── TransferDetectionService.php
├── Csv/                        # CSV parsing utilities
├── GoCardless/                 # Bank sync (19 files)
├── Import/                     # Import orchestration
├── RuleEngine/                 # Transaction rules
└── TransactionImport/          # CSV import pipeline
```

## Key Services

| Service                     | Purpose                            | Key Methods                                       |
| --------------------------- | ---------------------------------- | ------------------------------------------------- |
| `BudgetService`             | Budget progress aggregation        | `getBudgetsWithProgress()`, `calculateSpending()` |
| `ExchangeRateService`       | Currency conversion                | `convert()`, `fetchLatestRates()`                 |
| `RecurringDetectionService` | Pattern matching for subscriptions | `detectRecurring()`                               |
| `TransferDetectionService`  | Cross-account transfer pairs       | `detectTransfers()`                               |

## GoCardless Subsystem

**Location**: `app/Services/GoCardless/`

19 services handling bank data integration:

- `GoCardlessBankDataClient.php` — API client with token refresh
- `TokenManager.php` — OAuth token lifecycle
- `BankDataClientFactory.php` — Mock/production client factory
- `SyncService.php` — Transaction sync orchestration

**Mock Mode**: Set `GOCARDLESS_USE_MOCK=true` for development. Fixtures in `sample_data/gocardless_bank_account_data/`.

## Rule Engine

**Location**: `app/Services/RuleEngine/`

Pipeline-based rule processing:

- `RuleEngine.php` — Main orchestrator
- `ConditionEvaluator.php` — Condition logic
- `ActionExecutor.php` — Action application

Triggered by `TransactionCreated`/`TransactionUpdated` events.

## Conventions

- **Dependency Injection**: Constructor injection via `private readonly` properties
- **Interfaces**: Services implement contracts in `app/Contracts/`
- **Return Types**: Full type hints + PHPDoc for collections
- **Error Handling**: Log then re-throw or return `Result` objects

## Anti-Patterns

- **NEVER** use facades (prefer DI)
- **NEVER** access request data directly (pass as parameters)
- **NEVER** call other services from constructors

## Testing

```bash
php artisan test --filter=BudgetServiceTest
php artisan test tests/Unit/Services/
```

Use mocks for external APIs (GoCardless, exchange rates).
