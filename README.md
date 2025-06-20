# Spendly - Personal Finance Tracker

**Based on [laravel/react-starter-kit](https://github.com/laravel/react-starter-kit)**

<!--
SPDX-License-Identifier: GPL-3.0-or-later
SPDX-FileCopyrightText: 2024 Spendly Contributors
-->



[![Build and Push Docker Image](https://github.com/andrejvysny/spendly/actions/workflows/build.yml/badge.svg?event=push)](https://github.com/andrejvysny/spendly/actions/workflows/build.yml)
[![tests](https://github.com/andrejvysny/spendly/actions/workflows/tests.yml/badge.svg?branch=main&event=push)](https://github.com/andrejvysny/spendly/actions/workflows/tests.yml)
![CodeRabbit Pull Request Reviews](https://img.shields.io/coderabbit/prs/github/andrejvysny/spendly?utm_source=oss&utm_medium=github&utm_campaign=andrejvysny%2Fspendly&labelColor=171717&color=FF570A&link=https%3A%2F%2Fcoderabbit.ai&label=CodeRabbit+Reviews)


![GitHub Issues or Pull Requests](https://img.shields.io/github/issues/andrejvysny/spendly)
![GitHub Issues or Pull Requests](https://img.shields.io/github/issues-pr/andrejvysny/spendly)


[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![SPDX](https://img.shields.io/badge/SPDX-GPL--3.0--or--later-blue.svg)](https://spdx.org/licenses/GPL-3.0-or-later.html)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)
[![React](https://img.shields.io/badge/React-19.x-blue.svg)](https://reactjs.org)
[![Self-Hosting](https://img.shields.io/badge/Self--Hosting-Ready-blue.svg)](docs/DEPLOYMENT.md)

> ⚠️ **Development Status Notice**
> 
> This project is currently in active development with no official release yet. The codebase and documentation have been significantly developed using AI assistance and require extensive refactoring and thorough code review before being production-ready. Use at your own risk and contributions are welcome to help improve the code quality and stability.

Spendly is an open-source personal finance tracker that helps you manage your finances, analyze spending patterns, and maintain budgets. It integrates with GoCardless for seamless bank account imports and provides powerful financial analysis tools.

## 📸 Screenshots

![Screenshots](docs/screenshots/screenshots.png)

## 🌟 Features

- **Bank Account Integration**: Import transactions automatically using GoCardless
- **Financial Analysis**: Get insights into your spending patterns and financial health
- **Budget Management WIP**: Create and track budgets for different categories
- **Transaction Categorization WIP**: Automatically categorize transactions with machine learning - currently supports manual categorization
- **Reports & Visualizations**: Beautiful charts and reports for better financial understanding
- **Multi-currency Support WIP**: Track finances in multiple currencies
- **CSV Import**: Import transactions from CSV files with customizable field mapping
- **Self-Hosting**: Easy deployment with Docker, Kubernetes, or bare metal
- **API Access WIP**: RESTful API for integrations and automation

## 🚀 Tech Stack

- **Backend**: Laravel 12.x
- **Frontend**: React 19.x with TypeScript
- **Database**: SQLite (default), MySQL, PostgreSQL
- **Authentication**: Laravel Sanctum
- **API Integration**: GoCardless API
- **Testing**: PHPUnit, Jest
- **Deployment**: Docker

---

## 🐳 Quick Start - One line (Recommended)

Download and run setup script scripts/setup.sh
```bash
curl -sSL https://raw.githubusercontent.com/andrejvysny/spendly/refs/heads/main/scripts/setup.sh | bash
```


## Manual Docker setup

1. Make sure you have Docker installed on your system.
2. Download compose.prod.yml.
3. Create .env file using .env.example and adjust settings as needed.
4. Generate app key using docker or use one from https://laravel-encryption-key-generator.vercel.app/.
```bash
docker run --rm \
        --entrypoint="" \
        --user "$(id -u):$(id -g)" \
        -v "$(pwd)/.env:/var/www/html/.env" \
        -v "$(pwd)/compose.yml:/var/www/html/compose.yml" \
        ghcr.io/andrejvysny/spendly:main \
        php artisan key:generate --force
```
5. Start Spendly 
```bash
docker compose up -d
```
6. Visit `http://localhost` in your browser and enjoy.


---

## 📚 Documentation (WIP)
 
- **[Installation Guide](docs/ai/INSTALLATION.md)** - Detailed setup instructions
- **[Deployment Guide](docs/DEPLOYMENT.md)** - Self-hosting and production deployment
- **[Development Setup](docs/DEVELOPMENT.md)** - Local development environment
- **[API Documentation](docs/API.md)** - RESTful API reference
- **[Contributing Guidelines](CONTRIBUTING.md)** - How to contribute
- **[Security Policy](SECURITY.md)** - Security and vulnerability reporting

## 🔧 Development

See [Development Guide](docs/DEVELOPMENT.md) for detailed instructions.
See [Testing Guide](docs/TESTING.md) for testing setup.


## 🔒 Security

Spendly takes security seriously, especially when handling financial data:

- **Data encryption** for sensitive information
- **All data stored locally**: Your data remains on your device and is never sent to external servers, giving you full control.
- **Secure API keys** and credentials management
- **Regular security audits** and dependency updates
- **Secure authentication** with Laravel Sanctum
- **Input validation** and SQL injection prevention
- **Security headers** and CSRF protection

Report security vulnerabilities to **vysnyandrej@gmail.com**. See [SECURITY.md](SECURITY.md) for details.

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guidelines](CONTRIBUTING.md) for details on:

- Code of Conduct
- Development setup
- Submitting pull requests
- Reporting issues
- Financial domain best practices

## 📄 License

This project is licensed under the GNU General Public License v3.0 (GPLv3). See LICENSE for details.

## 🏛️ Governance

- **Code of Conduct**: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
- **Security Policy**: [SECURITY.md](SECURITY.md)
- **Contributing Guidelines**: [CONTRIBUTING.md](CONTRIBUTING.md)
- **Changelog**: [CHANGELOG.md](CHANGELOG.md)

## 🙏 Acknowledgments

- [Laravel](https://laravel.com) - The PHP framework for web artisans
- [React](https://reactjs.org) - A JavaScript library for building user interfaces
- [GoCardless](https://gocardless.com) - Bank account data API
- [Tailwind CSS](https://tailwindcss.com) - A utility-first CSS framework
- All our contributors and supporters

## 📞 Support

- **Documentation**: [Installation](docs/ai/INSTALLATION.md) | [API](docs/API.md) | [Deployment](docs/DEPLOYMENT.md)
- **GitHub Issues**: [Report bugs or request features](https://github.com/andrejvysny/spendly/issues)
- **Security Issues**: vysnyandrej@gmail.com
- **Community**: [GitHub Discussions](https://github.com/andrejvysny/spendly/discussions)

## 🔗 Links

- **Project Repository**: [GitHub](https://github.com/andrejvysny/spendly)
- **Docker Images**: [GitHub Container Registry](https://ghcr.io/andrejvysny/spendly)
- **Issue Tracker**: [GitHub Issues](https://github.com/andrejvysny/spendly/issues)
- **API Documentation**: [docs/API.md](docs/API.md)

---

**Made with ❤️ for the open-source community**
