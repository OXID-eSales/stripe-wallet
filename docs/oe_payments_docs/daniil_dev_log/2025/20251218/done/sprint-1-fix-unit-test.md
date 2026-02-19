# Sprint 1: Fix StripePaymentReturnHandlerTest

**Sprint Goal:** Fix failing unit tests for StripePaymentReturnHandler (7 test errors)
**Status:** PENDING
**Priority:** HIGH (blocking CI)

---

## Problem Description

The `StripePaymentReturnHandler` constructor signature was changed to remove `$contractRepository` parameter, but the unit test still uses the old signature.

### Current Handler Constructor (CORRECT)

```php
// src/Stripe/EventSystem/Handler/StripePaymentReturnHandler.php
public function __construct(
    private EventDispatcherInterface $eventDispatcher,
    private ?FileLoggerInterface $eventLogger = null
)
```

### Test Using Old Signature (WRONG)

```php
// tests/Unit/Stripe/EventSystem/Handler/StripePaymentReturnHandlerTest.php
$handler = new StripePaymentReturnHandler(
    $this->contractRepository,  // <-- REMOVED from handler
    $this->eventDispatcher
);
```

---

## GitHub CI Error (7 failures)

```
1) StripePaymentReturnHandlerTest::testHandlesSucceededRedirectStatus
   StripePaymentReturnHandler::__construct(): Argument #1 ($eventDispatcher) must be of type
   EventDispatcherInterface, ContractRepositoryInterface given

2) StripePaymentReturnHandlerTest::testHandlesFailedRedirectStatus
   [Same error]

3) StripePaymentReturnHandlerTest::testSetsErrorWhenPaymentIntentMissing
   [Same error]

4) StripePaymentReturnHandlerTest::testPassesContractIdToExecuteEvent
   [Same error]

... and 3 more
```

---

## Tasks

### 1.1 Fix Constructor Calls in Test

**Status:** [ ] NOT STARTED

**File:** `tests/Unit/Stripe/EventSystem/Handler/StripePaymentReturnHandlerTest.php`

**Changes Required:**

1. Remove `$this->contractRepository` property declaration
2. Update `setUp()` to remove contractRepository mock (if present)
3. Fix all constructor calls:

**Before:**
```php
$handler = new StripePaymentReturnHandler(
    $this->contractRepository,
    $this->eventDispatcher
);
```

**After:**
```php
$handler = new StripePaymentReturnHandler(
    $this->eventDispatcher
);
```

---

### 1.2 Update Test Methods

**Status:** [ ] NOT STARTED

**Methods to fix (lines with wrong signature):**
- Line 100-103: `testHandlesSucceededRedirectStatus`
- Line 124-127: `testHandlesFailedRedirectStatus`
- Line 146-149: `testSetsErrorWhenPaymentIntentMissing`
- Line 183-186: `testPassesContractIdToExecuteEvent`

**Each method uses the old signature:**
```php
$handler = new StripePaymentReturnHandler(
    $this->contractRepository,  // REMOVE THIS LINE
    $this->eventDispatcher
);
```

---

### 1.3 Verify Tests Pass

**Status:** [ ] NOT STARTED

**Command:**
```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/EventSystem/Handler/StripePaymentReturnHandlerTest.php
```

---

## Definition of Done

- [ ] All 7 test methods fixed to use correct constructor signature
- [ ] No `$contractRepository` references in test file
- [ ] Tests pass locally: `docker compose exec php vendor/bin/phpunit ... StripePaymentReturnHandlerTest.php`
- [ ] Pre-commit check passes: `./bin/pre-commit-check.sh`
- [ ] CI passes on GitHub

---

## Files to Modify

| File | Action |
|------|--------|
| `tests/Unit/Stripe/EventSystem/Handler/StripePaymentReturnHandlerTest.php` | Fix constructor calls |

---

## Development Principles

All changes must follow:

- **TDD** - Write failing tests first, then implementation
- **SOLID** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance

---

## Commands Reference

```bash
# Run pre-commit check
./bin/pre-commit-check.sh           # Unit tests + style checks
./bin/pre-commit-check.sh --full    # Unit + Integration tests
./bin/pre-commit-check.sh --no-phpunit  # Style checks only

# Run specific unit test
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/EventSystem/Handler/StripePaymentReturnHandlerTest.php

# Run all unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Code style checks
composer phpcs              # PHP CodeSniffer (PSR-12)
composer phpstan            # PHPStan static analysis (level 6)
composer phpmd              # PHP Mess Detector
composer style              # All style checks
```

---

## Notes

- The handler no longer uses `ContractRepository` directly - contract operations are handled by other event handlers
- The `EventDispatcherInterface` is the only required dependency
- The optional `FileLoggerInterface` can be passed for debugging but is not required
