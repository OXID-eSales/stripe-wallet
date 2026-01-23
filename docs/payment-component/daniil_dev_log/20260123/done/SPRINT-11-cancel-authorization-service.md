# SPRINT 11: Create CancelAuthorizationService

**Date Created:** 2026-01-23
**Status:** COMPLETED ✓
**Priority:** MEDIUM
**Estimated Effort:** 1-2 hours
**Baseline Tests:** 793 tests, 2334 assertions (ALL PASSING)
**Dependency:** SPRINT-8 (RequestLogService) should be completed first

---

## Core Requirements

**All code must follow:**
- **TDD (Test-Driven Development)** - Write failing tests first, then implementation
- **SOLID Principles** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance
- **DRY** do not repeat yourself - extract common code

---

## Development Environment

**Docker Environment:** All tests run inside Docker from project root.

**Running Tests:**
```bash
# Pre-commit check (Unit tests + style)
./bin/pre-commit-check.sh

# Full check with Integration tests (REQUIRED before completing sprint)
./bin/pre-commit-check.sh --full

# Single test file
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Service/CancelAuthorizationServiceTest.php
```

---

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Pattern | **Service extraction** | Follow same pattern as Capture/Refund services |
| Error handling | **Result objects** | `CancellationResult::success()` / `::failure()`, no exceptions |
| DTO location | **src/Stripe/DTO/** | Consistent with CaptureResult, RefundResult |
| DI Location | **Stripe services.yaml** | Stripe-specific service |
| Code style | **Follow existing patterns** | NullLogger default, readonly properties, final class |

---

## Objective

Create a dedicated `CancelAuthorizationService` to extract cancel authorization logic from `StripeCancelAuthorizationRequestHandler`. This follows the same pattern established for Capture and Refund services.

---

## Problem Statement

`StripeCancelAuthorizationRequestHandler` (211 lines) currently:
1. Calls Stripe adapter directly (no service layer)
2. Has inline RequestLog logging
3. Contains business logic that should be in a service

---

## Implementation Plan

### Phase 1: Create CancellationResult DTO

**File:** `src/Stripe/DTO/CancellationResult.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\DTO;

/**
 * Result of a cancel authorization operation.
 *
 * Sprint 11: Extract from handler to service layer.
 *
 * @since 2.0.0
 */
final readonly class CancellationResult
{
    private function __construct(
        private bool $successful,
        private ?string $paymentIntentId,
        private ?string $status,
        private ?string $errorMessage,
        private ?string $errorCode
    ) {
    }

    public static function success(string $paymentIntentId, string $status): self
    {
        return new self(
            successful: true,
            paymentIntentId: $paymentIntentId,
            status: $status,
            errorMessage: null,
            errorCode: null
        );
    }

    public static function failure(string $message, ?string $code = null): self
    {
        return new self(
            successful: false,
            paymentIntentId: null,
            status: null,
            errorMessage: $message,
            errorCode: $code
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getPaymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
```

### Phase 2: Create CancelAuthorizationService

**File:** `tests/Unit/Stripe/Service/CancelAuthorizationServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\DTO\CancellationResult;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationService;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\PaymentIntent;

class CancelAuthorizationServiceTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $service = new CancelAuthorizationService($adapter, new NullLogger());

        $this->assertInstanceOf(CancelAuthorizationServiceInterface::class, $service);
    }

    public function testCancelAuthorizationReturnsSuccessResult(): void
    {
        // Arrange
        $paymentIntent = $this->createMock(PaymentIntent::class);
        $paymentIntent->status = 'canceled';

        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_123', 'requested_by_customer')
            ->willReturn($paymentIntent);

        $service = new CancelAuthorizationService($adapter, new NullLogger());

        // Act
        $result = $service->cancelAuthorization('pi_123', 'requested_by_customer');

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('canceled', $result->getStatus());
    }

    public function testCancelAuthorizationReturnsFailureOnException(): void
    {
        // Arrange
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('cancelPaymentIntent')
            ->willThrowException(new \Exception('API Error'));

        $service = new CancelAuthorizationService($adapter, new NullLogger());

        // Act
        $result = $service->cancelAuthorization('pi_123', null);

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('API Error', $result->getErrorMessage());
    }
}
```

**File:** `src/Stripe/Service/CancelAuthorizationServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\DTO\CancellationResult;

/**
 * Service interface for canceling Stripe payment authorizations.
 *
 * Sprint 11: Extract from handler.
 *
 * @since 2.0.0
 */
interface CancelAuthorizationServiceInterface
{
    /**
     * Cancel a PaymentIntent authorization.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID (pi_xxx)
     * @param string|null $reason Cancellation reason
     * @return CancellationResult Result of the cancellation
     */
    public function cancelAuthorization(
        string $paymentIntentId,
        ?string $reason = null
    ): CancellationResult;
}
```

**File:** `src/Stripe/Service/CancelAuthorizationService.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\DTO\CancellationResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for canceling Stripe payment authorizations.
 *
 * Sprint 11: Extract from StripeCancelAuthorizationRequestHandler.
 *
 * @since 2.0.0
 */
final class CancelAuthorizationService implements CancelAuthorizationServiceInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function cancelAuthorization(
        string $paymentIntentId,
        ?string $reason = null
    ): CancellationResult {
        try {
            $cancelledPaymentIntent = $this->stripeAdapter->cancelPaymentIntent(
                $paymentIntentId,
                $reason
            );

            $this->logger->info('Authorization cancelled successfully', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $cancelledPaymentIntent->status,
            ]);

            return CancellationResult::success(
                $paymentIntentId,
                $cancelledPaymentIntent->status ?? 'canceled'
            );
        } catch (\Throwable $e) {
            $this->logger->error('Cancel authorization failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return CancellationResult::failure($e->getMessage());
        }
    }
}
```

### Phase 3: Refactor StripeCancelAuthorizationRequestHandler

**Target:** Reduce from 211 lines to ~80 lines

**New Handler Structure:**

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles cancel authorization requests for Stripe PaymentIntents.
 *
 * Sprint 11: Refactored to be thin handler.
 *
 * @since 2.0.0
 */
class StripeCancelAuthorizationRequestHandler implements HandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly CancelAuthorizationServiceInterface $cancelService,
        private readonly RequestLogServiceInterface $requestLogService,
        ?LoggerInterface $logger = null,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getHandledEventClass(): string
    {
        return StripeCancelAuthorizationRequestEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCancelAuthorizationRequestEvent) {
            return;
        }

        $context = $event->getContext();

        try {
            $this->processCancelAuthorization($event, $context);
        } catch (\Throwable $e) {
            $this->handleException($e, $context, $event);
        }
    }

    private function processCancelAuthorization(
        StripeCancelAuthorizationRequestEvent $event,
        EventContext $context
    ): void {
        $paymentIntentId = $event->getPaymentIntentId();

        if ($paymentIntentId === null || $paymentIntentId === '') {
            $context->set('error', 'PaymentIntent ID is missing');
            $context->set('cancelSuccess', false);
            return;
        }

        $result = $this->cancelService->cancelAuthorization(
            $paymentIntentId,
            $event->getCancellationReason()
        );

        if (!$result->isSuccessful()) {
            $context->set('error', $result->getErrorMessage());
            $context->set('cancelSuccess', false);
            return;
        }

        // Log success
        $this->requestLogService->logRequest(
            'cancel_authorization',
            ['payment_intent_id' => $paymentIntentId],
            [
                'status' => $result->getStatus(),
                'reason' => $event->getCancellationReason(),
            ],
            $event->getOrderId() ?? $paymentIntentId,
            (int) Registry::getConfig()->getShopId()
        );

        // Set success context
        $context->set('cancelSuccess', true);
        $context->set('cancelledPaymentIntentId', $paymentIntentId);
        $context->set('cancelledStatus', $result->getStatus());
    }

    private function handleException(
        \Throwable $e,
        EventContext $context,
        StripeCancelAuthorizationRequestEvent $event
    ): void {
        $context->set('error', $e->getMessage());
        $context->set('cancelSuccess', false);

        $paymentIntentId = $event->getPaymentIntentId();
        if ($paymentIntentId !== null) {
            $this->requestLogService->logException(
                'cancel_authorization',
                $e,
                $paymentIntentId,
                (int) Registry::getConfig()->getShopId()
            );
        }
    }
}
```

---

## Files to Create

| File | Type | Lines |
|------|------|-------|
| `src/Stripe/DTO/CancellationResult.php` | DTO | ~60 |
| `src/Stripe/Service/CancelAuthorizationServiceInterface.php` | Interface | ~25 |
| `src/Stripe/Service/CancelAuthorizationService.php` | Implementation | ~55 |
| `tests/Unit/Stripe/Service/CancelAuthorizationServiceTest.php` | Unit Tests | ~60 |

## Files to Modify

| File | Change | Lines Change |
|------|--------|--------------|
| `src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php` | Refactor | 211 → ~80 (-131) |
| `services.yaml` | Register services | +5 |

---

## Acceptance Criteria

- [ ] `CancellationResult` DTO created
- [ ] `CancelAuthorizationServiceInterface` created
- [ ] `CancelAuthorizationService` implements interface
- [ ] Unit tests pass (3+ tests)
- [ ] Handler uses `RequestLogService` (from Sprint 8)
- [ ] Handler reduced from 211 to ~80 lines
- [ ] `./bin/pre-commit-check.sh --full` passes

---

## Metrics

| Metric | Before | After | Change |
|--------|--------|-------|--------|
| Handler lines | 211 | ~80 | -62% |
| New service lines | 0 | ~140 | +140 |

---

**Sprint Owner:** TBD
**Review Required:** Yes
**Depends On:** SPRINT-8 (RequestLogService)
