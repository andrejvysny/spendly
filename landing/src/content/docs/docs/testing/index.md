---
title: Testing Overview
description: Testing strategy and setup for Spendly — PHPUnit backend and Jest frontend.
---

## Overview

Spendly uses PHPUnit for backend testing and Jest with React Testing Library for frontend testing. Tests run without coverage by default for speed.

## Running Tests

### Backend (PHPUnit)

```bash
# All tests
php artisan test

# Specific test class
php artisan test --filter=ClassName

# Using Docker
docker compose run test ./vendor/bin/phpunit

# With coverage
./scripts/docker-test.sh --coverage-html
./scripts/docker-test.sh --coverage-text
./scripts/docker-test.sh --coverage-clover
```

### Frontend (Jest)

```bash
npm run test           # Watch mode
npm test -- path/to/file  # Single file
```

## Test Structure

```
tests/
├── Feature/          # HTTP/DB integration tests
│   ├── Auth/
│   └── Settings/
└── Unit/             # Isolated component tests
    ├── Controllers/
    └── Services/
```

```
resources/js/
└── **/*.test.tsx     # Jest tests co-located with components
```

## Test Database

Backend tests use SQLite in-memory database for fast execution. Test factories provide realistic test data.

## Writing Tests

### Feature Tests

Test complete user workflows and HTTP endpoints. Use Laravel's HTTP testing helpers (`$this->get()`, `$this->post()`).

### Unit Tests

Test individual classes and methods in isolation. Use Mockery for external service mocking.

### Frontend Tests

Test React components with React Testing Library. Use `@testing-library/user-event` for user interaction simulation.

## Coverage Reports

| Format     | Output                     | Use Case               |
| ---------- | -------------------------- | ---------------------- |
| HTML       | `coverage/html/index.html` | Interactive web report |
| Clover XML | `coverage/clover.xml`      | CI/CD integration      |
| Text       | Console                    | Quick summary          |

See [Laravel Testing](/docs/testing/laravel/) and [React Testing](/docs/testing/react/) for detailed patterns and examples.
