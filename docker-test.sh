#!/bin/bash

# Script to run tests with coverage using Docker

echo "🐳 Running tests with coverage using Docker..."

# Build the test container if it doesn't exist
docker-compose build test

# Run tests with coverage
docker-compose run --rm test php artisan test --coverage

echo "✅ Tests completed!"
echo "📊 Coverage reports available in:"
echo "   - HTML: coverage/html/index.html"
echo "   - Clover XML: coverage/clover.xml"
echo "   - Text: coverage/coverage.txt" 