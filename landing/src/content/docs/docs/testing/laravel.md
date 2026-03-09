---
title: Laravel Testing
description: PHPUnit testing patterns for the Spendly backend — unit, feature, and integration tests.
---

## Test Suites

### 1. Unit Tests

**Scope**: Individual classes and methods in isolation.

- Mock external services using Mockery
- Use `RefreshDatabase` trait for model tests with in-memory SQLite

```bash
php artisan test --testsuite=Unit
```

### 2. Feature Tests

**Scope**: HTTP endpoints via Laravel's testing helpers.

- Hit endpoints with `$this->get()`, `$this->post()`
- Verify JSON responses, views, or database states
- SQLite configured in `phpunit.xml`

```bash
php artisan test --testsuite=Feature
```

### 3. Integration Tests

**Scope**: Collaboration between services and repositories without HTTP layer.

- Test service classes that use multiple repositories
- Use in-memory or SQLite connections

### Docker Services

Each test suite can run in Docker:

```bash
docker compose run test ./vendor/bin/phpunit --testsuite=Unit
docker compose run test ./vendor/bin/phpunit --testsuite=Feature
```

## Best Practices

- Use `@group` annotations to tag and run subsets of tests
- Mock external services (GoCardless, mail, queues) via Laravel fakes
- Use factories for test data generation
- Keep tests fast — in-memory SQLite for database tests
- Test authorization and validation separately from business logic

## PHPUnit Configuration

Key settings in `phpunit.xml`:

- Testing environment variables
- In-memory SQLite database
- Test suites: `Unit`, `Feature`

```bash
# Generate coverage report
./vendor/bin/phpunit --coverage-html coverage

# Run specific test
php artisan test --filter=GoCardlessServiceTest
```
