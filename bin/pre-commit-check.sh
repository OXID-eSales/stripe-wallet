#!/bin/bash

# Pre-commit check script
# Runs tests and checks to ensure code is ready for commit
# Works both locally (with Docker) and on GitHub Actions (without Docker)

set +e  # Don't exit on error, we want to collect all results

# Get the script directory (module root)
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
MODULE_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

# Detect environment: GitHub Actions or Local Docker
if [ -n "$GITHUB_ACTIONS" ]; then
    # GitHub Actions environment
    ENVIRONMENT="github"
    WORKING_DIR="$MODULE_ROOT"
    echo "======================================"
    echo "Running Pre-Commit Checks (GitHub Actions)"
    echo "======================================"
    echo "Module root: $MODULE_ROOT"
else
    # Local Docker environment
    ENVIRONMENT="local"
    PROJECT_ROOT="$( cd "$SCRIPT_DIR/../../.." && pwd )"
    echo "======================================"
    echo "Running Pre-Commit Checks (Local Docker)"
    echo "======================================"
    echo "Project root: $PROJECT_ROOT"

    # Navigate to project root for docker compose commands
    cd "$PROJECT_ROOT" || {
        echo "Error: Could not navigate to project root"
        exit 1
    }
fi

echo ""

# Initialize status tracking
OVERALL_STATUS=0
FAILED_CHECKS=()

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper function to run commands based on environment
run_command() {
    if [ "$ENVIRONMENT" = "github" ]; then
        # GitHub: Run directly on host
        cd "$MODULE_ROOT" && eval "$1"
    else
        # Local: Run in Docker container
        docker compose exec -w /var/www/extensions/stripe -T php bash -c "$1"
    fi
}

# 1. Code Style Check (phpcs)
echo ">>> Running PHP Code Sniffer..."
run_command "composer run phpcs src"
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
if [ "$ENVIRONMENT" = "github" ]; then
    # GitHub: Run directly with host paths
    cd "$MODULE_ROOT" && vendor/bin/phpunit -c tests/phpunit.xml
    PHPUNIT_STATUS=$?
else
    # Local: Run in Docker with shop bootstrap
    docker compose exec -w /var/www/extensions/stripe -T php \
        vendor/bin/phpunit -c tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php
    PHPUNIT_STATUS=$?
fi

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
run_command "composer style-commit"
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
