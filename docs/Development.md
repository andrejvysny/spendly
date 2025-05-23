# Development Setup

## 📋 Prerequisites

- PHP 8.1 or higher
- Node.js 16.x or higher
- Composer
- MySQL/PostgreSQL
- GoCardless API credentials

## 🛠️ Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/spendly.git
cd spendly
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install JavaScript dependencies:
```bash
npm install
```

4. Copy the environment file:
```bash
cp .env.example .env
```

5. Configure your environment variables in `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=spendly
DB_USERNAME=root
DB_PASSWORD=

GOCARDLESS_ACCESS_TOKEN=your_access_token
GOCARDLESS_ENVIRONMENT=sandbox
```

6. Generate application key:
```bash
php artisan key:generate
```

7. Run migrations:
```bash
php artisan migrate
```

8. Start the development servers:
```bash
# Terminal 1 - Laravel
php artisan serve

# Terminal 2 - React
npm run dev
```

## 🧪 Testing

Run the test suites:

```bash
# Backend tests
php artisan test

# Frontend tests
npm test
```

## 📚 Documentation

- [API Documentation](api.md)
- [Frontend Development Guide](frontend.md)
- [Backend Development Guide](backend.md)
- [Contributing Guidelines](../CONTRIBUTING.md)

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guidelines](../CONTRIBUTING.md) for details on how to submit pull requests, report issues, and suggest improvements.

## 📞 Support

- [GitHub Issues](https://github.com/yourusername/spendly/issues)
- [Discord Community](https://discord.gg/spendly)
- [Documentation](https://docs.spendly.app)

