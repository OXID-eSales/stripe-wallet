# Sprint 21: Refactor Fat Handlers (Extract RefundService)

**Date:** 2025-12-09
**Priority:** MEDIUM
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 4 hours
**Depends On:** Sprint 18, 19 (service extraction pattern)

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write service tests first |
| **SOLID-SRP** | Handler: event handling. Service: refund orchestration |
| **SOLID-OCP** | Service can be extended for new refund types |
| **SOLID-DIP** | Handler depends on service interface |
| **DI** | All dependencies injected via constructor |
| **Clean Code** | Methods ≤ 25 lines, no else expressions |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`
**Line Count:** ~250 lines

**Handler responsibilities (should be 1-2):**
1. Load order
2. Extract PaymentIntent ID
3. Get charge from Stripe
4. Build refund params
5. Execute Stripe refund
6. Update order
7. Log transaction

**Documentation states:** "Handlers encapsulate all state transitions"
**Reality:** Handler contains excessive business logic

---

## Root Cause Analysis

1. **No orchestration service** - Handler does everything
2. **Convenience over design** - Faster to add code to handler
3. **No code review** - Size not caught during review

---

## Solution Design

### Target Architecture

```
StripeRefundRequestHandler (thin)
    │
    └──► RefundOrchestrator
            │
            ├──► RefundService (business logic)
            │       ├── validateRefundRequest()
            │       ├── calculateRefundAmount()
            │       └── buildRefundParams()
            │
            ├──► StripeAdapter (Stripe API)
            │       └── createRefund()
            │
            └──► OrderPaymentStateService (Sprint 16)
                    └── updateRefundState()
```

### Phase 1: TDD - Write Failing Tests First

**New Test File:** `tests/Unit/Stripe/Service/RefundServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\RefundService;
use OxidSolutionCatalysts\Payments\Stripe\Service\RefundServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class RefundServiceTest extends TestCase
{
    private PaymentAdapterInterface&MockObject $adapter;
    private OrderPaymentStateServiceInterface&MockObject $orderPaymentStateService;
    private RefundService $service;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->orderPaymentStateService = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->service = new RefundService(
            $this->adapter,
            $this->orderPaymentStateService
        );
    }

    /**
     * @test
     * LSP: Service implements interface
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(
            RefundServiceInterface::class,
            $this->service
        );
    }

    /**
     * @test
     * SRP: Processes full refund
     */
    public function processesFullRefund(): void
    {
        // Arrange
        $orderId = 'order-123';
        $paymentIntentId = 'pi_123';
        $chargeId = 'ch_456';
        $amount = 9999; // cents

        $paymentIntent = $this->createPaymentIntentMock($chargeId, $amount);
        $refund = $this->createRefundMock('re_789', $amount);

        $this->adapter
            ->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with($paymentIntentId)
            ->willReturn($paymentIntent);

        $this->adapter
            ->expects($this->once())
            ->method('createRefund')
            ->with($chargeId, null) // null = full refund
            ->willReturn($refund);

        // Act
        $result = $this->service->processFullRefund($orderId, $paymentIntentId);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame($amount, $result->getRefundedAmount());
    }

    /**
     * @test
     * SRP: Processes partial refund
     */
    public function processesPartialRefund(): void
    {
        // Arrange
        $orderId = 'order-123';
        $paymentIntentId = 'pi_123';
        $chargeId = 'ch_456';
        $partialAmount = 5000;

        $paymentIntent = $this->createPaymentIntentMock($chargeId, 9999);
        $refund = $this->createRefundMock('re_789', $partialAmount);

        $this->adapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->adapter
            ->expects($this->once())
            ->method('createRefund')
            ->with($chargeId, $partialAmount)
            ->willReturn($refund);

        // Act
        $result = $this->service->processPartialRefund(
            $orderId,
            $paymentIntentId,
            $partialAmount
        );

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame($partialAmount, $result->getRefundedAmount());
    }

    /**
     * @test
     * Guards: Validates refund amount
     */
    public function rejectsInvalidRefundAmount(): void
    {
        // Arrange
        $orderId = 'order-123';
        $paymentIntentId = 'pi_123';
        $chargeId = 'ch_456';
        $capturedAmount = 5000;
        $requestedRefund = 10000; // More than captured

        $paymentIntent = $this->createPaymentIntentMock($chargeId, $capturedAmount);

        $this->adapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->adapter
            ->expects($this->never())
            ->method('createRefund');

        // Act
        $result = $this->service->processPartialRefund(
            $orderId,
            $paymentIntentId,
            $requestedRefund
        );

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('exceeds', $result->getErrorMessage());
    }

    private function createPaymentIntentMock(string $chargeId, int $amount): object
    {
        return (object) [
            'id' => 'pi_123',
            'latest_charge' => $chargeId,
            'amount' => $amount,
            'amount_received' => $amount,
        ];
    }

    private function createRefundMock(string $id, int $amount): object
    {
        return (object) [
            'id' => $id,
            'amount' => $amount,
            'status' => 'succeeded',
        ];
    }
}
```

### Phase 2: Create DTO for Refund Result

**New File:** `src/Stripe/DTO/RefundResult.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\DTO;

/**
 * Immutable result of a refund operation
 */
final class RefundResult
{
    private function __construct(
        private readonly bool $successful,
        private readonly ?string $refundId,
        private readonly ?int $refundedAmount,
        private readonly ?string $errorMessage
    ) {
    }

    public static function success(string $refundId, int $amount): self
    {
        return new self(true, $refundId, $amount, null);
    }

    public static function failure(string $errorMessage): self
    {
        return new self(false, null, null, $errorMessage);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getRefundId(): ?string
    {
        return $this->refundId;
    }

    public function getRefundedAmount(): ?int
    {
        return $this->refundedAmount;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
```

### Phase 3: Create Interface

**New File:** `src/Stripe/Service/RefundServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\DTO\RefundResult;

/**
 * Interface for refund operations
 *
 * SOLID Principles:
 * - SRP: Single responsibility - refund processing
 * - ISP: Focused interface with refund methods only
 */
interface RefundServiceInterface
{
    /**
     * Process a full refund
     *
     * @param string $orderId Order OXID
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @return RefundResult Result of the refund operation
     */
    public function processFullRefund(string $orderId, string $paymentIntentId): RefundResult;

    /**
     * Process a partial refund
     *
     * @param string $orderId Order OXID
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @param int $amountCents Amount to refund in cents
     * @return RefundResult Result of the refund operation
     */
    public function processPartialRefund(
        string $orderId,
        string $paymentIntentId,
        int $amountCents
    ): RefundResult;
}
```

### Phase 4: Create Implementation

**New File:** `src/Stripe/Service/RefundService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\DTO\RefundResult;
use Stripe\Exception\ApiErrorException;

/**
 * Service for processing Stripe refunds
 *
 * SOLID Principles:
 * - SRP: Only handles refund processing logic
 * - OCP: Open for extension via interface
 * - DIP: Depends on adapter and service abstractions
 */
final class RefundService implements RefundServiceInterface
{
    public function __construct(
        private readonly PaymentAdapterInterface $adapter,
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService
    ) {
    }

    public function processFullRefund(string $orderId, string $paymentIntentId): RefundResult
    {
        return $this->processRefund($orderId, $paymentIntentId, null);
    }

    public function processPartialRefund(
        string $orderId,
        string $paymentIntentId,
        int $amountCents
    ): RefundResult {
        return $this->processRefund($orderId, $paymentIntentId, $amountCents);
    }

    private function processRefund(
        string $orderId,
        string $paymentIntentId,
        ?int $amountCents
    ): RefundResult {
        $paymentIntent = $this->retrievePaymentIntent($paymentIntentId);

        if ($paymentIntent === null) {
            return RefundResult::failure('PaymentIntent not found');
        }

        $chargeId = $this->extractChargeId($paymentIntent);

        if ($chargeId === null) {
            return RefundResult::failure('No charge found for PaymentIntent');
        }

        if (!$this->validateRefundAmount($paymentIntent, $amountCents)) {
            return RefundResult::failure('Refund amount exceeds captured amount');
        }

        return $this->executeRefund($orderId, $chargeId, $amountCents);
    }

    private function retrievePaymentIntent(string $paymentIntentId): ?object
    {
        try {
            return $this->adapter->retrievePaymentIntent($paymentIntentId, ['charges']);
        } catch (ApiErrorException) {
            return null;
        }
    }

    private function extractChargeId(object $paymentIntent): ?string
    {
        return $paymentIntent->latest_charge ?? null;
    }

    private function validateRefundAmount(object $paymentIntent, ?int $amountCents): bool
    {
        if ($amountCents === null) {
            return true; // Full refund always valid
        }

        $capturedAmount = $paymentIntent->amount_received ?? 0;

        return $amountCents <= $capturedAmount;
    }

    private function executeRefund(
        string $orderId,
        string $chargeId,
        ?int $amountCents
    ): RefundResult {
        try {
            $refund = $this->adapter->createRefund($chargeId, $amountCents);
            $refundedAmount = $refund->amount ?? $amountCents ?? 0;

            return RefundResult::success($refund->id, $refundedAmount);
        } catch (ApiErrorException $e) {
            return RefundResult::failure($e->getMessage());
        }
    }
}
```

### Phase 5: Update Handler to Use Service

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`

```php
// BEFORE (~250 lines with all logic):
public function handle(RefundRequestEvent $event): void
{
    // Order loading
    // PaymentIntent extraction
    // Stripe API calls
    // Order updates
    // Logging
    // ... 200+ lines
}

// AFTER (~50 lines, delegation only):
public function __construct(
    private readonly RefundServiceInterface $refundService,
    private readonly OrderRepositoryInterface $orderRepository,
    private readonly LoggerInterface $logger
) {
}

public function handle(RefundRequestEvent $event): void
{
    $order = $this->loadOrder($event->getOrderId());

    if ($order === null) {
        $this->logger->warning('Order not found for refund', [
            'orderId' => $event->getOrderId()
        ]);
        return;
    }

    $result = $this->processRefund($order, $event);
    $this->logResult($event, $result);
}

private function loadOrder(string $orderId): ?OrderInterface
{
    return $this->orderRepository->findById($orderId);
}

private function processRefund(OrderInterface $order, RefundRequestEvent $event): RefundResult
{
    $paymentIntentId = $order->getTransactionId();

    if ($event->isPartialRefund()) {
        return $this->refundService->processPartialRefund(
            $order->getId(),
            $paymentIntentId,
            $event->getAmountCents()
        );
    }

    return $this->refundService->processFullRefund(
        $order->getId(),
        $paymentIntentId
    );
}

private function logResult(RefundRequestEvent $event, RefundResult $result): void
{
    if ($result->isSuccessful()) {
        $this->logger->info('Refund processed successfully', [
            'orderId' => $event->getOrderId(),
            'refundId' => $result->getRefundId(),
            'amount' => $result->getRefundedAmount()
        ]);
        return;
    }

    $this->logger->error('Refund failed', [
        'orderId' => $event->getOrderId(),
        'error' => $result->getErrorMessage()
    ]);
}
```

---

## Implementation Steps

### Step 1: Write Tests (TDD - RED)

```bash
# Create test file
mkdir -p tests/Unit/Stripe/Service
touch tests/Unit/Stripe/Service/RefundServiceTest.php

# Run tests - should fail
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Service/RefundServiceTest.php"
```

### Step 2: Create DTO, Interface, and Service (TDD - GREEN)

```bash
# Create files
mkdir -p src/Stripe/DTO
touch src/Stripe/DTO/RefundResult.php
touch src/Stripe/Service/RefundServiceInterface.php
touch src/Stripe/Service/RefundService.php

# Run tests - should pass
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Service/RefundServiceTest.php"
```

### Step 3: Register Service and Update Handler

```bash
# Update services.yaml
# Refactor StripeRefundRequestHandler
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
```

---

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `src/Stripe/DTO/RefundResult.php` | Refund result DTO |
| `src/Stripe/Service/RefundServiceInterface.php` | Service interface |
| `src/Stripe/Service/RefundService.php` | Service implementation |
| `tests/Unit/Stripe/Service/RefundServiceTest.php` | Service tests |

### Modified Files

| File | Change |
|------|--------|
| `services.yaml` | Register service |
| `StripeRefundRequestHandler.php` | Thin handler, delegate to service |

---

## Verification Checklist

- [ ] RefundService created with ≤25 line methods
- [ ] Handler reduced to ≤50 lines
- [ ] All refund logic in service
- [ ] All unit tests pass
- [ ] Manual refund test works

### Metrics Before/After

| Metric | Before | After | Target |
|--------|--------|-------|--------|
| Handler LOC | ~250 | ~50 | ≤75 |
| Responsibilities | 7 | 2 | ≤2 |
| Service Methods | N/A | 3 | ≤5 |

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking refund flow | High | Manual refund test |
| Edge cases missed | Medium | Review original handler logic |
| API error handling | Medium | Test error paths |

---

## Success Criteria

1. ✅ Handler ≤75 lines
2. ✅ Each method ≤25 lines
3. ✅ Service handles all refund logic
4. ✅ Handler only orchestrates
5. ✅ All tests pass
6. ✅ Manual refund works

---

## Related Issues

- CODE_REVIEW.md Section 1.6 (HIGH: Fat Handler Anti-Pattern)
- CODE_REVIEW.md Section 4.6 (HIGH: Fat Handler Pattern - Stripe Layer)

---

**Last Updated:** 2025-12-09
