# SPRINT-27: Extract Services to payment-component

**Status:** TODO
**Priority:** HIGH
**Effort:** 4-6h

---

## Objective

Move provider-agnostic service interfaces from Stripe to payment-component.
**CRITICAL**: payment-component must NOT depend on Stripe. Stripe depends on payment-component.

---

## Core Requirements

All code must follow these principles:

| Principle | Description | Application |
|-----------|-------------|-------------|
| **TDD-First** | Write failing tests first, then implementation | Create payment-component tests before moving interfaces |
| **Single Responsibility (S)** | Each class has one reason to change | Each service interface has one focused purpose |
| **Open/Closed (O)** | Open for extension, closed for modification | Use interfaces so providers can implement |
| **Liskov Substitution (L)** | Subtypes must be substitutable for base types | Stripe implementations must work where base interfaces expected |
| **Interface Segregation (I)** | No client should depend on methods it doesn't use | Small, focused interfaces |
| **Dependency Inversion (D)** | Depend on abstractions, not concretions | Stripe services implement payment-component interfaces |
| **DRY** | Don't Repeat Yourself | Shared logic in payment-component |
| **Clean Code** | Meaningful names, small functions (15-25 lines), no else (early returns) | Clear parameter names, focused methods |
| **PSR-12** | PHP coding style standard | Enforced by phpcs |

---

## Testing Requirements

### Testing Strategy

1. **TDD Workflow:**
   - Write test for new payment-component interface
   - Run test (should fail)
   - Implement interface
   - Run test (should pass)
   - Refactor if needed

2. **Pre-commit Validation (MANDATORY after each change):**
   ```bash
   ./bin/pre-commit-check.sh
   ```

3. **Full Test Suites:**
   ```bash
   # payment-component tests
   docker compose exec php php vendor/bin/phpunit -c extensions/payment-component/tests/phpunit.xml --testsuite Unit

   # Stripe tests
   docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
   ```

### Tests to Update

| Test File | Module | Changes Required |
|-----------|--------|------------------|
| `CaptureServiceTest.php` | Stripe | Update import to use base interface, verify Liskov substitution |
| `CancelAuthorizationServiceTest.php` | Stripe | Update import to use base interface |
| `RefundServiceTest.php` | Stripe | Update import, rename param in tests |
| `CheckoutReturnServiceTest.php` | Stripe | Update import to use base interface |
| `ContractMetadataServiceTest.php` | Stripe → payment-component | **MOVE** test to payment-component |
| `DeliveryAddressHashServiceTest.php` | Stripe → payment-component | **MOVE** test to payment-component |
| `RequestLogServiceTest.php` | Stripe | Update import to use base interface |

### Tests to Create (payment-component)

| New Test File | Purpose |
|---------------|---------|
| `CaptureServiceInterfaceTest.php` | Test interface contract compliance |
| `CancelAuthorizationServiceInterfaceTest.php` | Test interface contract compliance |
| `RefundServiceInterfaceTest.php` | Test interface contract compliance |
| `HostedCheckoutReturnServiceInterfaceTest.php` | Test interface contract compliance |
| `ContractMetadataServiceTest.php` | Test concrete implementation |
| `DeliveryAddressHashServiceTest.php` | Test concrete implementation |
| `RequestLogServiceInterfaceTest.php` | Test interface contract compliance |
| `SessionAdapterInterfaceTest.php` | Test session adapter interface |
| `SessionAdapterTest.php` | Test session adapter implementation |
| `AbstractFileLoggerFactoryTest.php` | Test abstract factory with mock subclass |
| `OrderPaymentCompletedHandlerTest.php` | **MOVE** from Stripe to payment-component |

### Tests to Update (Stripe)

| Test File | Change |
|-----------|--------|
| `EventFileLoggerFactoryTest.php` | Update to test inheritance from AbstractFileLoggerFactory |
| `ReconciliationFileLoggerFactoryTest.php` | Update to test inheritance from AbstractFileLoggerFactory |
| `RequestFileLoggerFactoryTest.php` | Update to test inheritance from AbstractFileLoggerFactory |

---

## Analysis Summary

| Service | Current Location | Action | Notes |
|---------|------------------|--------|-------|
| `CheckoutReturnServiceInterface` | Stripe | Make generic | Rename param: checkoutSessionId → providerSessionId |
| `CaptureServiceInterface` | Stripe | Make generic | Rename param: paymentIntentId → providerPaymentId |
| `CancelAuthorizationServiceInterface` | Stripe | Make generic | Rename param: paymentIntentId → providerPaymentId |
| `CheckoutSessionServiceInterface` | Stripe | **STAY** | Too Stripe-specific (buildLineItems returns Stripe format) |
| `ContractMetadataServiceInterface` | Stripe | Move as-is | Already provider-agnostic |
| `ContractMetadataService` | Stripe | Move as-is | Uses OXID Registry (acceptable for OXID extension) |
| `DeliveryAddressHashServiceInterface` | Stripe | Move as-is | OXID-specific, not payment-provider-specific |
| `DeliveryAddressHashService` | Stripe | Move as-is | Already provider-agnostic |
| `RefundServiceInterface` | Stripe | Make generic | Rename param: chargeId → providerChargeId |
| `RequestLogServiceInterface` | Stripe | Move as-is | Already provider-agnostic |
| `WebhookLogService` | Stripe | **STAY** | Different purpose than payment-component version |
| `WebhookLogServiceInterface` | Stripe | **STAY** | Different purpose than payment-component version |
| `OrderPaymentCompletedHandler` | Stripe | Move as-is | Already uses only payment-component interfaces |
| `SessionAdapterInterface` | Stripe | Move as-is | OXID-specific but not provider-specific |
| `EventFileLoggerFactory` | Stripe | Extract abstract | Abstract in payment-component, Stripe-specific extension |
| `ReconciliationFileLoggerFactory` | Stripe | Extract abstract | Abstract in payment-component, Stripe-specific extension |
| `RequestFileLoggerFactory` | Stripe | Extract abstract | Abstract in payment-component, Stripe-specific extension |

---

## Architecture Principles (from handler-abstraction-pattern.md)

### Interface Hierarchy Pattern

```
PaymentComponentInterface (payment-component)
    └── StripeImplementation (stripe)
```

### Liskov Substitution Validation

When Stripe implements payment-component interfaces:
- Any code expecting `PaymentComponent\Service\CaptureServiceInterface`
- Must work correctly with `Stripe\Service\CaptureService`

### Dependency Inversion

```
❌ WRONG: payment-component depends on Stripe
✅ CORRECT: Stripe depends on payment-component
```

---

## Implementation Plan

### Phase 1: Create Provider-Agnostic Interfaces in payment-component

Create new interfaces in `payment-component/src/Service/`:

#### 1.1 CaptureServiceInterface.php
```php
namespace OxidEsales\PaymentComponent\Service;

interface CaptureServiceInterface
{
    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResult;

    public function processDirectCapture(
        string $providerPaymentId,  // Was: paymentIntentId
        ?float $amount,
        array $metadata
    ): CaptureResult;
}
```

#### 1.2 CancelAuthorizationServiceInterface.php
```php
namespace OxidEsales\PaymentComponent\Service;

interface CancelAuthorizationServiceInterface
{
    public function cancelAuthorization(
        string $providerPaymentId,  // Was: paymentIntentId
        ?string $reason = null
    ): CancellationResult;
}
```

#### 1.3 RefundServiceInterface.php
```php
namespace OxidEsales\PaymentComponent\Service;

interface RefundServiceInterface
{
    public function processFullRefund(
        string $orderId,
        ?string $providerPaymentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin'
    ): RefundResult;

    public function processRefundByCharge(
        string $providerChargeId,  // Was: chargeId
        ?string $reason = null,
        ?array $metadata = null
    ): RefundResult;
}
```

#### 1.4 HostedCheckoutReturnServiceInterface.php (New name)
```php
namespace OxidEsales\PaymentComponent\Service;

interface HostedCheckoutReturnServiceInterface
{
    public function validateReturn(
        string $providerSessionId,
        string $contractId,
        string $contractToken
    ): CheckoutReturnResult;

    public function getSessionDetails(string $providerSessionId): ?array;
}
```

#### 1.5-1.7 Move as-is (just namespace change)
- ContractMetadataServiceInterface
- DeliveryAddressHashServiceInterface
- RequestLogServiceInterface

#### 1.8 SessionAdapterInterface.php (Move as-is)
```php
namespace OxidEsales\PaymentComponent\Adapter;

use OxidEsales\Eshop\Application\Model\Basket;

interface SessionAdapterInterface
{
    public function getSessionId(): string;
    public function getBasket(): ?Basket;
    public function setVariable(string $name, mixed $value): void;
    public function getVariable(string $name): mixed;
}
```

#### 1.9 Abstract FileLoggerFactory (Template Method Pattern)
```php
namespace OxidEsales\PaymentComponent\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Abstract factory for creating file loggers.
 * Subclasses define the log file path and prefix.
 */
abstract class AbstractFileLoggerFactory
{
    /**
     * Get the log file path relative to shop directory.
     */
    abstract protected function getLogFile(): string;

    /**
     * Get the log entry prefix.
     */
    abstract protected function getPrefix(): string;

    /**
     * Create the file logger.
     *
     * @throws \RuntimeException If shop directory not configured
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');

        if (!is_string($shopDir)) {
            throw new \RuntimeException('Shop directory not configured');
        }

        $logFilePath = Path::join(rtrim($shopDir, '/'), $this->getLogFile());

        return new FileLogger($logFilePath, $this->getPrefix());
    }
}
```

### Phase 2: Move Concrete Services to payment-component

- `ContractMetadataService.php` → payment-component
- `DeliveryAddressHashService.php` → payment-component
- `OrderPaymentCompletedHandler.php` → payment-component (already provider-agnostic)
- `SessionAdapter.php` → payment-component (implements SessionAdapterInterface)

### Phase 2.5: Create Stripe-Specific Logger Factories

Stripe logger factories extend the abstract factory:

```php
// src/Stripe/Service/Factory/EventFileLoggerFactory.php
namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentComponent\Service\Factory\AbstractFileLoggerFactory;

final class EventFileLoggerFactory extends AbstractFileLoggerFactory
{
    protected function getLogFile(): string
    {
        return 'log/osc/stripe_events.log';
    }

    protected function getPrefix(): string
    {
        return 'EVENT';
    }
}

// src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php
namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentComponent\Service\Factory\AbstractFileLoggerFactory;

final class ReconciliationFileLoggerFactory extends AbstractFileLoggerFactory
{
    protected function getLogFile(): string
    {
        return 'log/osc/stripe_reconciliation.log';
    }

    protected function getPrefix(): string
    {
        return 'RECONCILE';
    }
}

// src/Stripe/Service/Factory/RequestFileLoggerFactory.php
namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentComponent\Service\Factory\AbstractFileLoggerFactory;

final class RequestFileLoggerFactory extends AbstractFileLoggerFactory
{
    protected function getLogFile(): string
    {
        return 'log/osc/stripe_requests.log';
    }

    protected function getPrefix(): string
    {
        return 'REQUEST';
    }
}
```

### Phase 3: Update Stripe to Use payment-component Interfaces

Stripe interfaces extend payment-component interfaces OR are deleted (using base directly).

### Phase 4: Update Tests

- Move tests for moved services
- Update imports in remaining tests
- Add interface compliance tests

### Phase 5: Cleanup & Verify

```bash
# After EACH phase, run:
./bin/pre-commit-check.sh

# Full verification:
docker compose exec php php vendor/bin/phpunit -c extensions/payment-component/tests/phpunit.xml --testsuite Unit
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Module activation test:
docker compose exec php bin/oe-console oe:module:deactivate oe_payments_stripe_wallet
docker compose exec php rm -rf var/cache/*
docker compose exec php bin/oe-console oe:module:activate oe_payments_stripe_wallet
```

---

## Files to Create (payment-component)

| File | Purpose |
|------|---------|
| `src/Service/CaptureServiceInterface.php` | Generic capture interface |
| `src/Service/CancelAuthorizationServiceInterface.php` | Generic cancel auth interface |
| `src/Service/RefundServiceInterface.php` | Generic refund interface |
| `src/Service/HostedCheckoutReturnServiceInterface.php` | Generic hosted checkout return |
| `src/Service/ContractMetadataServiceInterface.php` | Contract metadata |
| `src/Service/ContractMetadataService.php` | Contract metadata implementation |
| `src/Service/DeliveryAddressHashServiceInterface.php` | Delivery address hash |
| `src/Service/DeliveryAddressHashService.php` | Delivery address hash implementation |
| `src/Service/RequestLogServiceInterface.php` | Request logging |
| `src/Service/Result/CheckoutReturnResult.php` | Checkout return result DTO |
| `src/Adapter/SessionAdapterInterface.php` | Session operations interface |
| `src/Adapter/SessionAdapter.php` | Session adapter implementation |
| `src/Service/Factory/AbstractFileLoggerFactory.php` | Abstract logger factory (Template Method) |
| `src/EventSystem/Handler/OrderPaymentCompletedHandler.php` | Handler for ContractFulfilledEvent |

## Files to Modify (Stripe)

| File | Change |
|------|--------|
| `src/Stripe/Service/CaptureServiceInterface.php` | Extend base or delete |
| `src/Stripe/Service/CaptureService.php` | Implement base interface |
| `src/Stripe/Service/CancelAuthorizationServiceInterface.php` | Extend base or delete |
| `src/Stripe/Service/CancelAuthorizationService.php` | Implement base interface |
| `src/Stripe/Service/RefundServiceInterface.php` | Extend base or delete |
| `src/Stripe/Service/RefundService.php` | Implement base interface |
| `src/Stripe/Service/CheckoutReturnServiceInterface.php` | Extend HostedCheckoutReturnServiceInterface |
| `src/Stripe/Service/CheckoutReturnService.php` | Implement base interface |
| `services.yaml` | Update service definitions |

## Files to Delete (Stripe - moved to payment-component)

| File | Reason |
|------|--------|
| `src/Stripe/Service/ContractMetadataServiceInterface.php` | Moved |
| `src/Stripe/Service/ContractMetadataService.php` | Moved |
| `src/Stripe/Service/DeliveryAddressHashServiceInterface.php` | Moved |
| `src/Stripe/Service/DeliveryAddressHashService.php` | Moved |
| `src/Stripe/Service/RequestLogServiceInterface.php` | Moved |
| `src/Stripe/Adapter/SessionAdapterInterface.php` | Moved |
| `src/Stripe/Adapter/SessionAdapter.php` | Moved (if exists) |
| `src/Stripe/EventSystem/Handler/OrderPaymentCompletedHandler.php` | Moved |

## Files to Refactor (Stripe - extend payment-component)

| File | Change |
|------|--------|
| `src/Stripe/Service/Factory/EventFileLoggerFactory.php` | Extend AbstractFileLoggerFactory |
| `src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php` | Extend AbstractFileLoggerFactory |
| `src/Stripe/Service/Factory/RequestFileLoggerFactory.php` | Extend AbstractFileLoggerFactory |

## Files Staying in Stripe

| File | Reason |
|------|--------|
| `CheckoutSessionServiceInterface.php` | Too Stripe-specific (buildLineItems) |
| `CheckoutSessionService.php` | Uses Stripe SDK directly |
| `WebhookLogServiceInterface.php` | Different purpose (file logging vs DB) |
| `WebhookLogService.php` | Uses FileLogger |

---

## Acceptance Criteria

1. [ ] All moved interfaces are in `OxidEsales\PaymentComponent\Service` namespace
2. [ ] payment-component has NO references to Stripe namespace (verify with grep)
3. [ ] Stripe services implement/extend payment-component interfaces
4. [ ] All payment-component unit tests pass
5. [ ] All Stripe unit tests pass
6. [ ] Pre-commit checks pass: `./bin/pre-commit-check.sh`
7. [ ] Module activates and deactivates correctly
8. [ ] Liskov Substitution: Stripe implementations work where base interfaces expected
9. [ ] `OrderPaymentCompletedHandler` moved to payment-component
10. [ ] `SessionAdapterInterface` moved to payment-component
11. [ ] `AbstractFileLoggerFactory` created in payment-component
12. [ ] Stripe logger factories extend `AbstractFileLoggerFactory`
13. [ ] Template Method Pattern: Subclasses only override `getLogFile()` and `getPrefix()`

---

## Verification Checklist

After completion, run:

```bash
# 1. No Stripe references in payment-component
grep -r "Stripe" extensions/payment-component/src/ && echo "FAIL: Stripe reference found" || echo "PASS"

# 2. Pre-commit checks
./bin/pre-commit-check.sh

# 3. payment-component tests
docker compose exec php php vendor/bin/phpunit -c extensions/payment-component/tests/phpunit.xml --testsuite Unit

# 4. Stripe tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# 5. Module activation
docker compose exec php bin/oe-console oe:module:deactivate oe_payments_stripe_wallet
docker compose exec php rm -rf var/cache/*
docker compose exec php bin/oe-console oe:module:activate oe_payments_stripe_wallet
```
