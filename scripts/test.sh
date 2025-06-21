#!/bin/bash

set -e

# Check if docker compose is installed and accessible
if ! command -v docker &> /dev/null; then
    echo "❌ Error: Docker is not installed or not in PATH. Please install Docker and try again."
    exit 1
fi

if ! docker compose version &> /dev/null; then
    echo "❌ Error: 'docker compose' is not available. Please ensure you have Docker Compose V2 installed (use 'docker compose', not 'docker-compose')."
    exit 1
fi

# Check if the 'test' service is defined in docker compose
if ! docker compose config --services | grep -q '^test$'; then
    echo "❌ Error: 'test' service is not defined in your docker-compose file. Please check your configuration."
    exit 1
fi

# Check if Docker daemon is running
if ! docker info &> /dev/null; then
    echo "❌ Error: Docker daemon is not running. Please start Docker and try again."
    exit 1
fi

# Default values
COVERAGE=false
COVERAGE_TYPE="html"
COVERAGE_DIR="coverage/html"

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --coverage)
            COVERAGE=true
            shift
            ;;
        --coverage-text)
            COVERAGE=true
            COVERAGE_TYPE="text"
            shift
            ;;
        --coverage-html)
            COVERAGE=true
            COVERAGE_TYPE="html"
            shift
            ;;
        --coverage-clover)
            COVERAGE=true
            COVERAGE_TYPE="clover"
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --coverage         Run tests with HTML coverage report"
            echo "  --coverage-text    Run tests with text coverage report"
            echo "  --coverage-html    Run tests with HTML coverage report (default)"
            echo "  --coverage-clover  Run tests with Clover XML coverage report"
            echo "  --help, -h         Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                    # Run tests without coverage"
            echo "  $0 --coverage         # Run tests with HTML coverage"
            echo "  $0 --coverage-text    # Run tests with text coverage"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Build the PHPUnit command
CMD="docker compose run test ./vendor/bin/phpunit --colors=always --stop-on-failure --testdox"

if [ "$COVERAGE" = true ]; then
    case $COVERAGE_TYPE in
        "text")
            mkdir -p coverage
            CMD="$CMD --coverage-text --coverage-filter app > coverage/coverage.txt"
            ;;
        "html")
            CMD="$CMD --coverage-html coverage/html --coverage-filter app"
            ;;
        "clover")
            CMD="$CMD --coverage-clover coverage/clover.xml --coverage-filter app"
            ;;
    esac
fi

echo "Running: $CMD"
eval $CMD

if [ "$COVERAGE_TYPE" = "text" ]; then
    echo -e "\n===== coverage/coverage.txt ====="
    cat coverage/coverage.txt
    echo "==============================="
fi

echo -e "\n\n✅ PHP Tests completed!"
echo -e "\n📊 Coverage reports available in:"
echo -e "\n   - HTML: coverage/html/index.html"
echo -e "\n   - Clover XML: coverage/clover.xml"
echo -e "\n   - Text: coverage/coverage.txt"



echo -e "📦 Running JavaScript tests...\n"
docker compose run --rm node npm test
if [ $? -ne 0 ]; then
    echo "❌ Error: JavaScript tests failed. Exiting."
    exit 1
fi
echo -e "\n✅ JavaScript tests completed successfully!"



echo -e "\n\n 🚀 All tests passed successfully!"
