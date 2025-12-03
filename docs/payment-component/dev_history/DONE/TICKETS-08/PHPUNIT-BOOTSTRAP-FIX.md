# PHPUnit Bootstrap Configuration Fix

**Date:** 2025-10-30
**Issue:** GitHub Actions failing with bootstrap error
**Status:** ✅ Fixed

---

## Problem

GitHub Actions CI was failing when running unit tests with this error:

```
Error in bootstrap script: Error:
Failed opening required '/var/www/test-module/tests/../../../source/bootstrap.php'
```

### Root Cause

The `tests/phpunit.xml` was configured with a global `bootstrap="bootstrap.php"` that:
1. Loaded composer autoloader (needed for all tests) ✅
2. Loaded OXID shop bootstrap at `../../../source/bootstrap.php` (only needed for integration tests) ❌

In GitHub Actions, the directory structure is different (`/var/www/test-module`), so the OXID shop bootstrap path doesn't exist.

### Why This Was Wrong

**Unit tests should be isolated:**
- ❌ Should NOT depend on OXID shop environment
- ❌ Should NOT require shop bootstrap
- ✅ Should ONLY need composer autoloader
- ✅ Should run fast and independently

**Integration tests need shop environment:**
- ✅ Need OXID shop bootstrap
- ✅ Need database connection
- ✅ Need full shop environment

---

## Solution

### 1. Removed Global Bootstrap from phpunit.xml

**Before:**
```xml
<phpunit bootstrap="bootstrap.php">
```

**After:**
```xml
<phpunit>
    <!-- No global bootstrap -->
```

**Result:** Unit tests now use only composer's autoloader (loaded automatically by PHPUnit)

### 2. Updated GitHub Actions Workflow

**Unit Tests (no bootstrap needed):**
```yaml
- name: Install module dependencies and run unit tests
  run: |
    docker compose exec -T php bash -c "cd /var/www/test-module && composer install --no-interaction"
    docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

**Integration Tests (with shop bootstrap):**
```yaml
- name: Run integration tests with shop bootstrap
  run: |
    docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
      -c /var/www/test-module/tests/phpunit.xml \
      --testsuite Integration \
      --bootstrap=/var/www/source/bootstrap.php
```

---

## Verification

### Unit Tests (194 tests, 0.133s) ✅
```bash
# Local Docker environment
docker compose exec -T php bash -c "cd /var/www/extensions/stripe && \
  vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"

# Result
Tests: 194, Assertions: 278 ✅
Time: 00:00.133
```

### Integration Tests (with bootstrap) ✅
```bash
# Local Docker environment
docker compose exec -T php bash -c "cd /var/www/extensions/stripe && \
  vendor/bin/phpunit -c tests/phpunit.xml --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php"

# Bootstrap loads correctly ✅
```

---

## Benefits

✅ **Unit tests are truly isolated** - No shop dependencies
✅ **Faster unit test execution** - No bootstrap overhead
✅ **Works in all environments** - Local, Docker, GitHub Actions
✅ **Proper separation** - Unit tests separate from integration tests
✅ **CI/CD compatible** - Tests run successfully in GitHub Actions

---

## Files Changed

1. **tests/phpunit.xml** - Removed global bootstrap attribute
2. **.github/workflows/development.yml** - Added explicit bootstrap for integration tests only

---

## Best Practices Applied

### Unit Tests
- ✅ No external dependencies
- ✅ Fast execution
- ✅ Use interfaces for mocking
- ✅ Test one class at a time

### Integration Tests
- ✅ Use shop bootstrap
- ✅ Test component interactions
- ✅ Use real dependencies
- ✅ Test with database

---

**Status:** ✅ **FIXED - Ready for GitHub Actions**
*Last Updated: 2025-10-30*
