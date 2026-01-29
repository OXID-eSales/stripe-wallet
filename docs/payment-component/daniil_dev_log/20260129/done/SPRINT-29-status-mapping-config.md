# Sprint 29: Extract Status Mapping to Configuration Class

**Status:** TODO
**Priority:** Normal
**Estimated Tests:** 8-10

---

## Problem Statement

Status mapping settings (`sStripeStatusPending`, `sStripeStatusProcessing`, `sStripeStatusCancelled`) are currently exposed in admin UI as configurable options. However:

1. These values should NOT be changed during normal shop administration
2. They are "hardcoded" defaults that only change if Stripe SDK changes
3. Admin exposure creates confusion and potential misconfiguration risk

---

## Solution

Create a dedicated `StatusMappingConfig` class with pure constants. Remove settings from `metadata.php` and admin template entirely.

---

## Core Requirements

| Requirement | Description |
|-------------|-------------|
| **TDD-First** | Write failing tests first, then implementation |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **DRY** | Don't Repeat Yourself - extract common patterns |
| **Clean Code** | Meaningful names, small functions (15-25 lines), no else expressions (use early returns) |
| **PSR-12** | PHP coding style standard |

**Testing Requirements:**
- All changes must pass pre-commit checks: `./bin/pre-commit-check.sh`
- Unit tests: `docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit`

---

## Tasks

### Task 1: Create StatusMappingConfig Class (TDD)

**File:** `src/Stripe/Config/StatusMappingConfig.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Config;

/**
 * Status mapping configuration for Stripe payment states to OXID order statuses.
 *
 * Maps Stripe payment lifecycle states to OXID's OXTRANSSTATUS values.
 * These are static mappings that should only change if Stripe SDK changes.
 *
 * OXID OXTRANSSTATUS values:
 * - 'NOT_FINISHED' - Order not finished
 * - 'OK' - Payment successful
 * - 'ERROR' - Payment error
 * - 'PENDING' - Payment pending
 * - 'PROBLEMS' - Payment has problems
 *
 * @since Sprint 29
 */
final class StatusMappingConfig
{
    /**
     * OXID status when Stripe payment is pending authorization.
     */
    public const STRIPE_PENDING = 'PENDING';

    /**
     * OXID status when Stripe payment is processing/authorized.
     */
    public const STRIPE_PROCESSING = 'OK';

    /**
     * OXID status when Stripe payment is cancelled.
     */
    public const STRIPE_CANCELLED = 'ERROR';

    /**
     * Get all status mappings as array.
     *
     * @return array<string, string> Map of stripe state => OXID status
     */
    public static function getAll(): array
    {
        return [
            'pending' => self::STRIPE_PENDING,
            'processing' => self::STRIPE_PROCESSING,
            'cancelled' => self::STRIPE_CANCELLED,
        ];
    }

    /**
     * Get OXID status for a Stripe state.
     *
     * @param string $stripeState Stripe payment state (pending, processing, cancelled)
     * @return string|null OXID status or null if not mapped
     */
    public static function getOxidStatus(string $stripeState): ?string
    {
        return self::getAll()[$stripeState] ?? null;
    }
}
```

**Tests to write first:**

```php
// tests/Unit/Stripe/Config/StatusMappingConfigTest.php

public function testPendingStatusIsPending(): void
public function testProcessingStatusIsOk(): void
public function testCancelledStatusIsError(): void
public function testGetAllReturnsAllMappings(): void
public function testGetOxidStatusReturnsMappedValue(): void
public function testGetOxidStatusReturnsNullForUnknownState(): void
public function testConstantsAreValidOxidStatuses(): void
```

---

### Task 2: Remove Settings from metadata.php

**File:** `metadata.php`

Remove these lines from the `settings` array:

```php
// REMOVE:
['group' => 'STRIPE_STATUS_MAPPING', 'name' => 'sStripeStatusPending', 'type' => 'select', 'value' => '', 'position' => 50],
['group' => 'STRIPE_STATUS_MAPPING', 'name' => 'sStripeStatusProcessing', 'type' => 'select', 'value' => '', 'position' => 60],
['group' => 'STRIPE_STATUS_MAPPING', 'name' => 'sStripeStatusCancelled', 'type' => 'select', 'value' => '', 'position' => 70],
```

---

### Task 3: Remove Status Mapping from Admin Template

**File:** `views/twig/extensions/themes/default/module_config.html.twig`

Remove this entire block:

```twig
{% if module_var == 'sStripeStatusPending' or module_var == 'sStripeStatusProcessing' or module_var == 'sStripeStatusCancelled' %}
    <dl>
        ...
    </dl>
{% elseif ... %}
```

---

### Task 4: Update Code Using Status Mappings

Find and replace all usages of:
- `$this->moduleConfig->getStatusPending()` → `StatusMappingConfig::STRIPE_PENDING`
- `$this->moduleConfig->getStatusProcessing()` → `StatusMappingConfig::STRIPE_PROCESSING`
- `$this->moduleConfig->getStatusCancelled()` → `StatusMappingConfig::STRIPE_CANCELLED`

**Files to check:**
- `ModuleConfigurationService.php` - remove getter methods
- Any webhook handlers or order state services using these values

---

### Task 5: Update Module Configuration YAML Files

**Files:**
- `recipe/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml`
- `source/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml` (active config)

Remove `moduleSettings` entries for:
- `sStripeStatusPending`
- `sStripeStatusProcessing`
- `sStripeStatusCancelled`

---

### Task 6: Clean Up ModuleConfigurationService

**File:** `src/Stripe/Service/ModuleConfigurationService.php`

Remove these methods (no longer needed):
- `getStatusPending(): string`
- `getStatusProcessing(): string`
- `getStatusCancelled(): string`

---

## Test Commands

```bash
# Run specific test file
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Config/StatusMappingConfigTest.php

# Run all unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Pre-commit checks
./bin/pre-commit-check.sh
```

---

## Verification

After completion:
1. All 606+ tests pass
2. Pre-commit checks pass (PHPCS, PHPStan, PHPMD)
3. Module activates without errors
4. Admin module config page loads without status mapping fields
5. No references to removed settings in codebase

---

## Files Changed

| Action | File |
|--------|------|
| CREATE | `src/Stripe/Config/StatusMappingConfig.php` |
| CREATE | `tests/Unit/Stripe/Config/StatusMappingConfigTest.php` |
| MODIFY | `metadata.php` |
| MODIFY | `views/twig/extensions/themes/default/module_config.html.twig` |
| MODIFY | `src/Stripe/Service/ModuleConfigurationService.php` |
| MODIFY | `recipe/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml` |

---

## Acceptance Criteria

- [ ] `StatusMappingConfig` class created with constants
- [ ] Unit tests for all constants and helper methods
- [ ] Settings removed from `metadata.php`
- [ ] Template block removed from admin config
- [ ] `ModuleConfigurationService` getter methods removed
- [ ] All code using old getters updated to use constants
- [ ] YAML config files cleaned up
- [ ] All tests pass
- [ ] Pre-commit checks pass
