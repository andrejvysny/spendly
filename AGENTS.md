# AGENTS.md - AI Assistant Guidelines for Spendly

## Project Overview

Spendly is an open-source personal finance tracker built with Laravel (backend) and React/TypeScript (frontend). This document provides guidelines for AI assistants working on this codebase.

**Tech Stack:**
- **Backend**: Laravel 12.x with PHP 8.3+
- **Frontend**: React 19.x with TypeScript 5.x
- **Database**: SQLite/MySQL/PostgreSQL with Eloquent ORM
- **UI Framework**: Tailwind CSS + shadcn/ui components
- **Build Tool**: Vite
- **Bridge**: Inertia.js
- **Testing**: PHPUnit (backend), Jest (frontend)
- **Deployment**: Docker

## Project Structure & Scope

For Cursor-specific commands and scoped rules, see `.cursor/rules/` and `.cursor/commands/`.

### Modifiable Directories
AI assistants **CAN** modify files in these directories:
- `/app` - Laravel application code (Models, Controllers, Services)
- `/resources/js` - React components, pages, hooks, utilities
- `/resources/css` - Stylesheets
- `/routes` - API and web routes
- `/database` - Migrations, seeders, factories
- `/tests` - Test files
- `/config` - Configuration files (with caution)
- `/docs` - Documentation

### Protected Directories
AI assistants **SHOULD NOT** modify:
- `/vendor` - Composer dependencies
- `/node_modules` - NPM dependencies
- `/public` - Public assets (except generated files)
- `/storage` - Application storage
- `/bootstrap` - Framework bootstrap files
- `/.docker` - Docker configurations (without explicit permission)
- `/.github` - CI/CD workflows (without explicit permission)

### Critical Files (Require Explicit Permission)
- `.env` files
- `composer.json` / `composer.lock`
- `package.json` / `package-lock.json`
- Docker configuration files
- Database migration files (after initial creation)

## Coding Conventions

### PHP/Laravel Standards

1. **Style Guide**: Follow PSR-12 coding standards
2. **Type Declarations**: Use strict typing in all PHP files
   ```php
   <?php
   
   declare(strict_types=1);
   ```
3. **Naming Conventions**:
   - Classes: PascalCase (e.g., `TransactionController`)
   - Methods/Functions: camelCase (e.g., `getTransactionsByMonth()`)
   - Variables: camelCase (e.g., `$totalAmount`)
   - Constants: UPPER_SNAKE_CASE (e.g., `DEFAULT_CURRENCY`)
   - Database tables: snake_case plural (e.g., `bank_accounts`)
   - Database columns: snake_case (e.g., `created_at`)

4. **File Organization**:
   - One class per file
   - Filename matches class name
   - Use proper namespace structure

### TypeScript/React Standards

1. **Style Guide**: Use Prettier configuration (see `.prettierrc`)
2. **Component Structure**:
   ```tsx
   // 1. Imports
   import React from 'react';
   
   // 2. Type definitions
   interface ComponentProps {
     // props
   }
   
   // 3. Component definition
   export function ComponentName({ prop }: ComponentProps) {
     // 4. Hooks
     // 5. Event handlers
     // 6. Render
   }
   ```

3. **Naming Conventions**:
   - Components: PascalCase (e.g., `TransactionList`)
   - Functions/Hooks: camelCase (e.g., `useTransactions()`)
   - Types/Interfaces: PascalCase with descriptive suffixes
   - Files: Match component names

4. **Type Safety**: Always define proper TypeScript types/interfaces

## Prompt Structure Guidelines

### For Backend (Laravel) Development

```php
// Task: Create a service to calculate spending analytics
// Context: Need to analyze user spending patterns by category
// Requirements:
// - Calculate monthly totals by category
// - Identify spending trends
// - Support date range filtering

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Collection;

class SpendingAnalyticsService
{
    // Implementation...
}
```

### For Frontend (React) Development

```tsx
// Task: Create a spending chart component
// Context: Display monthly spending data in a bar chart
// Requirements:
// - Use Chart.js for visualization
// - Support multiple currencies
// - Responsive design

import React from 'react';
import { Bar } from 'react-chartjs-2';

interface SpendingChartProps {
  data: SpendingData[];
  currency: string;
}

export function SpendingChart({ data, currency }: SpendingChartProps) {
  // Implementation...
}
```

## Testing Requirements

### Backend Testing
1. **Unit Tests**: Required for all Services and critical Model methods
   ```bash
   php artisan test
   php artisan test --filter=SpendingAnalyticsTest
   ```

2. **Feature Tests**: Required for all API endpoints
   ```php
   public function test_can_get_spending_analytics(): void
   {
       $user = User::factory()->create();
       // Test implementation
   }
   ```

### Frontend Testing
1. **Component Tests**: Required for critical UI components
   ```bash
   npm run test
   ```

2. **Type Checking**:
   ```bash
   npm run types
   ```

### Code Quality Checks
```bash
# Backend
./vendor/bin/phpstan analyse
./vendor/bin/pint

# Frontend
npm run lint
npm run format:check
```

### CLI and AI agent usage (CSV import)

CSV can be imported from the terminal without the web wizard. Useful for testing, verification, and automation.

**Command:**
```bash
php artisan import:csv <file> --account=<id|name> [--user=] [--mapping=] [--delimiter=] [--currency=]
```

- **file**: Path to CSV (absolute or relative to project root).
- **--account=**: Required. Account ID or account name for the chosen user.
- **--user=**: User ID or email. Default: first user (convenient for single-user dev).
- **--mapping=**: Name of a saved import mapping for this user. If omitted, column mapping and date/amount formats are auto-detected.
- **--delimiter=**: Override delimiter (e.g. `;`). If omitted, detected from the file.
- **--currency=**: Default `EUR` if not provided and not from mapping.
- **--date-format=**: Override date format (e.g. `d.m.Y` for DD.MM.YYYY, or `Y-m-d H:i:s` for Revolut-style datetimes).

**Examples:**
```bash
# Minimal: first user, account 1, auto-detect mapping
php artisan import:csv sample_data/csv/SLSP/SK9009000000005124514591_2025-01-01_2026-02-06.csv --account=1

# With saved mapping and explicit user
php artisan import:csv sample_data/csv/SLSP/SK9009000000005124514591_2025-01-01_2026-02-06.csv --account=1 --mapping="SLSP Main" --user=1

# SLSP (semicolon, DD.MM.YYYY dates) – use date-format if auto-detect is wrong
php artisan import:csv sample_data/csv/SLSP/SK9009000000005124514591_2025-01-01_2026-02-06.csv --account="Main Checking" --user=3 --delimiter=";" --date-format=d.m.Y

# Revolut (comma, datetime) – use date-format for Started/Completed Date columns
php artisan import:csv sample_data/csv/Revolut/LT683250013083708433_2025-01-16_2026-02-06_USD.csv --account="Main Checking" --user=3 --date-format="Y-m-d H:i:s"
```

AI agents can use this command for import testing and regression checks without the UI. Sample CSVs live under `sample_data/csv/` (e.g. Revolut, SLSP). With seeded DB use `--user=3` for the demo user (who has accounts); omit `--mapping` to use auto-detect.

### GoCardless CLI (bank data and sync)

All GoCardless flows can be driven from the terminal for testing and AI agents. When `GOCARDLESS_USE_MOCK` is true (default in local/development), the mock client and fixtures under `gocardless_bank_account_data/` (or `GOCARDLESS_MOCK_DATA_PATH`) are used. Use `--user=` for user ID or email; default is first user.

| Command | Purpose |
|--------|--------|
| `php artisan gocardless:institutions --country=gb` | List institutions for a country (mock: Revolut, SLSP, etc. from fixtures). |
| `php artisan gocardless:requisitions` | List requisitions and their linked accounts. |
| `php artisan gocardless:connect --institution=Revolut` | Create requisition and import all linked accounts (simulates callback flow). |
| `php artisan gocardless:import-account LT683250013083708433` | Import a single account by GoCardless account ID (e.g. from fixtures). |
| `php artisan gocardless:sync --account=1` | Sync transactions for one linked account. Options: `--no-update-existing`, `--force-max-date-range`. |
| `php artisan gocardless:sync-all` | Sync all GoCardless-linked accounts for the user. |
| `php artisan gocardless:delete-requisition {id}` | Delete a requisition by ID. |
| `php artisan gocardless:refresh-balance --account=1` | Refresh balance from GoCardless for one linked account. |
| `php artisan gocardless:retry-failures` | Retry unresolved GoCardless sync failures (existing command). |

**Example (mock, full flow):**
```bash
php artisan gocardless:institutions --country=sk
php artisan gocardless:connect --institution=SLSP --user=3
php artisan gocardless:requisitions --user=3
php artisan gocardless:sync --account=1 --user=3
```

Fixture data lives under `sample_data/gocardless_bank_account_data/` (Revolut, SLSP). Point `GOCARDLESS_MOCK_DATA_PATH` at that directory to use it.

## API Development Guidelines

### RESTful Endpoints
Follow Laravel resource conventions:
```php
// routes/api.php
Route::apiResource('transactions', TransactionController::class);

// Results in:
// GET    /api/transactions          index
// POST   /api/transactions          store
// GET    /api/transactions/{id}     show
// PUT    /api/transactions/{id}     update
// DELETE /api/transactions/{id}     destroy
```

### Response Format
Use Laravel API Resources for consistent responses:
```php
return response()->json([
    'data' => TransactionResource::collection($transactions),
    'meta' => [
        'total' => $transactions->total(),
        'per_page' => $transactions->perPage(),
    ],
]);
```

## Database Guidelines

### Migrations
1. **Naming**: Use descriptive names with timestamps
2. **Rollback**: Always define `down()` method
3. **Indexes**: Add appropriate indexes for query performance

```php
Schema::create('transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('bank_account_id')->constrained();
    $table->decimal('amount', 15, 2);
    $table->string('currency', 3);
    $table->date('transaction_date');
    $table->timestamps();
    
    $table->index(['bank_account_id', 'transaction_date']);
});
```

### Eloquent Models
1. Use proper relationships
2. Define fillable/guarded properties
3. Use scopes for common queries
4. Implement proper type casting

## Security Guidelines

### Critical Security Rules
1. **NEVER** include sensitive data in code:
   - API keys, passwords, tokens
   - Database credentials
   - Personal user data in examples

2. **Input Validation**: Always validate user input
   ```php
   $request->validate([
       'amount' => ['required', 'numeric', 'min:0'],
       'category_id' => ['required', 'exists:categories,id'],
   ]);
   ```

3. **Authorization**: Use Laravel policies
   ```php
   $this->authorize('update', $transaction);
   ```

4. **SQL Injection**: Use Eloquent or query builder, never raw queries
5. **XSS Prevention**: Escape output in Blade/React

### Dependency Security
- Flag any new dependency for security review
- Check for known vulnerabilities before suggesting packages
- Prefer well-maintained packages with recent updates

## PR & Review Guidelines

### Branch Naming
- Feature: `feature/add-spending-analytics`
- Bug Fix: `fix/transaction-import-error`
- Refactor: `refactor/optimize-transaction-queries`
- Docs: `docs/update-api-documentation`

### Commit Messages
Follow conventional commits:
```
feat: add spending analytics service
fix: correct currency conversion in transactions
refactor: optimize transaction query performance
docs: update API documentation for transactions
test: add tests for spending analytics
```

### PR Description Template
```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Refactoring
- [ ] Documentation

## Testing
- [ ] Unit tests pass
- [ ] Feature tests pass
- [ ] Manual testing completed

## Checklist
- [ ] Code follows project conventions
- [ ] Tests added/updated
- [ ] Documentation updated
- [ ] No security vulnerabilities introduced
```

## Component Development Guidelines

### Laravel Components
1. **Controllers**: Keep thin, delegate to services
2. **Services**: Business logic layer
3. **Repositories**: Data access layer (optional)
4. **Resources**: API response transformation
5. **Requests**: Input validation

### React Components
1. **Pages**: Inertia page components in `/resources/js/pages`
2. **Components**: Reusable UI in `/resources/js/components`
3. **Hooks**: Custom hooks in `/resources/js/hooks`
4. **Utils**: Helper functions in `/resources/js/utils`

### shadcn/ui Integration
```tsx
// Use existing UI components from shadcn/ui
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

// Follow shadcn/ui patterns for new components
```

## Environment-Specific Guidelines

### Development
```bash
# Start development servers
php artisan serve
npm run dev

# Database operations
php artisan migrate:fresh --seed
```

### Testing
```bash
# Run all tests
php artisan test
npm run test

# With coverage
php artisan test --coverage
```

### Production Build
```bash
# Build assets
npm run build

# Optimize Laravel
php artisan optimize
php artisan config:cache
php artisan route:cache
```

## Continuous Improvement

### Code Review Checklist
- [ ] Follows coding standards
- [ ] Includes appropriate tests
- [ ] No security vulnerabilities
- [ ] Performance considerations addressed
- [ ] Documentation updated
- [ ] Accessibility requirements met

### Performance Considerations
1. **Database**: Use eager loading to prevent N+1 queries
2. **Caching**: Implement caching for expensive operations
3. **Frontend**: Use React.memo for expensive components
4. **API**: Implement pagination for large datasets

### Accessibility
1. Use semantic HTML
2. Implement proper ARIA labels
3. Ensure keyboard navigation
4. Test with screen readers

## AI Assistant Behavior

### When Generating Code
1. **Always** include proper error handling
2. **Always** add TypeScript types
3. **Always** consider edge cases
4. **Prefer** composition over inheritance
5. **Prefer** functional components in React
6. **Follow** existing patterns in the codebase

### When Reviewing Code
1. Check for security vulnerabilities first
2. Verify proper input validation
3. Ensure tests are included
4. Look for performance issues
5. Verify accessibility compliance

### When Suggesting Changes
1. Explain the reasoning
2. Provide examples
3. Consider backward compatibility
4. Suggest incremental improvements
5. Respect existing architecture decisions

## Implementation Notes

### For Laravel Development
- Use dependency injection over facades when possible
- Implement repository pattern for complex data operations
- Use form requests for validation
- Leverage Laravel's built-in features (queues, events, etc.)

### For React Development
- Use controlled components
- Implement proper loading and error states
- Use Inertia's shared data for global state
- Leverage React Query for server state management

### For Database Design
- Normalize to 3NF unless performance requires denormalization
- Use UUID for public-facing IDs
- Implement soft deletes where appropriate
- Add proper indexes for query optimization

---

**Version**: 1.0.0  
**Last Updated**: 2024-12-27  
**Maintained By**: Spendly Development Team

*This document should be reviewed and updated regularly as the project evolves and best practices change.*
