# Sprint 7: Webhook Handler for charge.captured

**Status:** PENDING
**Priority:** HIGH
**Estimated Effort:** 2 hours
**Depends On:** Sprint 4, Sprint 5

---

## Objective

Add webhook handler to process `charge.captured` events from Stripe, ensuring the contract state is properly updated when capture is confirmed.

---

## Webhook Events to Handle

### Primary Event: `charge.captured`

Sent when a charge is captured (for manual capture mode).

```json
{
  "type": "charge.captured",
  "data": {
    "object": {
      "id": "ch_3ABC123...",
      "amount": 9999,
      "amount_captured": 9999,
      "captured": true,
      "payment_intent": "pi_3ABC123...",
      "metadata": {
        "contract_id": "contract-123"
      }
    }
  }
}
```

### Secondary Event: `payment_intent.amount_capturable_updated`

Sent when the capturable amount changes (partial captures).

```json
{
  "type": "payment_intent.amount_capturable_updated",
  "data": {
    "object": {
      "id": "pi_3ABC123...",
      "amount": 9999,
      "amount_capturable": 5000,
      "status": "requires_capture"
    }
  }
}
```

---

## Tasks

### 1. Create ChargeCapturedWebhookHandler

**File:** `src/Stripe/Webhook/Handler/ChargeCapturedWebhookHandler.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event as StripeEvent;
use Stripe\Charge;

class ChargeCapturedWebhookHandler implements WebhookHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(StripeEvent $event): bool
    {
        return $event->type === 'charge.captured';
    }

    public function handle(StripeEvent $event): void
    {
        /** @var Charge $charge */
        $charge = $event->data->object;

        $this->logger->info('Processing charge.captured webhook', [
            'charge_id' => $charge->id,
            'payment_intent' => $charge->payment_intent,
            'amount_captured' => $charge->amount_captured,
        ]);

        // 1. Find contract by payment intent ID
        $paymentIntentId = $charge->payment_intent;
        $contract = $this->findContractByPaymentIntent($paymentIntentId);

        if ($contract === null) {
            $this->logger->warning('No contract found for captured charge', [
                'payment_intent' => $paymentIntentId,
            ]);
            return;
        }

        // 2. Check if already processed (idempotency)
        if ($this->isAlreadyCaptured($contract, $charge->id)) {
            $this->logger->info('Charge capture already processed', [
                'contract_id' => $contract->getId(),
                'charge_id' => $charge->id,
            ]);
            return;
        }

        // 3. Update contract state if in AUTHORIZED
        if ($contract->getState()->isAuthorized()) {
            $contract->captureAuthorization();
            $this->contractRepository->save($contract);

            $this->logger->info('Contract transitioned from AUTHORIZED to READY_TO_COMMIT', [
                'contract_id' => $contract->getId(),
            ]);
        }

        // 4. Emit PaymentCapturedEvent
        $amountCaptured = $charge->amount_captured / 100; // Convert from cents

        $this->eventDispatcher->dispatch(new PaymentCapturedEvent(
            contractId: $contract->getId(),
            providerPaymentId: $paymentIntentId,
            captureId: $charge->id,
            amount: $amountCaptured,
            triggeredBy: 'webhook',
            context: [
                'webhook_event_id' => $event->id,
                'charge_id' => $charge->id,
            ]
        ));

        // 5. Store capture info in contract metadata
        $contract->setMetadataValue('capture_id', $charge->id);
        $contract->setMetadataValue('captured_amount', $amountCaptured);
        $contract->setMetadataValue('captured_at', date('Y-m-d H:i:s'));
        $this->contractRepository->save($contract);

        $this->logger->info('charge.captured webhook processed successfully', [
            'contract_id' => $contract->getId(),
            'capture_id' => $charge->id,
            'amount' => $amountCaptured,
        ]);
    }

    private function findContractByPaymentIntent(string $paymentIntentId): ?PaymentContract
    {
        return $this->contractRepository->findByMetadata('provider_payment_id', $paymentIntentId);
    }

    private function isAlreadyCaptured(PaymentContract $contract, string $chargeId): bool
    {
        $existingCaptureId = $contract->getMetadataValue('capture_id');
        return $existingCaptureId === $chargeId;
    }
}
```

### 2. Register Handler in WebhookProcessingService

**File:** `src/Stripe/Service/WebhookProcessingService.php`

Add the new handler to the webhook processing:

```php
private function getHandlerForEvent(StripeEvent $event): ?WebhookHandlerInterface
{
    $handlers = [
        // ... existing handlers ...
        ChargeCapturedWebhookHandler::class,
    ];

    foreach ($handlers as $handlerClass) {
        $handler = $this->container->get($handlerClass);
        if ($handler->supports($event)) {
            return $handler;
        }
    }

    return null;
}
```

### 3. Update Webhook Event Types List

Ensure `charge.captured` is in the list of expected events:

```php
private const SUPPORTED_EVENTS = [
    'checkout.session.completed',
    'payment_intent.succeeded',
    'payment_intent.payment_failed',
    'charge.refunded',
    'charge.captured',  // NEW
    'payment_intent.amount_capturable_updated',  // NEW (optional)
];
```

---

## Unit Tests

**File:** `tests/Unit/Stripe/Webhook/Handler/ChargeCapturedWebhookHandlerTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Webhook\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\ChargeCapturedWebhookHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Event as StripeEvent;

class ChargeCapturedWebhookHandlerTest extends TestCase
{
    public function testSupportsChargeCapturedEvent(): void
    {
        $handler = $this->createHandler();

        $event = $this->createStripeEvent('charge.captured');
        $this->assertTrue($handler->supports($event));

        $event = $this->createStripeEvent('payment_intent.succeeded');
        $this->assertFalse($handler->supports($event));
    }

    public function testHandleTransitionsContractFromAuthorizedToReadyToCommit(): void
    {
        // Arrange
        $contract = $this->createAuthorizedContract('contract-123');
        $contract->setMetadataValue('provider_payment_id', 'pi_123');

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findByMetadata')
            ->with('provider_payment_id', 'pi_123')
            ->willReturn($contract);
        $contractRepository->expects($this->atLeastOnce())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcher::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentCapturedEvent::class));

        $handler = $this->createHandler($contractRepository, $eventDispatcher);

        $event = $this->createChargeCapturedEvent('ch_123', 'pi_123', 9999);

        // Act
        $handler->handle($event);

        // Assert
        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

    public function testHandleSkipsIfContractNotFound(): void
    {
        // Arrange
        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findByMetadata')->willReturn(null);
        $contractRepository->expects($this->never())->method('save');

        $handler = $this->createHandler($contractRepository);

        $event = $this->createChargeCapturedEvent('ch_123', 'pi_unknown', 9999);

        // Act & Assert (no exception)
        $handler->handle($event);
    }

    public function testHandleIsIdempotent(): void
    {
        // Arrange
        $contract = $this->createAuthorizedContract('contract-123');
        $contract->setMetadataValue('provider_payment_id', 'pi_123');
        $contract->setMetadataValue('capture_id', 'ch_123'); // Already captured

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findByMetadata')->willReturn($contract);

        $eventDispatcher = $this->createMock(EventDispatcher::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $handler = $this->createHandler($contractRepository, $eventDispatcher);

        $event = $this->createChargeCapturedEvent('ch_123', 'pi_123', 9999);

        // Act
        $handler->handle($event);

        // Assert - contract state unchanged, no event dispatched
    }

    // ... helper methods ...

    private function createChargeCapturedEvent(
        string $chargeId,
        string $paymentIntentId,
        int $amountCaptured
    ): StripeEvent {
        return StripeEvent::constructFrom([
            'id' => 'evt_123',
            'type' => 'charge.captured',
            'data' => [
                'object' => [
                    'id' => $chargeId,
                    'payment_intent' => $paymentIntentId,
                    'amount_captured' => $amountCaptured,
                    'captured' => true,
                    'metadata' => [],
                ],
            ],
        ]);
    }
}
```

---

## Acceptance Criteria

- [ ] `ChargeCapturedWebhookHandler` created and handles `charge.captured` events
- [ ] Handler finds contract by PaymentIntent ID
- [ ] Handler transitions AUTHORIZED contracts to READY_TO_COMMIT
- [ ] Handler emits `PaymentCapturedEvent`
- [ ] Handler is idempotent (skips if already captured)
- [ ] Capture info stored in contract metadata
- [ ] Unit tests pass
- [ ] Integration with webhook endpoint verified
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Webhook Testing

### Local Testing with Stripe CLI

```bash
# Install Stripe CLI (if not installed)
brew install stripe/stripe-cli/stripe

# Login to Stripe
stripe login

# Forward webhooks to local endpoint
stripe listen --forward-to https://daniil.oxiddev.de/index.php?cl=osc_stripe_webhook

# Trigger test event
stripe trigger charge.captured
```

### Integration Test

```php
public function testWebhookEndpointProcessesChargeCaptured(): void
{
    // 1. Create test contract in AUTHORIZED state
    // 2. Build webhook payload
    // 3. Call webhook endpoint
    // 4. Assert contract is now READY_TO_COMMIT
}
```

---

## Notes

- The `charge.captured` webhook confirms the capture happened on Stripe's side
- This provides a safety net if the synchronous capture call fails
- Idempotency is important as webhooks may be retried
- Store `capture_id` to prevent duplicate processing
