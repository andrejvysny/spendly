# Plans & Implementation Status

This document summarizes all identified plans/designs in the codebase and their current implementation status. Last updated: 2025-02-05.

---

## 1. Repository Migration Plan

**Source:** `docs/repository-migration/Repository-Migration-Plan.md`, `Model-and-Facade-Usage-Audit.md`, `Artifact-Inventory.md`, `Coverage-Matrix.md`

**Goals:** Replace direct Eloquent/DB usage with repository interfaces via DI; preserve behavior; keep controllers thin; ensure tests stay green.

### Status: **Partially implemented**

| Item | Plan | Current state |
|------|------|----------------|
| **Interfaces & implementations** | Account, Transaction, Rule (+ later Merchants/Categories/Tags, Import, ImportFailure, Analytics query) | **Done for core + more:** `AccountRepository`, `TransactionRepository`, `RuleRepository`, plus `CategoryRepository`, `TagRepository`, `MerchantRepository`, `ImportRepository`, `ImportMappingRepository`, `ImportFailureRepository`, `RuleGroupRepository`, `ConditionGroupRepository`, `RuleActionRepository`, `RuleConditionRepository`, `RuleExecutionLogRepository`, `GoCardlessSyncFailureRepository`, `UserRepository`. All bound in `RepositoryServiceProvider`. |
| **Controllers use repos** | Replace direct Model/DB in controllers | **Partial:** Many controllers still use `Model::` and `$model->` directly (e.g. `AccountController`, `TransactionController`, `ImportWizardController`, `RuleController`, `ImportFailureController` uses `$import->failures()`). `TransactionPersister` and `ImportFailurePersister` use repositories. |
| **Analytics / cashflow** | Move `DB::table('transactions')` into dedicated query repo | **Not done:** `AnalyticsController` still uses `DB::table('transactions')` in multiple places (lines 145, 264, 281, 309, 326). `AccountController` uses `DB::table('transactions')` for cashflow (line 147). No `AnalyticsQueryRepository` exists. |
| **Rule Engine ActionExecutor** | Replace `Tag::firstOrCreate`, `Category::firstOrCreate`, `Merchant::firstOrCreate` with repo lookups | **Not done:** `ActionExecutor` still uses `firstOrCreate` on Tag, Category, Merchant (audit lines 251, 268, 284). Repos exist but are not used here. |
| **ImportFailurePersister** | Use ImportFailureRepository | **Done:** Injects `ImportFailureRepositoryInterface` and uses it (implementation still uses `DB::table('import_failures')->insert` internally, which is an implementation detail). |
| **TransactionSyncService** | Use repo transaction helper | **Not verified:** Audit suggested switching to repo transaction; `TransactionSyncService` still uses `DB::transaction()` directly. |

**Conclusion:** Repository layer is largely built and bound; migration of controllers and analytics/query paths to use repos is incomplete. `Coverage-Matrix.md` is outdated (e.g. it still lists ImportFailure as “Missing”).

---

## 2. Rule Engine Optimizations

**Source:** `docs/RuleEngine_Optimizations.md`

**Goals:** Multi-level caching, DB query reduction, memory management, batched logging, transaction management, performance monitoring.

### Status: **Implemented**

- **Rule caching / condition groups / actions:** Implemented in `RuleEngine`, `ConditionEvaluator`, `ActionExecutor` (e.g. `getCacheStats()`, `clearCaches()` on `RuleEngine` and `ActionExecutor`; `ConditionEvaluator::getCacheStats()`).
- **Chunked/batch processing:** Documented and reflected in `RuleEngine` (e.g. `processBatch`, chunk size).
- **Batched logging:** Document refers to queue-based logging and batch inserts; implementation present in rule engine flow.
- **Rule-level transactions / dry run:** Handled in rule execution.
- **Future optimizations (Redis, async, rule compilation, sharding, parallel):** Documented as “Future” — **not implemented** and not part of current scope.

---

## 3. Budgeting Feature

**Source:** `docs/ai/BUDGETING_FEATURE_REQUEST.md`

**Goals:** Budget model, repository, service, API, rule engine integration, frontend (list + form), tests.

### Status: **Not implemented**

- No `Budget` model, no `budgets` migration, no `BudgetController`, no `BudgetService`, no `BudgetRepository`, no budget API routes.
- No frontend pages (`BudgetsIndex.tsx`, `BudgetForm.tsx`) or routes.
- No rule engine / pipeline integration for budget updates.

---

## 4. Full-Text Search (FTS)

**Source:** `docs/ai/FULLTEXT_SEARCH.md`

**Goals:** Doc describes SQLite FTS5 virtual tables for efficient full-text search (e.g. on transactions).

### Status: **Not implemented** (reference only)

- Document is explanatory (how FTS5 works, example workflow). No FTS5 migration, no `transactions_fts` table, no application code using FTS. Transaction search (if any) is not backed by FTS in the codebase.

---

## 5. Recurring Payments Detection

**Source:** `docs/ai/RECURRING_PAYMENTS.md`

**Goals:** RecurringGroup model, detection algorithm, run-after-import + scheduled job, API, UI (recurring page, settings, transaction badge/filter), tag sync.

### Status: **Implemented**

- **Models:** `RecurringGroup`, `RecurringDetectionSetting`, `DismissedRecurringSuggestion`; `Transaction.recurring_group_id` migration.
- **Service & job:** `RecurringDetectionService`, `RecurringDetectionJob`.
- **Console:** `php artisan recurring:detect` with `--user` and `--account`.
- **API:** Routes under `/api/recurring` (index, analytics, settings, confirm, dismiss, unlink).
- **Controllers:** `RecurringController` (web), `RecurringGroupController` (API).
- **Frontend:** `resources/js/pages/recurring/index.tsx`, `resources/js/pages/settings/recurring.tsx`.
- **Integration:** Referenced from import and Bank Data sync flows (e.g. `ImportWizardController`, `BankDataController`).

---

## 6. Import Failure Management

**Source:** `docs/ai/README_IMPORT_FAILURES.md`, `docs/ai/FRONTEND_IMPLEMENTATION_SUMMARY.md`

**Goals:** Store failed/skipped CSV rows, track error types, manual review workflows, bulk operations, CSV export, API and UI.

### Status: **Implemented**

- **Schema & model:** `import_failures` table; `ImportFailure` model with error types, statuses, scopes, and review methods.
- **Persistence:** `ImportFailurePersister` (uses `ImportFailureRepository`); integrated in import flow.
- **API & controller:** `ImportFailureController` — failures page, index, stats, show, reviewed/resolved/ignored, bulk, export. Uses `$import->failures()` for reads.
- **Frontend:** `resources/js/pages/import/failures.tsx` and related components (e.g. `FailureCollapse`, `ReviewInterface`); import list “Review Failures” and failure review flow as described in FRONTEND_IMPLEMENTATION_SUMMARY.

---

## 7. GoCardless Bank Data Architecture

**Source:** `docs/ai/GoCardless_Architecture.md`

**Goals:** Document architecture: Bank Data API v2, controllers, services, client interface, mock/production factory, token flow, requisitions, account import, transaction sync (account-scoped), routes and config.

### Status: **Implemented** (architecture as documented)

- Controllers, `GoCardlessService`, `BankDataClientInterface`, production and mock clients, `TokenManager`, factory and provider, sync with account-scoped dedupe (`TransactionRepository::getExistingTransactionIds` / `updateBatch`) are present and align with the doc.

---

## 8. Transaction Events (TransactionCreated / TransactionUpdated)

**Source:** `docs/ai/cursor_dispatching_events_for_transacti.md` (Cursor Q&A export)

**Goals:** Dispatch `TransactionCreated` and `TransactionUpdated` so Rule Engine processes new/updated transactions; optionally use model events and `_apply_rules`-style control.

### Status: **Partially implemented**

- **TransactionCreated:** Implemented. Fired from `TransactionPersister` (batch/single create paths) with `Transaction` model and `applyRules` flag; listener `ProcessTransactionRules::handleTransactionCreated` runs rule engine. No model `booted()` dispatching — dispatching is from service layer.
- **TransactionUpdated:** Listener `ProcessTransactionRules::handleTransactionUpdated` exists and is registered. **Not dispatched in app code:** `TransactionController::updateTransaction()` and bulk update paths call `$transaction->update(...)` but do not fire `TransactionUpdated`. Only tests dispatch it. So rule engine does **not** run on manual transaction updates in the UI/API.

---

## 9. Cursor Agents & Rules

**Source:** `.cursor/agents/*.md`, `.cursor/rules/*.mdc`

**Goals:** Subagent routing (import-wizard, gocardless, rule-engine, frontend, architecture, debugger, test-runner, verifier) and coding/UI/testing rules.

### Status: **Implemented** (guidance only)

- Agent and rule files exist and are referenced by the workflow; no “implementation” to verify beyond presence and consistency with the codebase.

---

## Summary Table

| Plan / Feature | Status | Notes |
|----------------|--------|--------|
| Repository migration | Partial | Repos and bindings in place; controllers and analytics still use Eloquent/DB; no AnalyticsQueryRepository; ActionExecutor still uses firstOrCreate. |
| Rule Engine optimizations | Done | Caching, batching, logging, monitoring as in doc; “Future” items not done. |
| Budgeting | Not done | No backend or frontend. |
| Full-text search (FTS) | Not done | Doc only; no FTS in app or DB. |
| Recurring payments | Done | Models, job, command, API, UI, settings. |
| Import failure management | Done | Backend and frontend as described. |
| GoCardless architecture | Done | Matches documented design. |
| Transaction events | Partial | TransactionCreated used; TransactionUpdated not dispatched on update. |

---

## Recommended Next Steps

1. **Repository migration:** Introduce `AnalyticsQueryRepository` (and optionally a cashflow query) and move `AnalyticsController` and `AccountController` cashflow logic behind it; then gradually switch remaining controller model access to repositories. Optionally refactor `ActionExecutor` to use Category/Merchant/Tag repositories for get-or-create.
2. **TransactionUpdated:** After any transaction update that should re-run rules (e.g. `TransactionController::updateTransaction` and bulk update methods), dispatch `TransactionUpdated` with the transaction (and optionally changed attributes). Consider model `updated` event if all update paths should trigger rules.
3. **Budgeting:** Treat as a new feature; implement per BUDGETING_FEATURE_REQUEST (model, repo, service, API, rule integration, frontend, tests).
4. **Full-text search:** If transaction search is a product goal, add an FTS5 migration and wire search to it; otherwise leave doc as reference.
5. **Docs:** Update `docs/repository-migration/Coverage-Matrix.md` and, if needed, `Artifact-Inventory.md` to reflect current repo coverage and remaining gaps.
