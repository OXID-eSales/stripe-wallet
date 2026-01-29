# SPRINT 9: Create CaptureService & Refactor StripeCaptureRequestHandler

**Date Created:** 2026-01-23
**Status:** TODO
**Priority:** HIGH
**Estimated Effort:** 3-4 hours
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
  extensions/stripe/tests/Unit/Stripe/Service/CaptureServiceTest.php
```

---

## Objective

Create a dedicated `CaptureService` to extract capture business logic from `StripeCaptureRequestHandler`. This addresses the **worst offender** handler (389 lines) with internal duplication.

---

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Contract state | **Service handles state transitions** | AUTHORIZED → READY_TO_COMMIT atomically with capture |
| Capture modes | **Single service, two methods** | `processCapture(contract)` and `processDirectCapture(paymentIntentId)` |
| Amount format | **Service accepts float** | Converts internally to cents, cleaner API for callers |
| Error handling | **Result objects** | `CaptureResult::success()` / `::failure()`, no exceptions |
| DTO location | **src/Stripe/DTO/** | Consistent with existing DTOs |
| Code style | **Follow existing patterns** | NullLogger default, readonly properties, final class |
| DI Location | **Stripe services.yaml** | Stripe-specific service |

---

## Problem Statement

`StripeCaptureRequestHandler` (389 lines) has:
1. **No dedicated CaptureService** - calls adapter directly
2. **Internal duplication** - `executeCapture()` and `executeDirectCapture()` share ~50 lines
3. **Too many responsibilities** - validation, adapter calls, contract updates, logging, context setting

---

## Implementation Plan

### Phase 1: Create CaptureResult DTO (TDD)

**File:** `tests/Unit/Stripe/DTO/CaptureResultTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\DTO;

use OxidEsales\Payments\Stripe\DTO\CaptureResult;
use PHPUnit\Framework\TestCase;

class CaptureResultTest extends TestCase
{
    public function testSuccessfulResult(): void
    {
        $capturedAt = new \DateTimeImmutable('2026-01-23 12:00:00');
        $result = CaptureResult::success(
            captureId: 'ch_123',
            amountCaptured: 1000,
            currency: 'eur',
            capturedAt: $capturedAt
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->getCaptureId());
        $this->assertSame(1000, $result->getAmountCaptured());
        $this->assertSame('eur', $result->getCurrency());
        $this->assertSame($capturedAt, $result->getCapturedAt());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testFailedResult(): void
    {
        $result = CaptureResult::failure('Card declined', 'card_declined');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getCaptureId());
        $this->assertNull($result->getAmountCaptured());
        $this->assertSame('Card declined', $result->getErrorMessage());
        $this->assertSame('card_declined', $result->getErrorCode());
    }

    public function testFailedResultWithoutCode(): void
    {
        $result = CaptureResult::failure('Unknown error');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Unknown error', $result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }
}
```

**File:** `src/Stripe/DTO/CaptureResult.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\DTO;

/**
 * Result of a capture operation.
 *
 * Sprint 9: Immutable result object for CaptureService.
 * Uses factory methods for success/failure - no exceptions.
 *
 * @since 2.0.0
 */
final readonly class CaptureResult
{
    private function __construct(
        private bool $successful,
        private ?string $captureId,
        private ?int $amountCaptured,
        private ?string $currency,
        private ?\DateTimeImmutable $capturedAt,
        private ?string $errorMessage,
        private ?string $errorCode
    ) {
    }

    public static function success(
        string $captureId,
        int $amountCaptured,
        string $currency,
        \DateTimeImmutable $capturedAt
    ): self {
        return new self(
            successful: true,
            captureId: $captureId,
            amountCaptured: $amountCaptured,
            currency: $currency,
            capturedAt: $capturedAt,
            errorMessage: null,
            errorCode: null
        );
    }

    public static function failure(string $message, ?string $code = null): self
    {
        return new self(
            successful: false,
            captureId: null,
            amountCaptured: null,
            currency: null,
            capturedAt: null,
            errorMessage: $message,
            errorCode: $code
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getCaptureId(): ?string
    {
        return $this->captureId;
    }

    public function getAmountCaptured(): ?int
    {
        return $this->amountCaptured;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getCapturedAt(): ?\DateTimeImmutable
    {
        return $this->capturedAt;
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

### Phase 2: Create CaptureService Interface & Tests

**File:** `tests/Unit/Stripe/Service/CaptureServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\DTO\CaptureResult;
use OxidEsales\Payments\Stripe\Service\CaptureService;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CaptureServiceTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $repository = $this->createMock(ContractRepositoryInterface::class);
        $service = new CaptureService($adapter, $repository, new NullLogger());

        $this->assertInstanceOf(CaptureServiceInterface::class, $service);
    }

    public function testProcessCaptureWithContractReturnsSuccess(): void
    {
        // Arrange
        $capturedAt = new \DateTimeImmutable();
        $response = new CaptureResponse('ch_123', 1000, 'eur', $capturedAt, []);

        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('capturePayment')
            ->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->expects($this->once())->method('captureAuthorization');

        $repository = $this->createMock(ContractRepositoryInterface::class);
        $repository->expects($this->once())->method('save')->with($contract);

        $service = new CaptureService($adapter, $repository, new NullLogger());

        // Act
        $result = $service->processCapture($contract, 10.00, ['initiator' => 'admin']);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->getCaptureId());
        $this->assertSame(1000, $result->getAmountCaptured());
    }

    public function testProcessCaptureTransitionsContractState(): void
    {
        // Arrange
        $response = new CaptureResponse('ch_123', 1000, 'eur', new \DateTimeImmutable(), []);

        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->expects($this->once())->method('captureAuthorization'); // State transition

        $repository = $this->createMock(ContractRepositoryInterface::class);
        $repository->expects($this->once())->method('save');

        $service = new CaptureService($adapter, $repository, new NullLogger());

        // Act
        $service->processCapture($contract, null, []);

        // Assert - expectations on mock verify state transition
    }

    public function testProcessCaptureConvertsFloatToCents(): void
    {
        // Arrange
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) {
                return $request->amount === 1050; // 10.50 EUR = 1050 cents
            }))
            ->willReturn(new CaptureResponse('ch_123', 1050, 'eur', new \DateTimeImmutable(), []));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');

        $repository = $this->createMock(ContractRepositoryInterface::class);

        $service = new CaptureService($adapter, $repository, new NullLogger());

        // Act
        $service->processCapture($contract, 10.50, []);
    }

    public function testProcessCaptureWithNullAmountDoesFullCapture(): void
    {
        // Arrange
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) {
                return $request->amount === null; // Full capture
            }))
            ->willReturn(new CaptureResponse('ch_123', 5000, 'eur', new \DateTimeImmutable(), []));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');

        $repository = $this->createMock(ContractRepositoryInterface::class);

        $service = new CaptureService($adapter, $repository, new NullLogger());

        // Act
        $result = $service->processCapture($contract, null, []);

        // Assert
        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessCaptureReturnsFailureOnException(): void
    {
        // Arrange
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->method('capturePayment')
            ->willThrowException(new \Exception('API Error'));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');

        $repository = $this->createMock(ContractRepositoryInterface::class);

        $service = new CaptureService($adapter, $repository, new NullLogger());

        // Act
        $result = $service->processCapture($contract, 10.00, []);

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertSame('API Error', $result->getErrorMessage());
    }

    public function testProcessDirectCaptureWithoutContract(): void
    {
        // Arrange
        $response = new CaptureResponse('ch_123', 1000, 'eur', new \DateTimeImmutable(), []);

        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('capturePayment')
            ->willReturn($response);

        $repository = $this->createMock(ContractRepositoryInterface::class);
        $repository->expects($this->never())->method('save'); // No contract = no save

        $service = new CaptureService($adapter, $repository, new NullLogger());

        // Act
        $result = $service->processDirectCapture('pi_123', 10.00, ['order_id' => 'order_123']);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->getCaptureId());
    }
}
```

**File:** `src/Stripe/Service/CaptureServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\DTO\CaptureResult;

/**
 * Service interface for capturing Stripe payments.
 *
 * Sprint 9: Two methods for different capture scenarios:
 * - processCapture(): Contract-based capture with state transition
 * - processDirectCapture(): Direct capture without contract (admin panel)
 *
 * @since 2.0.0
 */
interface CaptureServiceInterface
{
    /**
     * Process a capture for a contract.
     *
     * Handles:
     * - Stripe API capture call
     * - Contract state transition (AUTHORIZED → READY_TO_COMMIT)
     * - Contract persistence
     *
     * @param PaymentContractInterface $contract The contract to capture
     * @param float|null $amount Amount in currency units (null for full capture)
     * @param array<string, string> $metadata Metadata to attach
     * @return CaptureResult Result object (never throws)
     */
    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResult;

    /**
     * Process a direct capture without contract (admin panel).
     *
     * Used when capturing from admin panel where no contract exists.
     * Does NOT handle contract state - only Stripe API call.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @param float|null $amount Amount in currency units (null for full capture)
     * @param array<string, string> $metadata Metadata to attach
     * @return CaptureResult Result object (never throws)
     */
    public function processDirectCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResult;
}
```

### Phase 3: Create CaptureService Implementation

**File:** `src/Stripe/Service/CaptureService.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\DTO\CaptureResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for capturing Stripe payments.
 *
 * Sprint 9: Extracted from StripeCaptureRequestHandler.
 *
 * Handles both contract-based and direct captures:
 * - processCapture(): With contract, handles state transition
 * - processDirectCapture(): Without contract (admin panel)
 *
 * Follows existing patterns: NullLogger default, readonly properties, final class.
 *
 * @since 2.0.0
 */
final class CaptureService implements CaptureServiceInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResult {
        $paymentIntentId = $contract->getProviderOrderId();
        if (!is_string($paymentIntentId) || $paymentIntentId === '') {
            return CaptureResult::failure('Contract has no PaymentIntent ID');
        }

        $result = $this->executeCapture($paymentIntentId, $amount, $metadata);

        if ($result->isSuccessful()) {
            $this->transitionContractState($contract);
        }

        return $result;
    }

    public function processDirectCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResult {
        return $this->executeCapture($paymentIntentId, $amount, $metadata);
    }

    private function executeCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResult {
        try {
            $amountCents = $this->convertAmountToCents($amount);

            $request = new CapturePaymentRequest(
                providerPaymentId: $paymentIntentId,
                amount: $amountCents,
                metadata: $metadata
            );

            $response = $this->stripeAdapter->capturePayment($request);

            $this->logger->info('Capture processed successfully', [
                'payment_intent_id' => $paymentIntentId,
                'capture_id' => $response->captureId,
                'amount' => $response->amountCaptured,
                'currency' => $response->currency,
            ]);

            return CaptureResult::success(
                captureId: $response->captureId,
                amountCaptured: $response->amountCaptured,
                currency: $response->currency,
                capturedAt: $response->capturedAt
            );
        } catch (\Throwable $e) {
            $this->logger->error('Capture failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return CaptureResult::failure($e->getMessage());
        }
    }

    private function transitionContractState(PaymentContractInterface $contract): void
    {
        $contract->captureAuthorization();
        $this->contractRepository->save($contract);

        $this->logger->info('Contract transitioned after capture', [
            'contract_id' => $contract->getId(),
            'new_state' => $contract->getStateValue(),
        ]);
    }

    private function convertAmountToCents(?float $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        return (int) round($amount * 100);
    }
}
```

### Phase 4: Refactor StripeCaptureRequestHandler

**Target:** Reduce from 389 lines to ~120 lines

**New Handler Structure:**

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\DTO\CaptureResult;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles capture requests for Stripe authorized payments.
 *
 * Sprint 9: Refactored to thin handler.
 *
 * Handler responsibilities (ONLY):
 * 1. Validate event and extract parameters
 * 2. Delegate to CaptureService
 * 3. Log via RequestLogService
 * 4. Set context results
 *
 * @since 2.0.0
 */
class StripeCaptureRequestHandler implements HandlerInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CaptureServiceInterface $captureService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly RequestLogServiceInterface $requestLogService,
        ?LoggerInterface $logger = null,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getHandledEventClass(): string
    {
        return StripeCaptureRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCaptureRequestEvent) {
            return;
        }

        $context = $event->getContext();

        try {
            $this->processCapture($event, $context);
        } catch (\Throwable $e) {
            $this->handleException($e, $context, $event);
        }
    }

    private function processCapture(StripeCaptureRequestEvent $event, EventContext $context): void
    {
        $contractId = $event->getContractId();

        // Direct capture mode (admin panel, no contract)
        if ($contractId === null) {
            $this->processDirectCapture($event, $context);
            return;
        }

        // Contract-based capture
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            $context->set('error', 'Contract not found: ' . $contractId);
            $context->set('captureSuccess', false);
            return;
        }

        if (!$contract->getState()->isAuthorized()) {
            $context->set('error', 'Contract not in AUTHORIZED state');
            $context->set('captureSuccess', false);
            return;
        }

        $metadata = $this->buildMetadata($event);
        $result = $this->captureService->processCapture($contract, $event->getAmount(), $metadata);

        $this->handleCaptureResult($result, $event, $context);
    }

    private function processDirectCapture(StripeCaptureRequestEvent $event, EventContext $context): void
    {
        $paymentIntentId = $event->getPaymentIntentId();
        if ($paymentIntentId === null || $paymentIntentId === '') {
            $context->set('error', 'PaymentIntent ID is missing');
            $context->set('captureSuccess', false);
            return;
        }

        $metadata = $this->buildMetadata($event);
        $result = $this->captureService->processDirectCapture($paymentIntentId, $event->getAmount(), $metadata);

        $this->handleCaptureResult($result, $event, $context);
    }

    private function handleCaptureResult(
        CaptureResult $result,
        StripeCaptureRequestEvent $event,
        EventContext $context
    ): void {
        if (!$result->isSuccessful()) {
            $context->set('error', $result->getErrorMessage());
            $context->set('captureSuccess', false);
            return;
        }

        // Log success
        $this->requestLogService->logRequest(
            'capture',
            ['payment_intent_id' => $event->getPaymentIntentId()],
            [
                'capture_id' => $result->getCaptureId(),
                'amount' => $result->getAmountCaptured(),
                'currency' => $result->getCurrency(),
            ],
            $event->getOrderId() ?? $event->getContractId() ?? '',
            (int) Registry::getConfig()->getShopId()
        );

        // Set success context
        $context->set('captureSuccess', true);
        $context->set('captureId', $result->getCaptureId());
        $context->set('capturedAmount', $result->getAmountCaptured());
        $context->set('captureCurrency', $result->getCurrency());
        $context->set('capturedAt', $result->getCapturedAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, string>
     */
    private function buildMetadata(StripeCaptureRequestEvent $event): array
    {
        $metadata = ['initiator' => $event->getInitiator()];

        if ($event->getContractId() !== null) {
            $metadata['contract_id'] = $event->getContractId();
        }
        if ($event->getOrderId() !== null) {
            $metadata['order_id'] = $event->getOrderId();
        }
        if ($event->getReason() !== null) {
            $metadata['reason'] = $event->getReason();
        }

        return $metadata;
    }

    private function handleException(
        \Throwable $e,
        EventContext $context,
        StripeCaptureRequestEvent $event
    ): void {
        $context->set('error', $e->getMessage());
        $context->set('captureSuccess', false);

        $this->logger->error('Capture handler exception', [
            'error' => $e->getMessage(),
            'contract_id' => $event->getContractId(),
        ]);

        $referenceId = $event->getOrderId() ?? $event->getContractId() ?? '';
        if ($referenceId !== '') {
            $this->requestLogService->logException(
                'capture',
                $e,
                $referenceId,
                (int) Registry::getConfig()->getShopId()
            );
        }
    }
}
```

### Phase 5: Integration Tests

**File:** `tests/Integration/Stripe/Service/CaptureServiceIntegrationTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Integration\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\CaptureService;
// ... test implementation
```

### Phase 6: Register Service in DI

**File:** `src/Stripe/Internal/services.yaml` (add)

```yaml
  OxidEsales\Payments\Stripe\Service\CaptureServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\CaptureService
    arguments:
      - '@OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface'
      - '@OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface'
      - '@Psr\Log\LoggerInterface'
```

---

## Files to Create

| File | Type | Lines |
|------|------|-------|
| `src/Stripe/DTO/CaptureResult.php` | DTO | ~80 |
| `src/Stripe/Service/CaptureServiceInterface.php` | Interface | ~45 |
| `src/Stripe/Service/CaptureService.php` | Implementation | ~110 |
| `tests/Unit/Stripe/DTO/CaptureResultTest.php` | Unit Tests | ~50 |
| `tests/Unit/Stripe/Service/CaptureServiceTest.php` | Unit Tests | ~140 |
| `tests/Integration/Stripe/Service/CaptureServiceIntegrationTest.php` | Integration | ~50 |

## Files to Modify

| File | Change | Lines Change |
|------|--------|--------------|
| `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php` | Major refactor | 389 → ~120 (-269) |
| `src/Stripe/Internal/services.yaml` | Register services | +5 |

---

## Acceptance Criteria

- [ ] `CaptureResult` DTO with success/failure factories
- [ ] `CaptureServiceInterface` with 2 methods
- [ ] `CaptureService` handles both capture modes
- [ ] Service converts float to cents internally
- [ ] Service handles contract state transition atomically
- [ ] Unit tests pass (7+ tests covering all scenarios)
- [ ] Integration test verifies handler + service work together
- [ ] Handler reduced from 389 to ~120 lines
- [ ] No internal duplication in handler
- [ ] `./bin/pre-commit-check.sh --full` passes

---

## Verification Commands

```bash
# Run DTO tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/DTO/CaptureResultTest.php

# Run service tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Service/CaptureServiceTest.php

# Run handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "StripeCaptureRequestHandler"

# Full pre-commit check
./bin/pre-commit-check.sh --full
```

---

## Metrics

| Metric | Before | After | Change |
|--------|--------|-------|--------|
| Handler lines | 389 | ~120 | -69% |
| Internal duplication | ~50 lines | 0 | -100% |
| Responsibilities | 9+ | 4 | -56% |
| New service lines | 0 | ~235 | +235 |
| New tests | 0 | ~10 | +10 |

---

**Sprint Owner:** TBD
**Review Required:** Yes
**Depends On:** SPRINT-8 (RequestLogService)
