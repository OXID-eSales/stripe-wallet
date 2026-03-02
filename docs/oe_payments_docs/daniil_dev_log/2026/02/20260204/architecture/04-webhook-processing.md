# Webhook Processing Architecture

**Date:** 2026-02-04
**Based on:** Actual code analysis

---

## Overview

Webhook processing handles asynchronous notifications from payment providers. The system implements:
- Signature verification for security
- Idempotency to prevent duplicate processing
- Event dispatching to decouple webhook handling from business logic

---

## Webhook Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     Stripe Webhook                               │
│                 POST /webhook/stripe                             │
└─────────────────────────────┬───────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    WebhookController                             │
│  - Receives raw POST body                                        │
│  - Passes to StripeWebhookProcessor                              │
└─────────────────────────────┬───────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                 StripeWebhookProcessor                           │
│  extends AbstractWebhookProcessor                                │
│                                                                  │
│  1. parseAndValidateRequest()                                    │
│     - Verify Stripe signature                                    │
│     - Parse JSON payload                                         │
│                                                                  │
│  2. checkIdempotency()                                           │
│     - Check WebhookLogRepository for event_id                    │
│     - Skip if already processed                                  │
│                                                                  │
│  3. processEvent()                                               │
│     - Route to appropriate handler method                        │
│                                                                  │
│  4. storeWebhookLog()                                            │
│     - Record event for idempotency                               │
└─────────────────────────────┬───────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│            WebhookContractFulfillmentHandler                     │
│                                                                  │
│  Routes by event type:                                           │
│  - payment_intent.succeeded → handlePaymentSucceeded()           │
│  - payment_intent.payment_failed → handlePaymentFailed()         │
│  - payment_intent.canceled → handlePaymentCanceled()             │
│  - charge.captured → handleChargeCaptured()                      │
│  - charge.refunded → handleChargeRefunded()                      │
│  - checkout.session.expired → handleSessionExpired()             │
└─────────────────────────────┬───────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              Contract State Machine Updates                      │
│                                                                  │
│  - Find contract by providerOrderId                              │
│  - Update contract state                                         │
│  - Dispatch domain events                                        │
└─────────────────────────────────────────────────────────────────┘
```

---

## Components

### AbstractWebhookProcessor (payment-component)

**Location:** `payment-component/src/Webhook/AbstractWebhookProcessor.php`

Template Method pattern for webhook processing:

```php
abstract class AbstractWebhookProcessor implements WebhookProcessorInterface
{
    public function __construct(
        protected ContractRepositoryInterface $contractRepository,
        protected WebhookLogRepositoryInterface $webhookLogRepository,
        protected WebhookIdempotencyCheckerInterface $idempotencyChecker,
        protected EventDispatcherInterface $eventDispatcher,
        protected LoggerInterface $logger
    ) {}

    public function process(array $data): WebhookResult
    {
        // 1. Parse and validate
        $event = $this->parseAndValidateRequest($data);

        // 2. Check idempotency
        if ($this->idempotencyChecker->isProcessed($event->getId())) {
            $this->logger->info('Webhook already processed', ['eventId' => $event->getId()]);
            return WebhookResult::skipped('Already processed');
        }

        // 3. Process event
        $result = $this->processEvent($event);

        // 4. Store webhook log
        $this->storeWebhookLog($event, $result);

        return $result;
    }

    abstract protected function getProviderName(): string;
    abstract protected function parseAndValidateRequest(array $data): WebhookEvent;
    abstract protected function processEvent(WebhookEvent $event): WebhookResult;
    abstract protected function getContractIdFromResult(WebhookResult $result): ?string;
}
```

### StripeWebhookProcessor (stripe)

**Location:** `stripe/src/Stripe/Webhook/StripeWebhookProcessor.php`

```php
class StripeWebhookProcessor extends AbstractWebhookProcessor
{
    public function __construct(
        ContractRepositoryInterface $contractRepository,
        WebhookLogRepositoryInterface $webhookLogRepository,
        WebhookIdempotencyCheckerInterface $idempotencyChecker,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        private StripeAdapterInterface $adapter,
        private WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        private ModuleConfigurationService $config
    ) {
        parent::__construct(
            $contractRepository,
            $webhookLogRepository,
            $idempotencyChecker,
            $eventDispatcher,
            $logger
        );
    }

    protected function getProviderName(): string
    {
        return 'stripe';
    }

    protected function parseAndValidateRequest(array $data): WebhookEvent
    {
        $payload = $data['payload'] ?? '';
        $signature = $data['signature'] ?? '';
        $secret = $this->config->getWebhookSecret();

        if (!$this->adapter->verifyWebhookSignature($payload, $signature, $secret)) {
            throw new WebhookSignatureException('Invalid webhook signature');
        }

        $event = json_decode($payload, true);
        return new WebhookEvent(
            id: $event['id'],
            type: $event['type'],
            data: $event['data']['object'],
            createdAt: new \DateTimeImmutable('@' . $event['created'])
        );
    }

    protected function processEvent(WebhookEvent $event): WebhookResult
    {
        return match ($event->getType()) {
            'payment_intent.succeeded' => $this->fulfillmentHandler->handlePaymentSucceeded($event),
            'payment_intent.payment_failed' => $this->fulfillmentHandler->handlePaymentFailed($event),
            'payment_intent.canceled' => $this->fulfillmentHandler->handlePaymentCanceled($event),
            'charge.captured' => $this->fulfillmentHandler->handleChargeCaptured($event),
            'charge.refunded' => $this->fulfillmentHandler->handleChargeRefunded($event),
            'checkout.session.expired' => $this->fulfillmentHandler->handleSessionExpired($event),
            default => WebhookResult::skipped("Unhandled event type: {$event->getType()}")
        };
    }
}
```

---

## Idempotency

Prevents duplicate webhook processing:

### WebhookIdempotencyChecker

```php
interface WebhookIdempotencyCheckerInterface
{
    public function isProcessed(string $eventId): bool;
    public function markAsProcessed(string $eventId): void;
}

class WebhookIdempotencyChecker implements WebhookIdempotencyCheckerInterface
{
    public function __construct(
        private WebhookLogRepositoryInterface $repository
    ) {}

    public function isProcessed(string $eventId): bool
    {
        return $this->repository->existsByEventId($eventId);
    }

    public function markAsProcessed(string $eventId): void
    {
        $log = new WebhookLog($eventId);
        $this->repository->save($log);
    }
}
```

### WebhookLog Entity

```php
class WebhookLog
{
    public function __construct(
        private string $eventId,
        private ?string $eventType = null,
        private ?string $contractId = null,
        private string $status = 'processed',
        private ?\DateTimeInterface $createdAt = null
    ) {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
```

---

## Contract Fulfillment Handler

Bridges webhooks to contract state machine:

**Location:** `stripe/src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php`

```php
interface WebhookContractFulfillmentHandlerInterface
{
    public function handlePaymentSucceeded(WebhookEvent $event): WebhookResult;
    public function handlePaymentFailed(WebhookEvent $event): WebhookResult;
    public function handlePaymentCanceled(WebhookEvent $event): WebhookResult;
    public function handleChargeCaptured(WebhookEvent $event): WebhookResult;
    public function handleChargeRefunded(WebhookEvent $event): WebhookResult;
    public function handleSessionExpired(WebhookEvent $event): WebhookResult;
}

class WebhookContractFulfillmentHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private ContractFulfillmentServiceInterface $fulfillmentService,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {}

    public function handlePaymentSucceeded(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $event->getData()['id'];
        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);

        if (!$contract) {
            $this->logger->warning('Contract not found for payment intent', [
                'paymentIntentId' => $paymentIntentId
            ]);
            return WebhookResult::failed('Contract not found');
        }

        // Fulfill the payment_authorized condition
        $contract->fulfillCondition('payment_authorized', [
            'payment_intent_id' => $paymentIntentId,
            'captured_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        ]);

        $this->contractRepository->save($contract);

        // Dispatch event to trigger condition resolution
        $this->eventDispatcher->dispatch(
            new ContractConditionFulfilledEvent($contract, 'payment_authorized')
        );

        return WebhookResult::success($contract->getId());
    }

    public function handlePaymentFailed(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $event->getData()['id'];
        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);

        if (!$contract) {
            return WebhookResult::failed('Contract not found');
        }

        $errorMessage = $event->getData()['last_payment_error']['message'] ?? 'Payment failed';
        $contract->fail($errorMessage);
        $this->contractRepository->save($contract);

        $this->eventDispatcher->dispatch(
            new ContractFailedEvent($contract, $errorMessage)
        );

        return WebhookResult::success($contract->getId());
    }

    public function handleChargeCaptured(WebhookEvent $event): WebhookResult
    {
        // For manual capture flow
        $chargeId = $event->getData()['id'];
        $paymentIntentId = $event->getData()['payment_intent'];

        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if (!$contract) {
            return WebhookResult::failed('Contract not found');
        }

        $amountCaptured = $event->getData()['amount_captured'] / 100;
        $contract->setCapturedAmount((string) $amountCaptured);
        $contract->setCapturedAt(new \DateTimeImmutable());

        $this->contractRepository->save($contract);

        $this->eventDispatcher->dispatch(
            new PaymentCapturedEvent($contract, $amountCaptured)
        );

        return WebhookResult::success($contract->getId());
    }

    public function handleChargeRefunded(WebhookEvent $event): WebhookResult
    {
        $chargeId = $event->getData()['id'];
        $paymentIntentId = $event->getData()['payment_intent'];

        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if (!$contract) {
            return WebhookResult::failed('Contract not found');
        }

        $amountRefunded = $event->getData()['amount_refunded'] / 100;
        $contract->setRefundedAmount((string) $amountRefunded);
        $contract->setRefundedAt(new \DateTimeImmutable());

        $this->contractRepository->save($contract);

        $this->eventDispatcher->dispatch(
            new PaymentRefundedEvent($contract, $amountRefunded)
        );

        return WebhookResult::success($contract->getId());
    }

    public function handleSessionExpired(WebhookEvent $event): WebhookResult
    {
        $sessionId = $event->getData()['id'];
        // Checkout session metadata contains contract reference

        $metadata = $event->getData()['metadata'] ?? [];
        $contractId = $metadata['contract_id'] ?? null;

        if (!$contractId) {
            return WebhookResult::skipped('No contract ID in session metadata');
        }

        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            return WebhookResult::failed('Contract not found');
        }

        $contract->expire();
        $this->contractRepository->save($contract);

        $this->eventDispatcher->dispatch(
            new ContractExpiredEvent($contract)
        );

        return WebhookResult::success($contract->getId());
    }
}
```

---

## Webhook Result

Communicates processing outcome:

```php
class WebhookResult
{
    private function __construct(
        private string $status,
        private ?string $contractId = null,
        private ?string $message = null
    ) {}

    public static function success(string $contractId): self
    {
        return new self('success', $contractId);
    }

    public static function failed(string $message): self
    {
        return new self('failed', null, $message);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', null, $reason);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}
```

---

## Supported Webhook Events

| Stripe Event | Handler Method | Contract Action |
|--------------|----------------|-----------------|
| `payment_intent.succeeded` | handlePaymentSucceeded | Fulfill payment_authorized condition |
| `payment_intent.payment_failed` | handlePaymentFailed | Mark contract FAILED |
| `payment_intent.canceled` | handlePaymentCanceled | Mark contract CANCELLED |
| `charge.captured` | handleChargeCaptured | Update capturedAmount |
| `charge.refunded` | handleChargeRefunded | Update refundedAmount |
| `charge.dispute.created` | (to be implemented) | Create dispute record |
| `checkout.session.completed` | handleSessionCompleted | Alternative to payment_intent.succeeded |
| `checkout.session.expired` | handleSessionExpired | Mark contract EXPIRED |

---

## Webhook Security

1. **Signature Verification:**
   ```php
   $this->adapter->verifyWebhookSignature($payload, $signature, $secret);
   ```

2. **HTTPS Only:** Webhooks should only be received over HTTPS

3. **Idempotency:** Every event processed exactly once

4. **Logging:** All webhook events logged to `oe_payments_webhooklogs`

---

## Error Handling

```php
protected function process(array $data): WebhookResult
{
    try {
        $event = $this->parseAndValidateRequest($data);
    } catch (WebhookSignatureException $e) {
        $this->logger->error('Webhook signature verification failed', [
            'error' => $e->getMessage()
        ]);
        return WebhookResult::failed('Invalid signature');
    }

    try {
        return $this->processEvent($event);
    } catch (\Exception $e) {
        $this->logger->error('Webhook processing failed', [
            'eventId' => $event->getId(),
            'error' => $e->getMessage()
        ]);
        return WebhookResult::failed($e->getMessage());
    }
}
```
