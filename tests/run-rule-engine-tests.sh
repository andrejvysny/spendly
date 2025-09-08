#!/bin/bash

# Rule Engine Test Runner Script
# This script runs all tests related to the Rule Engine implementation

echo "=== Rule Engine Test Suite ==="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to run tests and check results
run_test_suite() {
    local suite_name=$1
    local test_path=$2
    
    echo -e "${YELLOW}Running $suite_name...${NC}"
    
    if php artisan test "$test_path" --stop-on-failure; then
        echo -e "${GREEN}✓ $suite_name passed${NC}"
        echo ""
        return 0
    else
        echo -e "${RED}✗ $suite_name failed${NC}"
        echo ""
        return 1
    fi
}

# Track failures
FAILED=0

# 1. Migration Tests
run_test_suite "Migration Tests" "tests/Feature/RuleEngine/MigrationTest.php" || FAILED=1

# 2. Unit Tests
echo -e "${YELLOW}=== Unit Tests ===${NC}"
echo ""

run_test_suite "Condition Evaluator Tests" "tests/Unit/RuleEngine/ConditionEvaluatorTest.php" || FAILED=1
run_test_suite "Action Executor Tests" "tests/Unit/RuleEngine/ActionExecutorTest.php" || FAILED=1

# 3. Feature Tests
echo -e "${YELLOW}=== Feature Tests ===${NC}"
echo ""

run_test_suite "Event-Driven Rules Tests" "tests/Feature/RuleEngine/EventDrivenRulesTest.php" || FAILED=1
run_test_suite "Rule API Tests" "tests/Feature/RuleEngine/RuleApiTest.php" || FAILED=1

# 4. All Rule Engine Tests Together
echo -e "${YELLOW}=== Running All Rule Engine Tests ===${NC}"
echo ""

if php artisan test --filter=RuleEngine --parallel; then
    echo -e "${GREEN}✓ All Rule Engine tests passed${NC}"
else
    echo -e "${RED}✗ Some Rule Engine tests failed${NC}"
    FAILED=1
fi

# Summary
echo ""
echo "=== Test Summary ==="
if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed successfully!${NC}"
    
    # Generate coverage report if requested
    if [ "$1" == "--coverage" ]; then
        echo ""
        echo -e "${YELLOW}Generating coverage report...${NC}"
        php artisan test --filter=RuleEngine --coverage --min=80
    fi
else
    echo -e "${RED}Some tests failed. Please check the output above.${NC}"
    exit 1
fi

# Optional: Run specific test groups
if [ "$1" == "--unit" ]; then
    echo ""
    echo -e "${YELLOW}Running only unit tests...${NC}"
    php artisan test tests/Unit/RuleEngine
elif [ "$1" == "--feature" ]; then
    echo ""
    echo -e "${YELLOW}Running only feature tests...${NC}"
    php artisan test tests/Feature/RuleEngine
elif [ "$1" == "--verbose" ]; then
    echo ""
    echo -e "${YELLOW}Running tests with verbose output...${NC}"
    php artisan test --filter=RuleEngine -v
fi 