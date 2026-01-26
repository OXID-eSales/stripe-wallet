# SPRINT-17: DELETE StripeCustomerService

**Priority:** MEDIUM
**Estimated Effort:** 30min
**Impact:** Remove direct SDK violation, fix PHPStan warning
**Decision:** Delete permanently - no plans for customer management (confirmed)

---

## Problem Statement

`StripeCustomerService` is an empty shell that:
1. Creates `StripeClient` directly (violates adapter architecture)
2. Has unused `$stripe` property (PHPStan warning)
3. Has no actual methods - all functionality was removed

```php
// Current state - essentially empty
class StripeCustomerService implements InitializableServiceInterface
{
    use InitializableServiceTrait;

    private ?StripeClient $stripe = null;  // Written but never read

    protected function doInitialize(): void
    {
        $this->stripe = new StripeClient($secretKey);  // Direct SDK - VIOLATION
    }
}
```

**PHPStan Error:**
- Line 58: `Property StripeCustomerService::$stripe is never read, only written`

---

## Requirements

### R1: Delete StripeCustomerService class
- Remove the file entirely
- It has no functionality

### R2: Delete associated test file
- Remove `StripeCustomerServiceTest.php` if exists

### R3: Remove from services.yaml
- Remove any DI registration

### R4: Verify no usages exist
- Grep for any references before deletion

### R5: All tests must pass
- PHPStan level 6
- PHPCS PSR-12

---

## Implementation

### Step 1: Verify no usages

```bash
# Check for usages
grep -r "StripeCustomerService" src/ tests/ --include="*.php" | grep -v "StripeCustomerService.php"

# Expected: No results (or only test file)
```

### Step 2: Check services.yaml

```bash
grep -n "StripeCustomerService" services.yaml

# If found, note line numbers for removal
```

### Step 3: Delete files

```bash
# Delete the service
rm src/Stripe/Service/StripeCustomerService.php

# Delete test if exists
rm tests/Unit/Stripe/Service/StripeCustomerServiceTest.php 2>/dev/null || true
```

### Step 4: Remove from services.yaml (if registered)

If found in services.yaml, remove the registration block.

---

## Files to Delete

| File | Reason |
|------|--------|
| `src/Stripe/Service/StripeCustomerService.php` | Empty, direct SDK violation |
| `tests/Unit/Stripe/Service/StripeCustomerServiceTest.php` | Test for deleted class |

## Files to Modify

| File | Action |
|------|--------|
| `services.yaml` | Remove registration (if exists) |

---

## Verification

```bash
# Verify file is deleted
ls src/Stripe/Service/StripeCustomerService.php 2>&1
# Expected: "No such file or directory"

# Verify no references remain
grep -r "StripeCustomerService" src/ tests/

# Run pre-commit check
./bin/pre-commit-check.sh --full

# Expected: All checks pass
# - PHPStan: No more "property never read" error
# - PHPUnit: All tests pass
# - PHPCS: No style violations
```

---

## Acceptance Criteria

- [ ] `StripeCustomerService.php` deleted
- [ ] `StripeCustomerServiceTest.php` deleted (if existed)
- [ ] No references to `StripeCustomerService` in codebase
- [ ] Removed from `services.yaml` (if was registered)
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] No PHPStan "property never read" warning
