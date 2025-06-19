#!/bin/bash

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
CMD="docker compose run test ./vendor/bin/phpunit"

if [ "$COVERAGE" = true ]; then
    case $COVERAGE_TYPE in
        "text")
            CMD="$CMD --coverage-text --coverage-filter app"
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

echo "✅ Tests completed!"
echo "📊 Coverage reports available in:"
echo "   - HTML: coverage/html/index.html"
echo "   - Clover XML: coverage/clover.xml"
echo "   - Text: coverage/coverage.txt" 