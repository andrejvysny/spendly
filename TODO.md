# Pre-release Checklist

## Done

- [x] Merchant → Counterparty rename (model, DB, backend, frontend, tests, docs)
- [x] GoCardless settings controllers (Credential, Requisition, Sync)
- [x] BalanceResolver for balance type selection
- [x] Dashboard card components (Budget Phase 2 UI)
- [x] Landing page
- [x] GoCardless sandbox integration tests
- [x] Frontend polish: console.log cleanup, window.location.reload → router.reload, WIP marker, dead code, SharedData type consolidation

## Remaining

### Test Coverage

- [ ] BudgetService unit tests (period creation, spending aggregation, rollover)
- [ ] GoCardless controller tests (Credential CRUD, Requisition lifecycle, Sync)
- [ ] Complete `markTestIncomplete` in RuleEngineTest
- [ ] BalanceResolver unit test

### Frontend

- [ ] Fix pre-existing TS errors (form-inputs module, review.tsx types, chart options)
- [ ] Fix app-logo.test.tsx Jest failure
- [ ] Replace remaining `console.error` in UI components with toast notifications

### Backend

- [ ] Import wizard column validation TODO
- [ ] ML system: document stub, plan real implementation
- [ ] Auto-sync scheduling for GoCardless
- [ ] Budget subcategory/rollover edge cases
