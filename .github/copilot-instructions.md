# Copilot Instructions for Spendly

## Project Overview
Spendly is a Laravel-React personal finance tracker with GoCardless bank integration, transaction rule engine, and CSV import system. The codebase follows service-oriented architecture with clear separation between business logic and data access.

## Essential Architecture Patterns

### Service Layer Architecture
- **Controllers** are thin - delegate complex logic to services (`app/Services/`)
- **Services** contain business logic (`GoCardlessService`, `TransactionRulePipeline`) 
- **Repositories** handle data access (`app/Repositories/`)
- **Models** use Eloquent relationships and scopes effectively

### Transaction Rule Engine
- Rules processed via Laravel Pipeline pattern (`TransactionRulePipeline`)
- Event-driven execution on transaction create/update (`ProcessRulesJob`)
- Rules have conditions (amount/IBAN/description) and actions (set category/add tag)
- Pipeline processes rules by priority order, respecting stop conditions

### CSV Import System  
- Multi-step wizard: upload → configure → preview → process
- Field auto-mapping using pattern matching (`FieldMappingService.ts`)
- Duplicate detection via configurable identifiers
- Failure recovery with manual field mapping interface
- Import mappings saved/reusable per bank

### GoCardless Integration
- Token management with automatic refresh (`TokenManager`)
- Service provider pattern for dependency injection (`GoCardlessServiceProvider`)
- Paginated transaction sync with date range calculation
- Account mapping between GoCardless and local models

## Development Workflows

### Backend Commands
```bash
# Tests with coverage
php artisan test --coverage

# Code quality
./vendor/bin/phpstan analyse
./vendor/bin/pint

# Database operations  
php artisan migrate:fresh --seed
```

### Frontend Commands
```bash
# Development
npm run dev

# Build & type checking
npm run build
npm run types

# Linting & formatting
npm run lint
npm run format:check
```

### Docker Development
```bash
# Full development setup
./scripts/dev.sh

# Run tests in container
./scripts/test.sh --coverage
```

## Key File Patterns

### Laravel Structure
- **Feature Tests**: `tests/Feature/` - test full request cycles
- **Unit Tests**: `tests/Unit/` - test individual classes/methods
- **Seeders**: Create realistic demo data with relationships
- **Migrations**: Use proper foreign keys and indexes
- **Policies**: Authorization logic for user-owned resources

### React Structure  
- **Pages**: Inertia.js page components in `resources/js/pages/`
- **Components**: Reusable UI in `resources/js/components/`
- **Types**: TypeScript interfaces in `resources/js/types/`
- **Services**: Business logic in `.ts` files (e.g., `FieldMappingService`)

### Critical Configuration
- **Inertia.js**: Bridges Laravel backend with React frontend
- **shadcn/ui**: UI component library - prefer existing components
- **Tailwind CSS**: Utility-first styling
- **Laravel Octane**: High-performance application server

## Project-Specific Conventions

### Database Design
- User-scoped data: always include `user_id` foreign key
- Soft deletes on user-facing resources
- JSON metadata columns for flexible data storage
- Composite indexes for common query patterns

### API Responses
```php
// Consistent success response
return response()->json([
    'success' => true,
    'data' => $data,
    'message' => 'Operation completed'
]);

// Error response with logging
Log::error('Operation failed', ['context' => $context]);
return response()->json([
    'success' => false, 
    'error' => 'User-friendly message'
], 500);
```

### React Component Patterns
```tsx
// Page component with Inertia props
interface PageProps {
    transactions: Transaction[];
    filters: FilterState;
}

export default function TransactionsIndex({ transactions, filters }: PageProps) {
    const { data, setData, post } = useForm(initialState);
    // Component logic
}
```

### Testing Requirements
- **Unit tests** for services and repositories
- **Feature tests** for API endpoints 
- **Mock external services** (GoCardless) in tests
- **Database transactions** for test isolation

## Integration Points

### GoCardless Flow
1. Token management handles authentication
2. Service layer orchestrates API calls  
3. Mapper transforms external data to local models
4. Repository persists with proper relationships

### Rule Engine Integration
- Triggered by `TransactionCreated`/`TransactionUpdated` events
- Jobs process rules asynchronously via queues
- Pipeline pattern enables complex rule combinations
- Audit logging tracks all rule executions

### Import System Dependencies
- Account selection determines transaction ownership
- Field mapping service handles bank format variations  
- Duplicate detection prevents data corruption
- Failure recovery maintains import state for retry

When modifying these systems, ensure backward compatibility and maintain the separation of concerns established in the architecture.
