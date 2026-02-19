# SPRINT-19: Fix Factory Type Issues

**Priority:** LOW
**Estimated Effort:** 15min
**Impact:** PHPStan compliance
**Decision:** Fail-fast - let RuntimeException propagate (confirmed)

---

## Problem Statement

Two factory classes have PHPStan type errors:

**EventFileLoggerFactory.php:34**
```php
$shopDir = Registry::getConfig()->getConfigParam('sShopDir');
$logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;
// PHPStan: Parameter #1 $string of function rtrim expects string, mixed given.
```

**ReconciliationFileLoggerFactory.php:34**
```php
$shopDir = Registry::getConfig()->getConfigParam('sShopDir');
$logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;
// PHPStan: Parameter #1 $string of function rtrim expects string, mixed given.
```

The issue: `getConfigParam()` returns `mixed`, but `rtrim()` expects `string`.

---

## Requirements

### R1: Add type validation before rtrim()
- Check if `$shopDir` is string
- Throw `RuntimeException` if not configured

### R2: Consistent with existing factories
- Match pattern used in Sprint 15 (RequestFileLoggerFactory)
- Match pattern used in Sprint 16 (WebhookFileLoggerFactory)

### R3: All tests must pass
- PHPStan level 6
- PHPCS PSR-12

---

## Implementation

### Fix EventFileLoggerFactory

```php
// src/Stripe/Service/Factory/EventFileLoggerFactory.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Factory for creating the event system file logger.
 *
 * Sprint 19: Fixed type validation for PHPStan compliance.
 *
 * @since Sprint 25
 */
final class EventFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_events.log';

    /**
     * Create the event file logger.
     *
     * @return FileLoggerInterface
     * @throws \RuntimeException If shop directory not configured
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');

        if (!is_string($shopDir)) {
            throw new \RuntimeException('Shop directory not configured');
        }

        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

        return new FileLogger($logFilePath, 'EVENT');
    }
}
```

### Fix ReconciliationFileLoggerFactory

```php
// src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php

<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Factory for creating the reconciliation file logger.
 *
 * Sprint 19: Fixed type validation for PHPStan compliance.
 *
 * @since Sprint 25
 */
final class ReconciliationFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_reconciliation.log';

    /**
     * Create the reconciliation file logger.
     *
     * @return FileLoggerInterface
     * @throws \RuntimeException If shop directory not configured
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');

        if (!is_string($shopDir)) {
            throw new \RuntimeException('Shop directory not configured');
        }

        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

        return new FileLogger($logFilePath, 'RECONCILIATION');
    }
}
```

---

## Files to Modify

| File | Change |
|------|--------|
| `src/Stripe/Service/Factory/EventFileLoggerFactory.php` | Add type check before `rtrim()` |
| `src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php` | Add type check before `rtrim()` |

---

## Verification

```bash
# Run PHPStan specifically on these files
docker compose exec php php vendor/bin/phpstan analyse \
    extensions/stripe/src/Stripe/Service/Factory/EventFileLoggerFactory.php \
    extensions/stripe/src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php \
    --level 6

# Expected: No errors

# Run full pre-commit check
./bin/pre-commit-check.sh --full

# Expected: All checks pass
# - PHPStan: 0 errors
# - PHPUnit: All tests pass
# - PHPCS: No style violations
```

---

## Acceptance Criteria

- [ ] `EventFileLoggerFactory` validates `$shopDir` is string before `rtrim()`
- [ ] `ReconciliationFileLoggerFactory` validates `$shopDir` is string before `rtrim()`
- [ ] Both throw `RuntimeException` if shop dir not configured
- [ ] PHPStan passes with no errors on these files
- [ ] `./bin/pre-commit-check.sh --full` passes with 0 PHPStan errors
