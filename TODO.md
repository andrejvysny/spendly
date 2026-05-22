# Current Session: ML-Intern Local Setup

- [x] Inspect current ml-intern config, scripts, tasks, docs, and cleanup scope
- [x] Implement local-only Kimi K2.6 config updates
- [x] Remove dataset Hub push flow and keep local export/prepare only
- [x] Rewrite ml-intern task prompts for local MLX training
- [x] Rewrite docs for Spendly local labeling/export/train workflow
- [x] Update ignore rules and verify changed paths/commands

# Current Session: Counterparty Cleanup + ML Export Hardening

- [ ] Review current cleanup/export pipeline and lock label policy
- [ ] Implement manifest-driven user-2 counterparty cleanup with role splits
- [ ] Re-run cleanup and regenerate review artifacts + ML datasets
- [ ] Harden partner ML export for role-aware filtered labels
- [ ] Validate final labels, iterate on noisy rows, and prep ml-intern datasets

# Production Release Review

## Critical (Must Fix)

### Security

- [x] **TransactionController missing `declare(strict_types=1)`** — fixed
- [x] **Error messages leak internals** — fixed: GoCardless controllers now return generic messages
- [x] **GoCardless credentials stored unencrypted** — Already handled: User model has `encrypted` cast on all 4 GoCardless fields. `GocardlessEncryptCredentialsCommand` exists for migrating legacy plaintext data. Frontend no longer receives raw credentials (masked display only).
- [x] **Routes outside auth middleware** — fixed: moved `/transactions/filter` and `/transactions/load-more` inside auth group
- [x] **`updateTransaction` has no authorization check** — fixed: added ownership check via `account.user_id`

### Null Safety / Runtime Crashes

- [x] **`Auth::user()->accounts()` can crash** — fixed: added `@var` annotation with typed user
- [x] **`Carbon::createFromFormat` can return null** — fixed: added null guards in AnalyticsController
- [ ] **Missing null check on `$first`/`$second`** — `TransactionController:582-583` calls `$first->amount` after `$transactions->first()`. Safe due to count check but PHPStan flags it.

## High Priority

### Backend Quality

- [ ] **TransactionController is 1000+ lines** — Extract filter logic, bulk operations, and summary calculations into separate services
- [ ] **N+1 query in BudgetService::getSuggestedAmounts** — Line 322 queries `Transaction::where('recurring_group_id', $group->id)` inside foreach. Eager-load or batch.
- [ ] **N+1 in BudgetService::getSpentForPeriod** — Called once per budget in `getBudgetsWithProgress`. Pre-fetch accounts once and batch-compute spending.
- [ ] **BudgetController duplicated serialization code** — `index()` and `builder()` both map categories/tags/counterparties/recurringGroups/accounts identically. Extract shared method.
- [x] **Missing exchange rate fetch scheduler** — fixed: added `exchange-rates:fetch` daily at 06:00
- [x] **GoCardlessRequisitionController:358 missing validation** — fixed: added `$request->validate()` and `$request->input()`

### Frontend / TypeScript

- [x] **TypeScript errors fixed:**
    - [x] `rules/index.tsx` — replaced inline type with `RuleOptionsResponse['data']`
    - [x] `review.tsx` — removed invalid breadcrumbs prop, added `review_reason`/`needs_manual_review` to Transaction type
    - [x] `form-inputs/index.ts` — removed dead re-exports of non-existent modules
- [ ] **Remaining TS errors (5, pre-existing):**
    - `smart-form.tsx:26` — Zod schema type mismatch with resolver
    - `MonthlyComparisonChart.tsx:85` — chart.js options type
    - `requisition.tsx:153`, `TransactionDetails.tsx:202` — tooltip className prop
    - `multi-select.tsx:116` — generic array type mismatch
- [x] **ESLint scanning `venv/`/`ml/`/`landing/`** — fixed: added to eslint ignores (83K→72 errors)
- [ ] **Remaining ESLint (72, pre-existing):** unused vars in `failures.tsx`, `any` types in type files, missing effect deps
- [x] **`filters` unused in review.tsx** — fixed: removed

### Docker / Infrastructure

- [ ] **`compose.yml` `cli` container** — No database volume persistence for production
- [ ] **Orphan containers piling up** — Use `--rm` flag
- [ ] **`public/frankenphp-worker.php`** — Untracked, needs commit or .gitignore

## Medium Priority

### Backend

- [x] **BudgetService::computePeriodEnd bug** — fixed: `startOfDay()` → `endOfDay()`
- [ ] **PHPStan errors in changed files (187 total)** — mostly type annotation gaps. Key ones:
    - `BudgetRepository` returns `Eloquent\Collection` vs `Support\Collection<Budget>`
    - `AnalyticsController` passes `array<mixed>` where `array<int>` expected
    - `Budget::$attributes` has `false` in `array<string, string>`
- [ ] **`BackfillNativeAmountsCommand` does individual UPDATEs** — Use batch update for performance
- [ ] **ExchangeRateService calls external API during requests** — `ensureRatesExist()` may trigger HTTP to Frankfurter during user requests. Should be background-only.

### Testing

- [ ] **GoCardless Settings controllers have no feature tests** — Only sandbox integration test exists
- [ ] **`markTestIncomplete` in RuleEngineTest** — Still incomplete
- [ ] **AnalyticsController has no tests** — Multi-currency analytics untested
- [ ] **TransactionController bulk operations need auth edge-case tests**

## Low Priority / Polish

- [ ] Remove `console.error` calls in UI components, use toast notifications
- [ ] Add index on `exchange_rates(base_currency, target_currency, date)` if not exists
- [ ] Add rate limiting to GoCardless API proxy endpoints
- [ ] Consider CSRF protection for JSON API routes under `/api/bank-data/`
