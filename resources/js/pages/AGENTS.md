# AGENTS.md - Frontend Pages

## Overview

Inertia.js page components. 41 TypeScript files across 8 domains. File path maps to route name.

## Structure

```
resources/js/pages/
├── accounts/
│   ├── index.tsx
│   └── show.tsx
├── budgets/
│   └── index.tsx
├── categories/
│   └── index.tsx
├── import/
│   ├── index.tsx
│   ├── configure.tsx
│   ├── map.tsx
│   ├── clean.tsx
│   └── confirm.tsx
├── rules/
│   └── index.tsx
├── settings/
│   ├── profile.tsx
│   └── bank_data.tsx
├── transactions/
│   └── index.tsx
├── dashboard.tsx
└── welcome.tsx
```

## Inertia Mapping

Page name must match route render:

```php
// routes/web.php
Route::get('/transactions', [TransactionController::class, 'index']);

// TransactionController.php
return Inertia::render('transactions/index', [...]);
// Maps to: resources/js/pages/transactions/index.tsx
```

## Page Component Pattern

```tsx
import { Head } from '@inertiajs/react';

interface Props {
    transactions: Transaction[];
    filters: FilterState;
}

export default function TransactionsIndex({ transactions, filters }: Props) {
    // Component logic
    return (
        <>
            <Head title="Transactions" />
            {/* JSX */}
        </>
    );
}
```

## Key Directories

| Directory   | Purpose                         |
| ----------- | ------------------------------- |
| `import/`   | CSV import wizard (5-step flow) |
| `rules/`    | Transaction rule management     |
| `settings/` | User settings, bank connections |

## Data Flow

1. Laravel controller queries data
2. Passes to `Inertia::render('page/name', $props)`
3. Inertia hydrates React component with props
4. Client-side navigation via `router.visit()`

## Conventions

- **Default export**: Pages must export default component
- **Props interface**: Name it `Props` or `PageProps`
- **Head**: Use `Head` from `@inertiajs/react` for titles
- **Layouts**: Wrap in layout via `page.layout = (page) => <Layout>{page}</Layout>`

## Testing

Jest tests for page logic in separate `.test.tsx` files (not colocated).
