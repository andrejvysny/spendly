---
name: Feature request
about: Suggest an idea for Spendly
title: '[FEATURE] Implement budgeting functionality'
labels: 'enhancement'
assignees: ''
---

## Feature Description
Add comprehensive budgeting functionality to Spendly, enabling users to define budgets for categories or accounts and automatically track progress and spending relative to budgets based on transaction data.

## Problem/Use Case
Many users need to set spending limits and monitor their expenses against budget targets. Without built-in budgeting, users must manually calculate and compare their spending, which is time-consuming and error-prone.

## Proposed Solution
1. **Budget Model & Migration**: Create a `Budget` Eloquent model with fields: `id`, `user_id`, `name`, `amount`, `start_date`, `end_date`, `category_id` (nullable), `account_id` (nullable), and soft deletes.  
2. **Repository & Service**: Implement `BudgetRepository` for data access and `BudgetService` to encapsulate business logic (creation, update, calculation of spent amount, alerts).  
3. **Controllers & API**: Add `BudgetController` endpoints (index, show, store, update, destroy) under `app/Http/Controllers/`.  
4. **Rule Engine Integration**: Extend `RuleEngine` or a new pipeline stage to update associated budgets when transactions are created or updated.  
5. **Frontend Pages & Components**:  
   - Inertia page `resources/js/pages/BudgetsIndex.tsx` listing budgets with progress bars.  
   - Form component `resources/js/components/BudgetForm.tsx` for create/update.  
6. **UI & Styling**: Use `shadcn/ui` and Tailwind CSS for forms, cards, and progress bars.  
7. **Tests**:  
   - Unit tests for `BudgetService` and `BudgetRepository`.  
   - Feature tests for API endpoints and rule engine budget updates.  

## Alternative Solutions
- Use a tag-based workaround by tagging transactions and aggregating sums in reports, but this lacks a dedicated budget entity and lifecycle.  
- Leverage external budgeting integrations, but adds complexity and dependency on third-party services.

## Financial Domain Context
- **Financial Use Case**: Budget tracking and expense control.  
- **Affected Data Types**: Budgets, transactions, categories, accounts.  
- **Regulatory Considerations**: No sensitive payment operations; standard user data protections (GDPR).  
- **Currency Support**: Multi-currency budgets calculating spend in budget currency using transaction currency conversion.

## Technical Considerations
- **Affected Components**: Backend (models, services, controllers, pipeline), frontend (Inertia pages, components).  
- **Third-party Integrations**: None by default; use existing GoCardless integration unaffected.  
- **Performance Impact**: Budget progress calculation may require aggregating transactions per budget—use efficient queries and caching if needed.  
- **Security Implications**: Ensure budgets are user-scoped and enforce `user_id` checks in policies.

## User Interface Mockups
_No mockups available yet. Proposed: a dashboard card per budget with: budget name, allocated amount, spent amount, remaining amount, and a progress bar._

## Acceptance Criteria
- [ ] Users can create, view, update, and delete budgets via API and UI.  
- [ ] Budget progress updates automatically when transactions are added, updated, or deleted.  
- [ ] Budget list page displays correct progress bars.  
- [ ] Unit tests cover service logic and repository methods.  
- [ ] Feature tests verify API endpoints and budget syncing with transactions.

## Additional Context
Budgets should optionally scope to a category or an entire account. If both `category_id` and `account_id` are null, the budget applies to all transactions.

## Implementation Suggestions
- Use Laravel migrate to add `budgets` table.  
- Leverage existing `Policy` structure for authorization (`BudgetPolicy`).  
- Integrate budget update logic in `ProcessRulesJob` or create a dedicated `UpdateBudgetsJob`.  
- Frontend: follow patterns in `resources/js/pages/TransactionsIndex.tsx` and `FieldMappingService.ts` for form handling.

## Impact Assessment
- **User Benefit**: High—enhances financial control and visibility for users.  
- **Development Effort**: Medium—requires backend, frontend, and rule engine updates.  
- **Priority**: Medium—valuable but can follow core transaction features.

## Related Issues
None yet.
