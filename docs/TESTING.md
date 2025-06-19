# Testing Setup

This project includes a comprehensive testing setup with Docker support for code coverage.

## Prerequisites

- Docker and Docker Compose installed
- Git

## Quick Start

### 1. Set up Development Environment

```bash
./docker-dev.sh
```

This script will:
- Build all Docker containers
- Install PHP and Node.js dependencies
- Build frontend assets
- Set up Laravel configuration
- Set proper permissions

### 2. Run Tests with Coverage

```bash
./docker-test.sh
```

This script will:
- Build the test container with Xdebug support
- Run all tests with code coverage
- Generate coverage reports

## Coverage Reports

After running tests, coverage reports are available in:

- **HTML Report**: `coverage/html/index.html` - Interactive web interface
- **Clover XML**: `coverage/clover.xml` - For CI/CD integration
- **Text Report**: `coverage/coverage.txt` - Plain text summary

## Manual Testing Commands

### Run All Tests
```bash
docker-compose run --rm test php artisan test
```

### Run Specific Test Suite
```bash
# Unit tests only
docker-compose run --rm test php artisan test --testsuite=Unit

# Feature tests only
docker-compose run --rm test php artisan test --testsuite=Feature
```

### Run Specific Test File
```bash
docker-compose run --rm test php artisan test tests/Unit/Services/GoCardlessServiceTest.php
```

### Run Tests with Coverage
```bash
docker-compose run --rm test php artisan test --coverage
```

### Run Tests with Verbose Output
```bash
docker-compose run --rm test php artisan test --verbose
```

## Test Configuration

### Environment Variables

The test environment is configured with the following settings:

- `APP_ENV=testing`
- `LOG_CHANNEL=null` (suppresses logging during tests)
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=:memory:` (in-memory database)
- `CACHE_STORE=array`
- `SESSION_DRIVER=array`
- `QUEUE_CONNECTION=sync`
- `XDEBUG_MODE=coverage`

### PHPUnit Configuration

The `phpunit.xml` file includes:

- Coverage configuration for the `app` directory
- Exclusions for Console, Exceptions, and Providers directories
- Multiple report formats (HTML, Clover XML, Text)
- Test suite organization (Unit and Feature)

## Troubleshooting

### Xdebug Issues

If you encounter Xdebug-related warnings:

1. Ensure the test container is built with Xdebug:
   ```bash
   docker-compose build test
   ```

2. Check Xdebug is enabled:
   ```bash
   docker-compose run --rm test php -m | grep xdebug
   ```

3. Verify Xdebug mode:
   ```bash
   docker-compose run --rm test php -i | grep xdebug.mode
   ```

### Permission Issues

If you encounter permission issues:

```bash
docker-compose run --rm cli chmod -R 775 storage bootstrap/cache
```

### Database Issues

The tests use SQLite in-memory database. If you need to use a different database:

1. Update the test environment variables in `compose.yml`
2. Ensure the database driver is installed in the test container
3. Update `phpunit.xml` environment variables

## Continuous Integration

For CI/CD pipelines, the coverage reports can be integrated using:

- **Clover XML**: Upload to services like Codecov, Coveralls, or SonarQube
- **HTML Report**: Deploy to a static hosting service for team review
- **Text Report**: Parse for coverage thresholds in CI scripts

## Development Workflow

1. Write tests in the appropriate test suite directory
2. Run tests locally: `./docker-test.sh`
3. Check coverage reports for uncovered code
4. Commit and push changes
5. CI/CD will run tests automatically

## Test Structure

- **Unit Tests** (`tests/Unit/`): Test individual classes and methods in isolation
- **Feature Tests** (`tests/Feature/`): Test complete features and HTTP endpoints
- **Test Helpers**: Common test utilities and factories in `tests/TestCase.php` 