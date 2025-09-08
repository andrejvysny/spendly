# Contributing to Spendly

Thank you for your interest in contributing to Spendly! We welcome contributions from the community and are grateful for any help you can provide.

## Table of Contents

- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Making Changes](#making-changes)
- [Submitting Changes](#submitting-changes)
- [Style Guidelines](#style-guidelines)
- [Testing](#testing)
- [Documentation](#documentation)
- [Community](#community)

## Getting Started

### Prerequisites

- PHP 8.3 or higher
- Node.js 20 or higher
- Composer

Or another option:

- Docker (recommended)

### Development Setup

1. **Fork the repository** on GitHub
2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/your-username/spendly.git
   cd spendly
   ```

3. **Set up the environment**:
   ```bash
   cp .env.example .env
   ```

4. **Using Docker (Recommended)**:
   ```bash
   docker compose up -d
   docker compose exec cli composer install
   docker compose exec cli php artisan key:generate
   docker compose exec cli php artisan migrate --seed
   docker compose exec node npm install
   docker compose exec node npm run dev
   ```

5. **Or install locally**:
   ```bash
   composer install
   php artisan key:generate
   php artisan migrate --seed
   npm install
   npm run dev & # Start the frontend build process in the background - or use another terminal without &
   php artisan serve & # Start the backend server in the background - or use another terminal without &
   ```

See [DEVELOPMENT.md](docs/DEVELOPMENT.md) for detailed setup instructions.

## Making Changes

### Branching Strategy

- `main` - Production-ready code
- `develop` - Development branch for integration
- Feature branches: `feature/github-issue-id`
- Bug fixes: `fix/github-issue-id`
- Documentation: `docs/topic-name`

### Workflow

1. **Create a feature branch** from `develop`:
   ```bash
   git checkout develop
   git pull origin develop
   git checkout -b feature/github-issue-id
   ```

2. **Make your changes** following our style guidelines

3. **Test your changes** thoroughly:
   ```bash
   # Run PHP tests
   ./vendor/bin/phpunit
   
   # Run frontend tests
   npm test
   
   # Check code style
   vendor/bin/pint
   npm run lint
   ```

4. **Commit your changes** with a clear message:
Refer to the [Commit Message Format](#commit-message-format) section for guidelines.
   ```bash
   git add .
   git commit -m "feat: add transaction categorization feature"
   ```

5. **Push to your fork**:
   ```bash
   git push origin feature/your-feature-name
   ```

## Submitting Changes

### Pull Requests

1. **Create a Pull Request** against the `develop` branch
2. **Fill out the PR template** completely
3. **Ensure all checks pass** (tests, linting, etc.)
4. **Request review** from maintainers
5. **Address feedback** promptly

### Pull Request Guidelines

- Keep PRs focused and atomic
- Include tests for new functionality
- Update documentation as needed
- Add changelog entry for user-facing changes
- Reference any related issues

### Commit Message Format

We follow the [Conventional Commits](https://conventionalcommits.org/) specification:

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `test`: Adding or modifying tests
- `chore`: Maintenance tasks

**Examples:**
```
feat(transactions): add CSV import functionality
fix(auth): resolve login redirect issue
docs(api): update endpoint documentation
```

## Style Guidelines

### PHP/Laravel

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Use Laravel conventions and best practices
- Run `vendor/bin/pint` before committing
- Add type hints where possible
- Write descriptive variable and method names

### React/TypeScript

- Use TypeScript for all new components
- Follow React best practices and hooks patterns
- Use functional components with hooks
- Implement proper error boundaries
- Run `npm run lint` and `npm run format` before committing

### Database

- Write reversible migrations
- Use descriptive column names
- Add proper indexes for performance
- Include foreign key constraints
- Document complex queries

## Testing

### Backend Testing

- Write unit tests for all new functionality
- Use Feature tests for API endpoints
- Mock external services (GoCardless, etc.)
- Maintain test coverage above 80%

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit --testsuite=Feature

# Generate coverage report
./vendor/bin/phpunit --coverage-html coverage
```

### Frontend Testing

- Write tests for new components
- Test user interactions and edge cases
- Mock API calls in tests

```bash
# Run frontend tests
npm test

# Run tests in watch mode
npm run test:watch
```

## Documentation

### Code Documentation

- Add PHPDoc blocks for complex classes and methods
- Use TypeScript interfaces and types
- Document complex business logic
- Keep README.md up to date

### API Documentation

- Document all API endpoints
- Include request/response examples
- Specify authentication requirements
- Update OpenAPI specifications
- Use Swagger UI for interactive API documentation

### User Documentation

- Update user guides for new features
- Add screenshots for UI changes
- Keep installation instructions current

## Financial Domain Guidelines

### Data Accuracy

- Use appropriate decimal precision for monetary values
- Implement proper rounding strategies
- Validate currency codes (ISO 4217)
- Handle timezone considerations for transactions

### Security Considerations

- Never log sensitive financial data
- Implement proper input validation
- Use parameterized queries
- Follow PCI DSS guidelines where applicable

### Performance

- Optimize database queries for large datasets
- Implement proper caching strategies
- Consider pagination for transaction lists
- Monitor API response times

## Community

### Getting Help

- **Discussions**: Use GitHub Discussions for questions
- **Issues**: Report bugs and feature requests on GitHub
- **Email**: Contact maintainers at vysnyandrej@gmail.com


## Release Process

1. Features are merged to `develop`
2. Regular releases are created from `develop` to `main`
3. Hotfixes go directly to `main` and are backported to `develop`
4. Semantic versioning is used for all releases

## Questions?

Don't hesitate to ask questions! We're here to help:

- Open an issue for bugs or feature requests
- Start a discussion for general questions

Thank you for contributing to Spendly! 🚀
