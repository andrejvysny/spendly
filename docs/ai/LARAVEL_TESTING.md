## Summary

* This guide shows how to implement **Unit**, **Feature**, **Functional**, **Integration**, and **E2E** tests for a **Laravel** app using **PHPUnit** and **Docker Compose**.
* Each test suite has its own **scope**, **dependencies**, **Docker service**, and **commands**.

## Prerequisites

* **Docker** and **Docker Compose** installed locally.
* A **Laravel 12** project with `tests/Unit`, `tests/Feature`, optional `tests/Integration`, and **Dusk** set up.
* **phpunit.xml** configured for the **testing** environment ([laravel.com](https://laravel.com/docs/12.x/testing?utm_source=chatgpt.com)).

## Docker Compose Setup

Define services for your app, database, and each test suite in `docker-compose.yml`:

```yaml
version: "3.8"
services:
    app:
        image: laravel-app
        build: ../..
        volumes:
            - ./:/var/www/html
        ports:
            - "8000:8000"
        command: php artisan serve --host=0.0.0.0 --port=8000

    db:
        image: mysql:8.0
        environment:
            MYSQL_DATABASE: testing
            MYSQL_ALLOW_EMPTY_PASSWORD: yes

    unit:
        image: php:8.2-cli
        working_dir: /var/www/html
        volumes:
            - ./:/var/www/html
        depends_on:
            - app
        command: vendor/bin/phpunit --testsuite=Unit

    feature:
        image: php:8.2-cli
        working_dir: /var/www/html
        volumes:
            - ./:/var/www/html
        depends_on:
            - app
            - db
        command: vendor/bin/phpunit --testsuite=Feature

    integration:
        image: php:8.2-cli
        working_dir: /var/www/html
        volumes:
            - ./:/var/www/html
        depends_on:
            - app
            - db
        command: vendor/bin/phpunit --testsuite=Integration

    functional:
        image: php:8.2-cli
        working_dir: /var/www/html
        volumes:
            - ./:/var/www/html
        depends_on:
            - app
            - db
        command: vendor/bin/phpunit --testsuite=Functional

    dusk:
        image: cypress/browsers:node-22.14.0-chrome-133.0.6943.126-1-ff-135.0.1
        working_dir: /var/www/html
        volumes:
            - ./:/var/www/html
        depends_on:
            - app
        environment:
            APP_URL: http://app:8000
        command: php artisan dusk --headless
```

## 1. Unit Tests

### Scope

* Test **individual classes** and **methods** in isolation without external dependencies ([testrigor.com](https://testrigor.com/laravel-testing/?utm_source=chatgpt.com)).

### Dependencies

* **Mock** or **stub** external services using **Mockery**.
* Use **RefreshDatabase** trait only if testing models with in-memory SQLite ([laravel.com](https://laravel.com/docs/12.x/database-testing?utm_source=chatgpt.com)).

### Docker Service

* Runs `vendor/bin/phpunit --testsuite=Unit` in the **unit** service.

### Commands

* `docker-compose run --rm unit`

## 2. Feature Tests

### Scope

* Hit **HTTP endpoints** via Laravel’s `$this->get()` and `$this->post()` helpers to test routes, middleware, and controllers ([laravel.com](https://laravel.com/docs/11.x/http-tests?utm_source=chatgpt.com)).
* Verify **JSON responses**, **views**, or **database states**.

### Dependencies

* **SQLite** or MySQL configured in **phpunit.xml**.
* Use **WithoutMiddleware** mask if skipping middleware.

### Docker Service

* Runs `vendor/bin/phpunit --testsuite=Feature` in the **feature** service.

### Commands

* `docker-compose run --rm feature`

## 3. Functional Tests

### Scope

* Also exercise **full user flows** but may mock external APIs for isolation.
* Similar to Feature tests but focused on **business scenarios** ([dev.to](https://dev.to/omarmalas/testing-in-laravel-types-and-setup-2gp?utm_source=chatgpt.com)).

### Dependencies

* Configure **fake** mailers and queues via Laravel’s `Mail::fake()`.
* Use **HTTP testing** helpers and **notification fakes**.

### Docker Service

* Runs `vendor/bin/phpunit --testsuite=Functional` in the **functional** service.

### Commands

* `docker-compose run --rm functional`

## 4. Integration Tests

### Scope

* Test **collaboration** between services and repositories without HTTP layer ([engineering.teknasyon.com](https://engineering.teknasyon.com/integration-testing-laravel-application-taking-tdd-approach-bae45c545aac?utm_source=chatgpt.com)).
* Example: Service classes that use multiple repositories.

### Dependencies

* Use **InMemory** or **SQLite** connections for DB tests.
* Boot the **Laravel container** only once for the suite.

### Docker Service

* Runs `vendor/bin/phpunit --testsuite=Integration` in the **integration** service.

### Commands

* `docker-compose run --rm integration`

## 5. E2E Tests (Laravel Dusk)

### Scope

* Full **browser** tests simulating user interactions across pages ([laravel.com](https://laravel.com/docs/12.x/dusk?utm_source=chatgpt.com)).

### Dependencies

* **ChromeDriver** installed automatically by Dusk.
* Optionally add **Selenium** container for remote WebDriver ([blog.deleu.dev](https://blog.deleu.dev/laravel-dusk-on-docker/?utm_source=chatgpt.com)).

### Docker Service

* Uses Cypress browsers image to run `php artisan dusk --headless` in the **dusk** service.

### Commands

* `docker-compose run --rm dusk`

---

**Next Steps:**

* Tweak `phpunit.xml` for environment variables.
* Tag tests with `@group` to run subsets.
* Integrate health checks to ensure services are ready before tests.
