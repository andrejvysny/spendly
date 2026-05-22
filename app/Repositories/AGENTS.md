# AGENTS.md - Repository Layer

## Overview

Data access abstraction layer. 23 interfaces, 21 implementations. Repository pattern (non-standard for Laravel).

## Structure

```
app/Repositories/
├── Contracts/
│   └── Repositories/
│       ├── UserRepositoryInterface.php
│       ├── TransactionRepositoryInterface.php
│       └── ... (23 total)
├── Concerns/
│   ├── WithUserScope.php      # User filtering
│   ├── WithOrdering.php       # Sort helpers
│   └── Paginating.php         # Pagination
├── BaseRepository.php
├── UserRepository.php
├── TransactionRepository.php
└── ... (21 implementations)
```

## Pattern

**Non-standard Laravel**: Uses repository interfaces instead of direct Eloquent.

```php
// In controller/service
public function __construct(
    private readonly TransactionRepositoryInterface $transactions
) {}

// Usage
$this->transactions->forUser($userId)->with('account')->get();
```

## Concerns (Traits)

| Concern         | Purpose                                                 |
| --------------- | ------------------------------------------------------- |
| `WithUserScope` | Automatic `user_id` filtering via `BelongsToUser` trait |
| `WithOrdering`  | `orderBy()`, `latest()` helpers                         |
| `Paginating`    | `paginate()`, `simplePaginate()` wrappers               |

## BaseRepository

Provides:

- `find($id)`
- `findOrFail($id)`
- `create(array $data)`
- `update($id, array $data)`
- `delete($id)`

## Creating New Repositories

1. Create interface in `app/Contracts/Repositories/`
2. Create implementation extending `BaseRepository`
3. Register in `RepositoryServiceProvider`
4. Type-hint interface in constructors

## Testing

```bash
php artisan test tests/Unit/Repositories/
```

Repository tests use in-memory SQLite with factories.
