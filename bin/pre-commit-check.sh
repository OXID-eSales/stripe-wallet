#!/bin/bash

# Pre-commit check script
# Runs tests and checks to ensure code is ready for commit
# Works both locally (with Docker) and on GitHub Actions (without Docker)
#
# Usage: ./bin/pre-commit-check.sh [OPTIONS]
# Options:
#   --no-phpunit    Skip PHPUnit tests entirely
#   --full          Run Unit + Integration tests (the local default — kept for back-compat)
#   --unit-only     Run only Unit tests (faster; skip Integration)
#   --changed-only  Scope PHPStan + PHPMD to git-changed files only (faster; default is all of src/)
#
# Default scope (no flags):
#   PHPUnit  — Unit + Integration   (full coverage before a commit)
#   PHPStan  — all of src/          (matches CI's `composer phpstan`)
#   PHPMD    — all of src/          (matches CI's `composer phpmd`)
#
# GitHub Actions: PHPUnit step is a no-op (CI runs its own suite); PHPStan/PHPMD
# delegate to composer scripts which already scope to all of src/.

set +e  # Don't exit on error, we want to collect all results

# Parse command line arguments. TEST_MODE = "default" | "full" | "unit"; "default"
# is resolved per environment below (local → full, CI → unit).
SKIP_PHPUNIT=false
TEST_MODE="default"
SCAN_MODE="all"   # "all" | "changed"
for arg in "$@"; do
    case $arg in
        --no-phpunit)
            SKIP_PHPUNIT=true
            shift
            ;;
        --full)
            TEST_MODE="full"
            shift
            ;;
        --unit-only)
            TEST_MODE="unit"
            shift
            ;;
        --changed-only)
            SCAN_MODE="changed"
            shift
            ;;
        *)
            echo "Warning: unknown flag '$arg' (known: --no-phpunit, --full, --unit-only, --changed-only)" >&2
            ;;
    esac
done

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

# Helper function to run phpcs in Docker with correct path
run_phpcs_docker() {
    docker compose exec -w /var/www/extensions/stripe -T php \
        /var/www/vendor/bin/phpcs --standard=tests/phpcs.xml --warning-severity=0 src/
}

# Helper function to run phpstan in Docker using module's vendor.
# $1 = "all" (scope to src/) or a space-separated list of files (changed-only mode).
run_phpstan_docker() {
    local scope="$1"
    if [ "$scope" = "all" ]; then
        echo "Running PHPStan over all of src/"
        docker compose exec -w /var/www/extensions/stripe -T php \
            vendor/bin/phpstan analyse -c tests/PhpStan/phpstan.neon --level=max --memory-limit=1G src/
    elif [ -n "$scope" ]; then
        echo "Running PHPStan on changed files: $scope"
        docker compose exec -w /var/www/extensions/stripe -T php \
            vendor/bin/phpstan analyse -c tests/PhpStan/phpstan.neon --level=max --memory-limit=1G $scope
    else
        echo "No PHP files to check with PHPStan"
        return 0
    fi
}

# Helper function to run phpmd in Docker using module's vendor.
# $1 = "all" (scope to src/) or a space-separated list of files (changed-only mode).
run_phpmd_docker() {
    local scope="$1"
    if [ "$scope" = "all" ]; then
        echo "Running PHPMD over all of src/"
        docker compose exec -w /var/www/extensions/stripe -T php \
            vendor/bin/phpmd src/ text tests/PhpMd/phpmd.xml --baseline-file tests/PhpMd/phpmd.baseline.xml --exclude tests/,migration/data/ --suffixes php --strict
    elif [ -n "$scope" ]; then
        echo "Running PHPMD on changed files: $scope"
        docker compose exec -w /var/www/extensions/stripe -T php \
            vendor/bin/phpmd $scope text tests/PhpMd/phpmd.xml --baseline-file tests/PhpMd/phpmd.baseline.xml --exclude tests/,migration/data/ --suffixes php --strict
    else
        echo "No PHP files to check with PHPMD"
        return 0
    fi
}

# Helper function to get changed PHP files (for local use)
get_changed_files_local() {
    cd "$MODULE_ROOT" || return
    local files=""
    # Get files changed from HEAD (excluding deleted files with --diff-filter=d)
    files=$(git diff --diff-filter=d --name-only HEAD 2>/dev/null | grep -E '\.php$' | grep -vE '^tests/' | tr '\n' ' ')
    # If no uncommitted changes, get files from last commit
    if [ -z "$files" ]; then
        files=$(git diff-tree --no-commit-id --diff-filter=d --name-only -r HEAD 2>/dev/null | grep -E '\.php$' | grep -vE '^tests/' | tr '\n' ' ')
    fi
    echo "$files"
}

# 1. Code Style Check (phpcs)
echo ">>> Running PHP Code Sniffer..."
if [ "$ENVIRONMENT" = "github" ]; then
    run_command "composer run phpcs src"
else
    run_phpcs_docker
fi
PHPCS_STATUS=$?
if [ $PHPCS_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHP Code Sniffer")
    echo -e "${RED}✗ PHP Code Sniffer failed${NC}"
else
    echo -e "${GREEN}✓ PHP Code Sniffer passed${NC}"
fi
echo ""

# 2. PHPUnit Tests
if [ "$SKIP_PHPUNIT" = true ]; then
    echo ">>> Skipping PHPUnit Tests (--no-phpunit flag set)"
    echo -e "${YELLOW}⊘ PHPUnit tests skipped${NC}"
    echo ""
else
    # Resolve "default" → local=full, CI=unit (CI's PHPUnit step is skipped below anyway).
    if [ "$TEST_MODE" = "default" ]; then
        if [ "$ENVIRONMENT" = "github" ]; then
            TEST_MODE="unit"
        else
            TEST_MODE="full"
        fi
    fi

    if [ "$TEST_MODE" = "full" ]; then
        echo ">>> Running PHPUnit Tests (Full: Unit + Integration, requires MySQL)..."
        TESTSUITE_ARG=""
    else
        echo ">>> Running PHPUnit Tests (Unit only, pass --full to include Integration)..."
        TESTSUITE_ARG="--testsuite Unit"
    fi

    if [ "$ENVIRONMENT" = "github" ]; then
      echo "skip on github"
      PHPUNIT_STATUS=0
    else
        # Local: Run in Docker with shop bootstrap (use shop's vendor phpunit)
        # Exclude webhook-e2e tests as they require actual HTTP endpoints
        docker compose exec -w /var/www/extensions/stripe -T php \
            /var/www/vendor/bin/phpunit -c tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php --exclude-group webhook-e2e $TESTSUITE_ARG
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
fi

# 3. PHPStan Static Analysis
echo ">>> Running PHPStan static analysis..."
if [ "$ENVIRONMENT" = "github" ]; then
    run_command "composer phpstan"
    PHPSTAN_STATUS=$?
else
    # Default to whole-src scan; --changed-only opts into the fast feedback path.
    if [ "$SCAN_MODE" = "changed" ]; then
        CHANGED_FILES=$(get_changed_files_local)
        run_phpstan_docker "$CHANGED_FILES"
    else
        run_phpstan_docker "all"
    fi
    PHPSTAN_STATUS=$?
fi
if [ $PHPSTAN_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHPStan")
    echo -e "${RED}✗ PHPStan failed${NC}"
else
    echo -e "${GREEN}✓ PHPStan passed${NC}"
fi
echo ""

# 4. PHPMD (PHP Mess Detector)
echo ">>> Running PHPMD..."
if [ "$ENVIRONMENT" = "github" ]; then
    run_command "composer phpmd"
    PHPMD_STATUS=$?
else
    if [ "$SCAN_MODE" = "changed" ]; then
        if [ -z "$CHANGED_FILES" ]; then
            CHANGED_FILES=$(get_changed_files_local)
        fi
        run_phpmd_docker "$CHANGED_FILES"
    else
        run_phpmd_docker "all"
    fi
    PHPMD_STATUS=$?
fi
if [ $PHPMD_STATUS -ne 0 ]; then
    OVERALL_STATUS=1
    FAILED_CHECKS+=("PHPMD")
    echo -e "${RED}✗ PHPMD failed${NC}"
else
    echo -e "${GREEN}✓ PHPMD passed${NC}"
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
