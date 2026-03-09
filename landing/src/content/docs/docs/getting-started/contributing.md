---
title: Contributing
description: How to contribute to Spendly — branching, style guidelines, and PR workflow.
---

Thank you for your interest in contributing to Spendly!

## Getting Started

### Prerequisites

- PHP 8.3+ or Docker
- Node.js 20+
- Composer

### Setup

1. **Fork the repository** on GitHub
2. **Clone your fork**:
    ```bash
    git clone https://github.com/your-username/spendly.git
    cd spendly
    cp .env.example .env
    ```
3. **With Docker**:
    ```bash
    docker compose up -d
    docker compose exec cli composer install
    docker compose exec cli php artisan key:generate
    docker compose exec cli php artisan migrate --seed
    docker compose exec node npm install
    docker compose exec node npm run dev
    ```
4. **Or locally**:
    ```bash
    composer install
    php artisan key:generate
    php artisan migrate --seed
    npm install
    npm run dev &
    php artisan serve &
    ```

## Branching Strategy

- `main` — Production-ready code
- `develop` — Development branch for integration
- Feature branches: `feature/github-issue-id`
- Bug fixes: `fix/github-issue-id`
- Documentation: `docs/topic-name`

## Workflow

1. **Create a feature branch** from `develop`:

    ```bash
    git checkout develop
    git pull origin develop
    git checkout -b feature/github-issue-id
    ```

2. **Make your changes** following the style guidelines below

3. **Test your changes**:

    ```bash
    ./vendor/bin/phpunit
    npm test
    vendor/bin/pint
    npm run lint
    ```

4. **Commit** with conventional commit messages:

    ```bash
    git commit -m "feat: add transaction categorization feature"
    ```

5. **Push and create a PR** against `develop`

## Commit Message Format

We follow [Conventional Commits](https://conventionalcommits.org/):

```
<type>[optional scope]: <description>
```

**Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

**Examples:**

```
feat(transactions): add CSV import functionality
fix(auth): resolve login redirect issue
docs(api): update endpoint documentation
```

## Style Guidelines

### PHP/Laravel

- [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Run `vendor/bin/pint` before committing
- Add type hints where possible
- Use Laravel conventions and best practices

### React/TypeScript

- TypeScript for all new components
- Functional components with hooks
- Run `npm run lint` and `npm run format` before committing

### Database

- Write reversible migrations
- Use descriptive column names
- Add proper indexes for performance
- Include foreign key constraints

## Testing

### Backend

- Write unit tests for all new functionality
- Use Feature tests for API endpoints
- Mock external services (GoCardless, etc.)

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --testsuite=Feature
./vendor/bin/phpunit --coverage-html coverage
```

### Frontend

```bash
npm test
npm run test:watch
```

## Pull Request Guidelines

- Keep PRs focused and atomic
- Include tests for new functionality
- Update documentation as needed
- Reference related issues
- Ensure all checks pass

## Financial Domain Guidelines

- Use appropriate decimal precision for monetary values
- Validate currency codes (ISO 4217)
- Handle timezone considerations for transactions
- Never log sensitive financial data

## Release Process

1. Features are merged to `develop`
2. Regular releases from `develop` to `main`
3. Hotfixes go directly to `main` and are backported to `develop`
4. Semantic versioning for all releases
