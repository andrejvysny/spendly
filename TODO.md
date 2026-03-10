# Phase 2: Builder Views + Table/Card Toggle

## Backend

- [x] BudgetService: getSuggestedAmounts() using recurring groups
- [x] BudgetController: builder() method (Inertia page)
- [x] BudgetController: suggestAmounts() endpoint (JSON)
- [x] Routes: GET /budgets/builder, GET /budgets/suggestions

## Frontend - Extract Components

- [x] BudgetProgressBar.tsx (reusable)
- [x] BudgetCard.tsx (extracted from Index)
- [x] BudgetTable.tsx (new table view)

## Frontend - Pages

- [x] Index.tsx: add card/table view toggle
- [x] Builder.tsx: budget builder grid page

## Tests

- [x] BudgetController builder/suggestions tests
- [x] BudgetService getSuggestedAmounts test

## Verification

- [x] phpstan
- [x] pint
- [x] npm run types
- [x] php artisan test --filter=Budget
