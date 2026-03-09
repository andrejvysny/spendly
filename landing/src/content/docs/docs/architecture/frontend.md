---
title: Frontend Architecture
description: React 19 + TypeScript frontend with Inertia.js, shadcn/ui, and Tailwind CSS.
---

## Stack

- **React 19** with TypeScript
- **Inertia.js** for seamless server-side routing
- **Tailwind CSS** for responsive styling
- **shadcn/ui + Radix UI** for UI components (46+ components)
- **React Hook Form + Zod** for form validation
- **Axios** for API communication

## Data Flow

```
Laravel Controller → Inertia → React Component → User Interaction → Axios API → Laravel Backend
```

Server-side data is passed as page props via Inertia. Client-side API calls use Axios for mutations and real-time updates.

## Directory Structure

```
resources/js/
├── pages/          # Inertia page components (dashboard, accounts, transactions, etc.)
├── components/
│   ├── ui/         # 46+ shadcn/ui components
│   ├── accounts/   # Account-specific components
│   ├── transactions/
│   ├── rules/
│   ├── charts/
│   └── Import/
├── hooks/          # Custom React hooks
├── layouts/        # Page layouts
├── types/          # TypeScript type definitions
├── utils/          # Utility functions
└── lib/            # Shared library code
```

### Path Alias

`@/` maps to `resources/js/` for clean imports:

```typescript
import { Button } from '@/components/ui/button';
```

## Key Patterns

### Inertia Page Components

Pages receive data from Laravel controllers as props:

```typescript
import { Head, usePage } from '@inertiajs/react';

interface Props {
    transactions: Transaction[];
    filters: FilterOptions;
}

export default function TransactionsPage({ transactions, filters }: Props) {
    return (
        <>
            <Head title="Transactions" />
            {/* ... */}
        </>
    );
}
```

### State Management

- **Server state** via Inertia page props (automatically synced on navigation)
- **Local state** with React `useState` for UI interactions
- **Real-time updates** via API calls + Inertia page refresh
- **Optimistic updates** for better UX during mutations

### Form Handling

Forms use React Hook Form with Zod validation:

```typescript
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const schema = z.object({
    amount: z.string().min(1),
    description: z.string().min(1),
    date: z.string(),
});
```

## Key Pages

| Page         | Route           | Description                              |
| ------------ | --------------- | ---------------------------------------- |
| Dashboard    | `/`             | Overview with charts and summaries       |
| Accounts     | `/accounts`     | Account list and management              |
| Transactions | `/transactions` | Transaction list with filters and search |
| Analytics    | `/analytics`    | Spending reports and visualizations      |
| Import       | `/import`       | CSV import wizard                        |
| Rules        | `/rules`        | Rule engine management                   |
| Recurring    | `/recurring`    | Recurring payment detection              |
| Settings     | `/settings`     | Application settings                     |

## Design Principles

- **Responsive** — Mobile-first design with collapsible sections
- **Accessible** — Proper ARIA labels, keyboard navigation
- **Progressive disclosure** — Details shown on demand
- **Consistent** — shadcn/ui components throughout
- **Type-safe** — Full TypeScript coverage with interfaces for all props
