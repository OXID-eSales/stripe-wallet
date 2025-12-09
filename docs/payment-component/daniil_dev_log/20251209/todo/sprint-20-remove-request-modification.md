# Sprint 20: Remove $_REQUEST Modification

**Date:** 2025-12-09
**Priority:** HIGH
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 2 hours

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write session service tests first |
| **SOLID-SRP** | Session service handles delivery address hash |
| **SOLID-DIP** | Handler depends on session service interface |
| **DI** | Session service injected via constructor |
| **Security** | No superglobal modification |
| **Clean Code** | Explicit data flow |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`
**Line:** 302

```php
$_REQUEST['sDeliveryAddressMD5'] = $deliveryHash;
```

**Impact:**
1. **Security anti-pattern** - Modifying superglobals
2. **Breaks testability** - Cannot mock $_REQUEST easily
3. **Implicit data flow** - Hard to trace where value comes from
4. **Side effects** - Affects global state unexpectedly

---

## Root Cause Analysis

The handler needs to restore the delivery address hash to make OXID's address validation pass. Instead of using a proper service, it directly modifies the superglobal.

**Current Flow:**
1. User goes to Stripe checkout
2. Handler stores delivery hash in session/metadata
3. User returns from Stripe
4. Handler modifies `$_REQUEST` to restore hash
5. OXID reads from `$_REQUEST` for validation

---

## Solution Design

### Option A: Use Session Service (Recommended)

Create a dedicated service for delivery address hash management.

### Option B: Use Request Service

Use OXID's Request service to set the value properly.

**Decision:** Option A - Session Service provides better separation and testability.

### Phase 1: TDD - Write Failing Tests First

**New Test File:** `tests/Unit/Stripe/Service/DeliveryAddressHashServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashService;
use OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashServiceInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class DeliveryAddressHashServiceTest extends TestCase
{
    private SessionInterface&MockObject $session;
    private DeliveryAddressHashService $service;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
        $this->service = new DeliveryAddressHashService($this->session);
    }

    /**
     * @test
     * LSP: Service implements interface
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(
            DeliveryAddressHashServiceInterface::class,
            $this->service
        );
    }

    /**
     * @test
     * SRP: Stores hash in session
     */
    public function storesHashInSession(): void
    {
        // Arrange
        $hash = 'abc123def456';

        $this->session
            ->expects($this->once())
            ->method('setVariable')
            ->with('sDeliveryAddressMD5', $hash);

        // Act
        $this->service->storeDeliveryAddressHash($hash);
    }

    /**
     * @test
     * SRP: Retrieves hash from session
     */
    public function retrievesHashFromSession(): void
    {
        // Arrange
        $expectedHash = 'abc123def456';

        $this->session
            ->expects($this->once())
            ->method('getVariable')
            ->with('sDeliveryAddressMD5')
            ->willReturn($expectedHash);

        // Act
        $result = $this->service->getDeliveryAddressHash();

        // Assert
        $this->assertSame($expectedHash, $result);
    }

    /**
     * @test
     * SRP: Restores hash for OXID validation
     */
    public function restoresHashForValidation(): void
    {
        // Arrange
        $hash = 'abc123def456';

        $this->session
            ->expects($this->once())
            ->method('getVariable')
            ->with('sDeliveryAddressMD5')
            ->willReturn($hash);

        // Act
        $result = $this->service->restoreHashForValidation();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     * Returns false when no hash stored
     */
    public function returnsFalseWhenNoHashStored(): void
    {
        // Arrange
        $this->session
            ->expects($this->once())
            ->method('getVariable')
            ->willReturn(null);

        // Act
        $result = $this->service->restoreHashForValidation();

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     * SRP: Clears hash after restoration
     */
    public function clearsHashAfterRestoration(): void
    {
        // Arrange
        $hash = 'abc123def456';

        $this->session
            ->method('getVariable')
            ->willReturn($hash);

        $this->session
            ->expects($this->once())
            ->method('deleteVariable')
            ->with('sDeliveryAddressMD5');

        // Act
        $this->service->restoreHashForValidation();
    }
}
```

### Phase 2: Create Interface

**New File:** `src/Stripe/Service/DeliveryAddressHashServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

/**
 * Interface for delivery address hash management
 *
 * SOLID Principles:
 * - SRP: Single responsibility - delivery address hash lifecycle
 * - ISP: Focused interface with hash operations only
 * - DIP: Handlers depend on this abstraction
 */
interface DeliveryAddressHashServiceInterface
{
    /**
     * Store delivery address hash before redirect to payment provider
     *
     * @param string $hash MD5 hash of delivery address
     */
    public function storeDeliveryAddressHash(string $hash): void;

    /**
     * Get stored delivery address hash
     *
     * @return string|null Hash or null if not stored
     */
    public function getDeliveryAddressHash(): ?string;

    /**
     * Restore hash for OXID's address validation
     *
     * Sets the hash in a way that OXID can read during checkout validation.
     *
     * @return bool True if hash was restored, false if no hash stored
     */
    public function restoreHashForValidation(): bool;

    /**
     * Clear stored hash
     */
    public function clearDeliveryAddressHash(): void;
}
```

### Phase 3: Create Implementation

**New File:** `src/Stripe/Service/DeliveryAddressHashService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Session\SessionInterface;

/**
 * Service for delivery address hash management
 *
 * SOLID Principles:
 * - SRP: Only handles delivery address hash lifecycle
 * - OCP: Open for extension via interface
 * - DIP: Depends on Session abstraction
 *
 * Security: No direct superglobal modification
 */
final class DeliveryAddressHashService implements DeliveryAddressHashServiceInterface
{
    private const SESSION_KEY = 'sDeliveryAddressMD5';
    private const SESSION_KEY_STORED = 'stripe_delivery_hash_stored';

    public function __construct(
        private readonly SessionInterface $session
    ) {
    }

    public function storeDeliveryAddressHash(string $hash): void
    {
        $this->session->setVariable(self::SESSION_KEY_STORED, $hash);
    }

    public function getDeliveryAddressHash(): ?string
    {
        $hash = $this->session->getVariable(self::SESSION_KEY_STORED);

        return is_string($hash) ? $hash : null;
    }

    public function restoreHashForValidation(): bool
    {
        $hash = $this->getDeliveryAddressHash();

        if ($hash === null) {
            return false;
        }

        // Set in session where OXID expects it
        $this->session->setVariable(self::SESSION_KEY, $hash);

        // Clear our stored copy
        $this->clearDeliveryAddressHash();

        return true;
    }

    public function clearDeliveryAddressHash(): void
    {
        $this->session->deleteVariable(self::SESSION_KEY_STORED);
    }
}
```

### Phase 4: Register Service

**File:** `services.yaml`

```yaml
services:
    OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashServiceInterface:
        class: OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashService
        arguments:
            - '@OxidEsales\EshopCommunity\Internal\Framework\Session\SessionInterface'
```

### Phase 5: Update Handler

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

```php
// BEFORE (security anti-pattern):
private function restoreDeliveryAddressHash(string $deliveryHash): void
{
    $_REQUEST['sDeliveryAddressMD5'] = $deliveryHash;
}

// AFTER (using service):
// Constructor:
public function __construct(
    // ... existing dependencies ...
    private readonly DeliveryAddressHashServiceInterface $deliveryAddressHashService
) {
}

// Usage:
private function restoreDeliveryAddressHash(string $deliveryHash): void
{
    $this->deliveryAddressHashService->storeDeliveryAddressHash($deliveryHash);
    $this->deliveryAddressHashService->restoreHashForValidation();
}
```

Also update `StripeContractCreationHandler` to store hash before redirect:

```php
// Store hash before redirect
$deliveryHash = $this->calculateDeliveryAddressHash();
$this->deliveryAddressHashService->storeDeliveryAddressHash($deliveryHash);
```

---

## Implementation Steps

### Step 1: Write Tests (TDD - RED)

```bash
# Create test file
mkdir -p tests/Unit/Stripe/Service
touch tests/Unit/Stripe/Service/DeliveryAddressHashServiceTest.php

# Run tests - should fail
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Service/DeliveryAddressHashServiceTest.php"
```

### Step 2: Create Interface and Service (TDD - GREEN)

```bash
# Create files
touch src/Stripe/Service/DeliveryAddressHashServiceInterface.php
touch src/Stripe/Service/DeliveryAddressHashService.php

# Run tests - should pass
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Service/DeliveryAddressHashServiceTest.php"
```

### Step 3: Register Service and Update Handlers

```bash
# Update services.yaml
# Update StripeCheckoutReturnHandler
# Update StripeContractCreationHandler
# Run all tests

docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

### Step 4: Quality Checks

```bash
# PHPStan
composer phpstan

# PHPCS
composer phpcs

# Pre-commit check
./bin/pre-commit-check.sh

# E2E test
cd tests/e2e/playwright && npx playwright test tests/checkout/
```

---

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `src/Stripe/Service/DeliveryAddressHashServiceInterface.php` | Service interface |
| `src/Stripe/Service/DeliveryAddressHashService.php` | Service implementation |
| `tests/Unit/Stripe/Service/DeliveryAddressHashServiceTest.php` | Service tests |

### Modified Files

| File | Change |
|------|--------|
| `services.yaml` | Register service |
| `StripeCheckoutReturnHandler.php` | Use service instead of $_REQUEST |
| `StripeContractCreationHandler.php` | Store hash using service |

---

## Verification Checklist

- [ ] DeliveryAddressHashServiceInterface created
- [ ] DeliveryAddressHashService implements interface
- [ ] Service registered in services.yaml
- [ ] No `$_REQUEST` modification in codebase
- [ ] All unit tests pass
- [ ] E2E checkout flow works with delivery address

### Verification Commands

```bash
# Verify no $_REQUEST modification
grep -rn "\$_REQUEST\[" src/
# Should return: nothing

# Verify no $_POST modification
grep -rn "\$_POST\[" src/
grep -rn "\$_GET\[" src/
# Should return: nothing (or only reads, not writes)
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking address validation | High | E2E tests with delivery address |
| Session key conflicts | Low | Use prefixed session key |
| Timing issues | Medium | Test full checkout flow |

---

## Success Criteria

1. ✅ No `$_REQUEST` modification in codebase
2. ✅ Dedicated service handles delivery hash
3. ✅ Hash properly restored for OXID validation
4. ✅ All existing tests pass
5. ✅ E2E checkout with delivery address works

---

## Related Issues

- CODE_REVIEW.md Section 4.4 (HIGH: Direct $_REQUEST Modification)
- CODE_REVIEW.md Section 4.8 (MEDIUM: Session Manipulation in Multiple Handlers)

---

**Last Updated:** 2025-12-09
