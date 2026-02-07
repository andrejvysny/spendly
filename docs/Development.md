# Development Setup

## 📋 Prerequisites

- PHP 8.3 or higher
- Node.js 20 or higher
- Composer
- Docker (recommended)

## 🛠️ Installation

### 1. Clone the repository
```bash
git clone https://github.com/your-username/spendly.git
cd spendly
```

### 2. Set up the environment
```bash
cp .env.example .env
```

### 3. Using Docker (Recommended)
```bash
docker compose up -d
docker compose exec cli composer install
docker compose exec cli php artisan key:generate
docker compose exec cli php artisan migrate --seed
docker compose exec node npm install
docker compose exec node npm run dev
```

### 4. Or install locally (without Docker)
```bash
composer install
php artisan key:generate
php artisan migrate --seed
npm install
npm run dev & # Start the frontend build process in the background - or use another terminal without &
php artisan serve & # Start the backend server in the background - or use another terminal without &
```

## 🧪 Testing

Run the test suites:

```bash
# Backend tests
./vendor/bin/phpunit

# Frontend tests
npm test
```

## Cursor IDE – SQLite MCP

If you use Cursor, the project includes a project-level MCP config (`.cursor/mcp.json`) for the SQLite MCP server so the agent can query the app database. See [docs/ai/CURSOR_MCP.md](ai/CURSOR_MCP.md) for setup and custom DB paths.

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

