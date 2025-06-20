
## 🧪 Testing

Spendly uses PHPUnit for backend testing. Tests are configured to run without coverage by default for faster execution, but you can enable coverage when needed.

### Running Tests

#### Without Coverage (Default - Fast)
```bash
# Using the provided script
./scripts/docker-test.sh

# Or directly with Docker
docker compose run test ./vendor/bin/phpunit
```

#### With Coverage Reports

**Text Coverage Report:**
```bash
./scripts/docker-test.sh --coverage-text
```

**HTML Coverage Report:**
```bash
./scripts/docker-test.sh --coverage-html
```

**Clover XML Coverage Report:**
```bash
./scripts/docker-test.sh --coverage-clover
```

### Test Script Options

The `docker-test.sh` script provides convenient options:

```bash
./scripts/docker-test.sh --help
```

**Available Options:**
- `--coverage` - Run tests with HTML coverage report
- `--coverage-text` - Run tests with text coverage report
- `--coverage-html` - Run tests with HTML coverage report (default)
- `--coverage-clover` - Run tests with Clover XML coverage report
- `--help, -h` - Show help message

### Coverage Reports

When running with coverage, reports are generated in:
- **HTML**: `coverage/html/index.html` - Interactive web report
- **Clover XML**: `coverage/clover.xml` - For CI/CD integration
- **Text**: Console output - Quick summary

### Test Structure

```
tests/
├── Feature/          # Feature tests (HTTP requests, database)
│   ├── Auth/        # Authentication tests
│   └── Settings/    # Settings functionality tests
└── Unit/            # Unit tests (isolated components)
    ├── Controllers/ # Controller logic tests
    └── Services/    # Service layer tests
```

### Writing Tests

- **Feature Tests**: Test complete user workflows and HTTP endpoints
- **Unit Tests**: Test individual classes and methods in isolation
- **Database Tests**: Use SQLite in-memory database for fast execution
