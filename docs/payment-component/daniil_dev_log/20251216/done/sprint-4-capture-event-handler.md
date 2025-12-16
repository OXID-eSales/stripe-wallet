# Sprint 4: Create CaptureRequestedEvent and Handler

**Status:** PENDING
**Priority:** HIGH
**Estimated Effort:** 3 hours
**Depends On:** Sprint 1 (AUTHORIZED State), Sprint 2 (Module Config)

---

## Objective

Create the event and handler infrastructure for processing manual capture requests.

---

## Components to Create

### 1. CaptureRequestedEvent

**File:** `src/Component/EventSystem/Event/Payment/CaptureRequestedEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\BaseEvent;

/**
 * Event emitted when a payment capture is requested.
 *
 * This event can be triggered by:
 * - Admin backend "Capture" button
 * - API/GraphQL mutation
 * - Automated process (e.g., order shipped trigger)
 */
class CaptureRequestedEvent extends BaseEvent
{
    public const EVENT_NAME = 'payment.capture.requested';

    public function __construct(
        private readonly string $contractId,
        private readonly ?float $amount = null, // null = full amount
        private readonly string $triggeredBy = 'admin',
        private readonly string $idempotencyKey = '',
        private readonly string $reason = '',
        array $context = []
    ) {
        parent::__construct($context);
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }

    /**
     * Amount to capture. Null means capture full authorized amount.
     */
    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getEventName(): string
    {
        return self::EVENT_NAME;
    }
}
```

### 2. ContractAuthorizedEvent

**File:** `src/Component/EventSystem/Event/Contract/ContractAuthorizedEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\BaseEvent;

/**
 * Event emitted when a contract transitions to AUTHORIZED state.
 *
 * This indicates the payment has been authorized but not yet captured.
 */
class ContractAuthorizedEvent extends BaseEvent
{
    public const EVENT_NAME = 'contract.authorized';

    public function __construct(
        private readonly PaymentContract $contract,
        private readonly string $providerPaymentId,
        private readonly float $authorizedAmount,
        array $context = []
    ) {
        parent::__construct($context);
    }

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }

    public function getProviderPaymentId(): string
    {
        return $this->providerPaymentId;
    }

    public function getAuthorizedAmount(): float
    {
        return $this->authorizedAmount;
    }

    public function getEventName(): string
    {
        return self::EVENT_NAME;
    }
}
```

### 3. StripeCaptureHandler

**File:** `src/Stripe/EventSystem/Handler/StripeCaptureHandler.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\CaptureRequestedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\EventHandlerInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CapturePaymentRequest;
use Psr\Log\LoggerInterface;

class StripeCaptureHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly StripeAdapterInterface $stripeAdapter,
        private readonly EventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(CaptureRequestedEvent $event): void
    {
        $contractId = $event->getContractId();

        $this->logger->info('Processing capture request', [
            'contract_id' => $contractId,
            'triggered_by' => $event->getTriggeredBy(),
            'amount' => $event->getAmount(),
        ]);

        // 1. Load contract
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            $this->logger->error('Contract not found for capture', ['contract_id' => $contractId]);
            return;
        }

        // 2. Validate state
        if (!$contract->getState()->isAuthorized()) {
            $this->logger->warning('Cannot capture: contract not in AUTHORIZED state', [
                'contract_id' => $contractId,
                'current_state' => $contract->getState()->getValue(),
            ]);
            return;
        }

        // 3. Get PaymentIntent ID from contract metadata
        $providerPaymentId = $contract->getMetadataValue('provider_payment_id');
        if (empty($providerPaymentId)) {
            $this->logger->error('No provider payment ID found in contract', [
                'contract_id' => $contractId,
            ]);
            return;
        }

        // 4. Build capture request
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $providerPaymentId,
            amount: $event->getAmount(),
            metadata: [
                'contract_id' => $contractId,
                'triggered_by' => $event->getTriggeredBy(),
                'reason' => $event->getReason(),
            ]
        );

        // 5. Execute capture via adapter
        try {
            $captureResponse = $this->stripeAdapter->capturePayment($captureRequest);

            $this->logger->info('Payment captured successfully', [
                'contract_id' => $contractId,
                'capture_id' => $captureResponse->getCaptureId(),
                'amount' => $captureResponse->getAmount(),
            ]);

            // 6. Transition contract state
            $contract->captureAuthorization();
            $this->contractRepository->save($contract);

            // 7. Emit success event
            $this->eventDispatcher->dispatch(new PaymentCapturedEvent(
                contractId: $contractId,
                providerPaymentId: $providerPaymentId,
                captureId: $captureResponse->getCaptureId(),
                amount: $captureResponse->getAmount(),
                triggeredBy: $event->getTriggeredBy(),
                context: $event->getContext()
            ));

        } catch (\Exception $e) {
            $this->logger->error('Capture failed', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getSubscribedEvents(): array
    {
        return [CaptureRequestedEvent::EVENT_NAME];
    }
}
```

### 4. Update PaymentCapturedEvent (if needed)

Extend existing event to include capture-specific data:

**File:** `src/Component/EventSystem/Event/Payment/PaymentCapturedEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\BaseEvent;

class PaymentCapturedEvent extends BaseEvent
{
    public const EVENT_NAME = 'payment.captured';

    public function __construct(
        private readonly string $contractId,
        private readonly string $providerPaymentId,
        private readonly string $captureId,
        private readonly float $amount,
        private readonly string $triggeredBy = 'webhook',
        array $context = []
    ) {
        parent::__construct($context);
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }

    public function getProviderPaymentId(): string
    {
        return $this->providerPaymentId;
    }

    public function getCaptureId(): string
    {
        return $this->captureId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function getEventName(): string
    {
        return self::EVENT_NAME;
    }
}
```

### 5. Register Handler in EventListenerProvider

**File:** `src/Component/EventSystem/EventListenerProvider.php`

```php
// Add to handler registrations
CaptureRequestedEvent::EVENT_NAME => [
    StripeCaptureHandler::class,
],
```

---

## Unit Tests

**File:** `tests/Unit/Stripe/EventSystem/Handler/StripeCaptureHandlerTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\CaptureRequestedEvent;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCaptureHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StripeCaptureHandlerTest extends TestCase
{
    public function testHandleCapturesAuthorizedContract(): void
    {
        // Arrange
        $contract = $this->createAuthorizedContract('contract-123');

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findById')->willReturn($contract);
        $contractRepository->expects($this->once())->method('save');

        $captureResponse = $this->createCaptureResponse('cap_123', 99.99);
        $stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $stripeAdapter->expects($this->once())
            ->method('capturePayment')
            ->willReturn($captureResponse);

        $eventDispatcher = $this->createMock(EventDispatcher::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentCapturedEvent::class));

        $handler = new StripeCaptureHandler(
            $contractRepository,
            $stripeAdapter,
            $eventDispatcher,
            new NullLogger()
        );

        $event = new CaptureRequestedEvent(
            contractId: 'contract-123',
            amount: 99.99,
            triggeredBy: 'admin'
        );

        // Act
        $handler->handle($event);

        // Assert - contract should transition to READY_TO_COMMIT
        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

    public function testHandleSkipsNonAuthorizedContract(): void
    {
        // Arrange
        $contract = $this->createPendingContract('contract-123');

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findById')->willReturn($contract);
        $contractRepository->expects($this->never())->method('save');

        $stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $stripeAdapter->expects($this->never())->method('capturePayment');

        $handler = new StripeCaptureHandler(
            $contractRepository,
            $stripeAdapter,
            $this->createMock(EventDispatcher::class),
            new NullLogger()
        );

        $event = new CaptureRequestedEvent(contractId: 'contract-123');

        // Act
        $handler->handle($event);

        // Assert - no capture should happen
    }

    // ... additional test cases ...
}
```

---

## Acceptance Criteria

- [ ] `CaptureRequestedEvent` class created with all required properties
- [ ] `ContractAuthorizedEvent` class created
- [ ] `StripeCaptureHandler` processes capture requests correctly
- [ ] Handler validates contract state before capturing
- [ ] Handler calls Stripe adapter to execute capture
- [ ] Handler transitions contract from AUTHORIZED to READY_TO_COMMIT
- [ ] Handler emits `PaymentCapturedEvent` on success
- [ ] All unit tests pass
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Test Commands

```bash
# Run capture handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/EventSystem/Handler/StripeCaptureHandlerTest.php

# Run all event tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  tests/Unit/Component/EventSystem/
```

---

## Event Flow Diagram

```
┌─────────────────────────────────────┐
│ Admin clicks "Capture Payment"      │
│         OR                          │
│ External trigger (API/event)        │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ CaptureRequestedEvent               │
│ - contractId: 'contract-123'        │
│ - amount: 99.99 (or null)           │
│ - triggeredBy: 'admin'              │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ StripeCaptureHandler                │
│ 1. Load contract                    │
│ 2. Validate AUTHORIZED state        │
│ 3. Get PaymentIntent ID             │
│ 4. Call StripeAdapter.capture()     │
│ 5. Transition to READY_TO_COMMIT    │
│ 6. Emit PaymentCapturedEvent        │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ PaymentCapturedEvent                │
│ - contractId: 'contract-123'        │
│ - captureId: 'cap_XXX'              │
│ - amount: 99.99                     │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ Existing handlers process event:    │
│ - Update OXPAID                     │
│ - Create order (if not created)     │
│ - Send notifications                │
└─────────────────────────────────────┘
```
