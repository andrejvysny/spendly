# Spendly - Project Context & Guidelines

Spendly is an open-source, self-hosted personal finance tracker built with Laravel 12 and React 19. It focuses on privacy, local data storage, and automated financial management through bank integrations and a powerful rule engine.

## 🚀 Technology Stack

- **Backend**: PHP 8.3, [Laravel 12.x](https://laravel.com), [Inertia.js](https://inertiajs.com) (v2.0), [Octane](https://laravel.com/docs/octane) (Roadrunner/Swoole), [Sanctum](https://laravel.com/docs/sanctum).
- **Frontend**: [React 19.x](https://react.dev), [TypeScript](https://www.typescriptlang.org), [Vite](https://vitejs.dev), [Tailwind CSS v4](https://tailwindcss.com), [Radix UI](https://www.radix-ui.com) / [Shadcn UI](https://ui.shadcn.com).
- **Database**: SQLite (default for self-hosting), supports MySQL and PostgreSQL.
- **Integrations**: [GoCardless API](https://gocardless.com) for bank account synchronization.
- **Testing**: [PHPUnit](https://phpunit.de) (Backend), [Jest](https://jestjs.io) + [React Testing Library](https://testing-library.com/docs/react-testing-library/intro/) (Frontend).
- **Infrastructure**: [Docker](https://www.docker.com) & Docker Compose.

## 🛠️ Key Commands

### Development
- `composer dev`: Starts the full development stack (Artisan server, Vite, Queue listener, and Pail logs).
- `npm run dev`: Starts the Vite development server (included in `composer dev`).
- `php artisan serve`: Starts the Laravel development server.

### Backend (PHP)
- `php artisan test`: Runs all PHPUnit tests.
- `php artisan test --filter=ClassName`: Runs a specific test class.
- `composer phpstan`: Runs static analysis (Level 9).
- `composer pint`: Applies PHP code style formatting (PSR-12).

### Frontend (JS/TS)
- `npm test`: Runs Jest tests (default watch mode).
- `npm run lint`: Runs ESLint with auto-fix.
- `npm run types`: Runs TypeScript type checking (`tsc --noEmit`).
- `npm run format`: Applies Prettier formatting.

### Docker
- `docker compose up -d`: Starts the production/development environment in containers.
- `./scripts/test.sh`: Runs the full test suite within a Docker container.

## 🏗️ Architecture

### Backend (`app/`)
Following the **Controllers → Services → Repositories** pattern with interfaces.
- **Controllers**: Thin, responsible for request handling and Inertia rendering.
- **Services**: Contain core business logic (e.g., `GoCardless`, `RuleEngine`, `TransactionImport`).
- **Repositories**: Handle data persistence and retrieval, implementing contracts from `app/Contracts/Repositories/`.
- **Models**: Eloquent models with a `BelongsToUser` trait for multi-tenancy.
- **Rule Engine**: A complex subsystem for automating transaction categorization and actions.

### Frontend (`resources/js/`)
- **Pages**: Inertia-driven React components located in `resources/js/pages/`.
- **Components**: UI components (Radix/Shadcn) in `resources/js/components/ui/` and domain-specific components in `resources/js/components/`.
- **Path Alias**: `@/` maps to `resources/js/`.
- **Forms**: Powered by `react-hook-form` and `zod` for validation.

## 📏 Development Conventions

### PHP/Laravel
- **Strict Types**: Every PHP file must begin with `declare(strict_types=1);`.
- **Validation**: Use Form Requests for all input validation.
- **DI**: Prefer Dependency Injection over Laravel Facades.
- **Formatting**: Adhere to PSR-12 using `laravel/pint`.

### React/TypeScript
- **Components**: Use functional components with TypeScript interfaces for props.
- **Styles**: Use Tailwind CSS (v4) for all styling.
- **Formatting**: Prettier with `singleQuotes`, `tabWidth: 4`, and `printWidth: 150`.
- **Imports**: Use `prettier-plugin-organize-imports` for consistent import ordering.

### Commits
- Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`).

## 🧪 Testing Strategy

- **Backend**: Feature tests for HTTP/API endpoints and Unit tests for isolated logic. Uses an in-memory SQLite database for speed.
- **Frontend**: Component and hook testing using Jest and React Testing Library.
- **Integration**: Comprehensive integration tests for the `Import Wizard` and `Rule Engine`.

## 🤖 AI Agent Guidance (Project-Specific)

This repository includes specialized sub-agent routing rules in `.cursor/rules/subagents-routing.mdc`. When working on specific domains, prioritize the following contexts:
- **Import Wizard**: `app/Http/Controllers/Import/`, `app/Services/TransactionImport/`.
- **GoCardless**: `app/Services/GoCardless/`, `app/Http/Controllers/Settings/BankDataController.php`.
- **Rule Engine**: `app/Services/RuleEngine/`, `app/Models/RuleEngine/`.
- **Frontend**: `resources/js/**`.

## 🔒 Security & Data
- All financial data is stored locally (self-hosted).
- Sensitive tokens (GoCardless) are stored on the `User` model.
- Transaction deduplication uses SHA256 fingerprinting.
