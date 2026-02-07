# Repository Migration Plan

## Goals
- Replace direct Eloquent/DB usage with repository interfaces via DI
- Preserve behavior and performance (batch paths + analytics)
- Keep controllers thin; move data access to repositories
- Ensure tests stay green; avoid regressions in rule engine/imports

## Scope & Order (Checklist)
- [ ] Core domain first, by dependency and traffic
  1) Accounts (Account) — foundation for user/account scoping
  2) Transactions (Transaction) — high traffic, imports/sync, rule engine
  3) Rules (Rule, RuleGroup, ConditionGroup, RuleCondition, RuleAction) — rule engine lifecycle
  4) Merchants/Categories/Tags — lookups, firstOrCreate semantics
  5) Imports (Import, ImportFailure, ImportMapping) — CSV flow
  6) Analytics/Aggregations — move DB builder into dedicated query repos
  7) Remaining models (RuleExecutionLog, etc.)

## Dependencies
- Transactions depend on Accounts, Merchants, Categories, Tags
- Rule engine touches Transactions, Merchants, Categories, Tags
- Import system writes Transactions and ImportFailures

## Risks & Mitigations
- Performance regressions on batch insert/update
  - Mitigate with bulk methods (insertBatch, upsertBulk) and explicit transactions
- Behavior changes in rule engine side-effects
  - Keep events intact; write focused tests on TransactionCreated/Updated
- Controller coupling to Eloquent builders (paginate, filters)
  - Expose query methods on repos returning LengthAwarePaginator or CursorPaginator
- Cross-service transactions
  - Provide transaction(fn) helper in BaseRepository using DB::transaction

## Interfaces & Contracts (Step 2 Preview)
- BaseRepositoryContract { transaction(callable $fn): mixed }
- AccountRepositoryInterface { findByIdForUser, findByGocardlessId, getGocardlessSyncedAccounts, updateSyncTimestamp, create, gocardlessAccountExists }
- TransactionRepositoryInterface { findByTransactionId, createBatch, updateOrCreate, getExistingTransactionIds, updateBatch }
- RuleRepositoryInterface { createRuleGroup, updateRuleGroup, createRule, updateRule, deleteRule, getRuleGroups, getRule, getRulesByTrigger, duplicateRule, reorderRules, getRuleStatistics }
- Add specialized QueryRepositories later for analytics to keep write repos clean

## Acceptance
- All direct DB/Eloquent calls replaced in targeted layers
- Interfaces bound in a RepositoryServiceProvider and injected via constructors
- Tests pass locally and in CI; key flows smoke-tested
