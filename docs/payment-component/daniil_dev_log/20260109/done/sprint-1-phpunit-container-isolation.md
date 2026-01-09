# Sprint 1: Move OXID-Dependent Tests to Integration Suite

**Sprint Goal:** Fix 46 PHPUnit errors by moving tests with OXID dependencies to Integration suite
**Status:** COMPLETED
**Priority:** HIGH

---

## Problem Analysis

### Root Cause

Unit tests are failing because they test classes that depend on OXID's Registry/Container. These are **integration tests**, not unit tests, and must run with the OXID bootstrap.

### Solution

Move tests that call `Registry::get()`, `ContainerFacade::get()`, or extend OXID controllers from `tests/Unit/` to `tests/Integration/` where they will use:

```php
// Bootstrap file: /var/www/source/bootstrap.php
```

---

## Affected Test Files

| Test File | Reason for Move | Status |
|-----------|-----------------|--------|
| `tests/Unit/Stripe/Controller/Admin/OrderRefundControllerTest.php` | Extends AdminController, uses Registry | MOVED |
| `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php` | Uses Registry for config/session | MOVED |
| `tests/Unit/Stripe/Webhook/PaymentIntentWebhookTest.php` | WebhookProcessingService uses Registry::getLogger() | MOVED |
| `tests/Unit/Stripe/Webhook/ChargeWebhookTest.php` | WebhookProcessingService uses Registry::getLogger() | MOVED |
| `tests/Unit/Stripe/Webhook/DisputeWebhookTest.php` | WebhookProcessingService uses Registry::getLogger() | MOVED |

---

## Tasks

### 1.1 Identify All Affected Tests
**Status:** [x] COMPLETED

- [x] List all 46 failing tests
- [x] Confirm they depend on OXID container
- [x] Group by test file (5 files total)

### 1.2 Move OrderRefundControllerTest to Integration
**Status:** [x] COMPLETED

**From:** `tests/Unit/Stripe/Controller/Admin/OrderRefundControllerTest.php`
**To:** `tests/Integration/Stripe/Controller/Admin/OrderRefundControllerTest.php`

- [x] Move file
- [x] Update namespace
- [x] Run test to confirm it passes

### 1.3 Move StripeOrderControllerTest to Integration
**Status:** [x] COMPLETED

**From:** `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php`
**To:** `tests/Integration/Stripe/Controller/StripeOrderControllerTest.php`

- [x] Move file
- [x] Update namespace
- [x] Run test to confirm it passes

### 1.4 Move PaymentIntentWebhookTest to Integration
**Status:** [x] COMPLETED

**From:** `tests/Unit/Stripe/Webhook/PaymentIntentWebhookTest.php`
**To:** `tests/Integration/Stripe/Webhook/PaymentIntentWebhookTest.php`

- [x] Move file
- [x] Update namespace
- [x] Run test to confirm it passes

### 1.5 Move ChargeWebhookTest to Integration
**Status:** [x] COMPLETED

**From:** `tests/Unit/Stripe/Webhook/ChargeWebhookTest.php`
**To:** `tests/Integration/Stripe/Webhook/ChargeWebhookTest.php`

- [x] Move file
- [x] Update namespace
- [x] Run test to confirm it passes

### 1.6 Move DisputeWebhookTest to Integration
**Status:** [x] COMPLETED

**From:** `tests/Unit/Stripe/Webhook/DisputeWebhookTest.php`
**To:** `tests/Integration/Stripe/Webhook/DisputeWebhookTest.php`

- [x] Move file
- [x] Update namespace
- [x] Run test to confirm it passes

---

## PHPUnit Configuration Reference

```xml
<!-- tests/phpunit.xml -->
<testsuites>
    <testsuite name="Unit">
        <directory>Unit</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory>Integration</directory>
    </testsuite>
</testsuites>

<php>
    <!-- Integration tests use OXID bootstrap -->
    <const name="OXID_BOOTSTRAP" value="/var/www/source/bootstrap.php"/>
</php>
```

---

## Definition of Done

- [x] All 46 errors resolved
- [x] 1 failure fixed
- [x] Tests moved to correct suite (Unit vs Integration)
- [x] `./bin/pre-commit-check.sh` passes
- [x] Integration tests run with OXID bootstrap

---

## Test Commands

```bash
# Run Unit tests only
docker compose exec php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Run Integration tests only
docker compose exec php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml --testsuite Integration

# Run specific moved test
docker compose exec php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Integration/Stripe/Controller/Admin/OrderRefundControllerTest.php
```

---

## Notes

- Unit tests = no external dependencies, pure logic
- Integration tests = require OXID container, database, or external services
- Bootstrap `/var/www/source/bootstrap.php` initializes OXID Registry and Container
