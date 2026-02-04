# Spendly Project Context

## Project Overview
Spendly is an open-source personal finance tracker application. It allows users to manage finances, analyze spending patterns, and maintain budgets. It features integration with GoCardless for bank account imports and a custom Rule Engine for transaction categorization.

## Tech Stack

### Backend
*   **Framework:** Laravel 12.x
*   **Language:** PHP 8.3+ (Strict Types Required)
*   **Database:** SQLite (default/dev), MySQL, PostgreSQL. Eloquent ORM used.
*   **API:** RESTful, using Laravel API Resources for standardized responses.
*   **Authentication:** Laravel Sanctum.

### Frontend
*   **Framework:** React 19.x
*   **Architecture:** Monolith via Inertia.js 2.0.
*   **Language:** TypeScript 5.x
*   **Styling:** Tailwind CSS 4.x
*   **UI Library:** Shadcn UI (Radix UI + Lucide React).
*   **Build Tool:** Vite.

### Infrastructure
*   **Containerization:** Docker & Docker Compose.

## Key Commands

### Setup & Installation
*   **One-line Setup:** `scripts/setup.sh`
*   **Manual Setup:**
    1.  Copy `.env.example` to `.env`
    2.  `docker compose run --rm cli composer install`
    3.  `npm install`
    4.  `docker compose run --rm cli php artisan key:generate`
    5.  `docker compose run --rm cli php artisan migrate`

### Development
*   **Start Dev Server:** `docker compose run --rm cli composer run dev` (Runs Laravel, Queue, Pail, and Vite concurrently).
*   **Frontend Build:** `npm run build`
*   **Database Reset:** `docker compose run --rm cli php artisan migrate:fresh --seed`

### Testing
*   **Backend (PHP):** `docker compose run --rm cli php artisan test` (or `docker compose run --rm cli composer run test`)
    *   *Coverage:* `docker compose run --rm cli php artisan test --coverage`
    *   *Filter:* `docker compose run --rm cli php artisan test --filter=TestName`
*   **Frontend (JS):** `npm run test` (Jest)

### Code Quality
*   **PHP Static Analysis:** `docker compose run --rm cli composer run phpstan` (PHPStan)
*   **PHP Formatting:** `docker compose run --rm cli composer run pint` (Laravel Pint)
*   **JS Linting:** `npm run lint` (ESLint)
*   **JS Formatting:** `npm run format:check` (Prettier)
*   **JS Type Check:** `npm run types` (TSC)

## Architecture & Conventions

### Directory Permissions
*   **Modifiable:** `app`, `resources/js`, `resources/css`, `routes`, `database`, `tests`, `config` (carefully), `docs`.
*   **Protected (Do Not Touch):** `vendor`, `node_modules`, `public` (assets), `storage`, `bootstrap`, `.docker`, `.github`.
*   **Critical (Ask First):** `.env`, `composer.json`, `package.json`, Docker configs, Migrations (after initial creation).

### Backend (Laravel)
*   **Strict Types:** All PHP files must start with `declare(strict_types=1);`.
*   **Layered Architecture:**
    1.  **Controllers:** Thin, delegate to Services.
    2.  **Services:** Contain business logic.
    3.  **Repositories:** Handle data access (optional but encouraged for complex ops).
    4.  **Resources:** Transform data for API responses.
*   **Naming:**
    *   Classes: `PascalCase`
    *   Methods/Variables: `camelCase`
    *   Constants: `UPPER_SNAKE_CASE`
    *   Tables: `snake_case` (plural)

### Frontend (React/Inertia)
*   **Structure:**
    *   `resources/js/pages`: Inertia pages.
    *   `resources/js/components`: Reusable UI (Shadcn patterns).
    *   `resources/js/hooks`: Custom hooks.
    *   `resources/js/utils`: Helpers.
*   **Naming:**
    *   Components/Files: `PascalCase`
    *   Functions/Hooks: `camelCase`
*   **Integration:** Use `import { Component } from '@/components/ui/...'` for Shadcn components.

### Security
*   **Validation:** Always use Form Requests or `$request->validate()`.
*   **Authorization:** Use Laravel Policies (`$this->authorize()`).
*   **Data:** Never expose credentials or raw user data.
*   **SQL:** Use Eloquent/Query Builder (No raw SQL without binding).

## Documentation Sources
*   `README.md`: General overview.
*   `AGENTS.md`: Detailed rules for AI agents (Source of Truth).
*   `docs/`: Detailed guides (API, Deployment, Development).
