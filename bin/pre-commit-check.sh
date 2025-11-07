#!/bin/bash

# Pre-commit check script
# Runs tests and checks to ensure code is ready for commit

set +e  # Don't exit on error, we want to collect all results

# Get the script directory and navigate to project root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/../../.." && pwd )"

cd "$PROJECT_ROOT" || {
    echo "Error: Could not navigate to project root"
    exit 1
}

echo "======================================"
echo "Running Pre-Commit Checks"
echo "======================================"
echo "Project root: $PROJECT_ROOT"
echo ""

# Initialize status tracking
OVERALL_STATUS=0
FAILED_CHECKS=()

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. Code Style Check (phpcs)
echo ">>> Running PHP Code Sniffer..."
docker compose exec -w /var/www/extensions/stripe -T php composer run phpcs src
PHPCS_STATUS=$?
if [ $PHPCS_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHP Code Sniffer")
    echo -e "${RED}✗ PHP Code Sniffer failed${NC}"
else
    echo -e "${GREEN}✓ PHP Code Sniffer passed${NC}"
fi
echo ""

# 2. PHPUnit - All Tests
echo ">>> Running PHPUnit Tests (All)..."
docker compose exec -w /var/www/extensions/stripe -T php \
    vendor/bin/phpunit -c tests/phpunit.xml
PHPUNIT_STATUS=$?
if [ $PHPUNIT_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHPUnit Tests")
    echo -e "${RED}✗ PHPUnit tests failed${NC}"
else
    echo -e "${GREEN}✓ PHPUnit tests passed${NC}"
fi
echo ""

# 3. Style Commit Check
echo ">>> Running style-commit check..."
docker compose exec -w /var/www/extensions/stripe -T php composer style-commit
STYLE_COMMIT_STATUS=$?
if [ $STYLE_COMMIT_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("Style Commit")
    echo -e "${RED}✗ Style commit check failed${NC}"
else
    echo -e "${GREEN}✓ Style commit check passed${NC}"
fi
echo ""

# Summary
echo "======================================"
echo "SUMMARY"
echo "======================================"
echo ""

if [ $OVERALL_STATUS -eq 0 ]; then
    echo -e "${GREEN}✓ ALL CHECKS PASSED${NC}"
    echo -e "${GREEN}Status: COMMITABLE${NC}"
    exit 0
else
    echo -e "${RED}✗ SOME CHECKS FAILED${NC}"
    echo ""
    echo "Failed checks:"
    for check in "${FAILED_CHECKS[@]}"; do
        echo -e "  ${RED}- $check${NC}"
    done
    echo ""
    echo -e "${RED}Status: NON-COMMITABLE${NC}"
    echo ""
    echo "Fix the issues above before committing."
    exit 1
fi
