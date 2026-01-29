# Sprint 5: Handle Authorization State in Return Flow

**Status:** PENDING
**Priority:** HIGH
**Estimated Effort:** 2 hours
**Depends On:** Sprint 1, Sprint 3, Sprint 4

---

## Objective

Modify the Stripe checkout return flow to handle the `requires_capture` status from PaymentIntents when manual capture mode is enabled.

---

## Current Flow (Automatic Capture)

```
User completes Stripe Checkout
          ↓
PaymentIntent.status = 'succeeded'
          ↓
StripeCheckoutReturnHandler
          ↓
PaymentAuthorizedEvent (for auto-capture, this means captured)
          ↓
Contract: PENDING → READY_TO_COMMIT
          ↓
Order created
```

## Target Flow (Manual Capture)

```
User completes Stripe Checkout
          ↓
PaymentIntent.status = 'requires_capture'
          ↓
StripeCheckoutReturnHandler
          ↓
PaymentAuthorizedEvent (this is ONLY authorization)
          ↓
Contract: PENDING → AUTHORIZED (NOT ready_to_commit!)
          ↓
[Wait for manual capture]
          ↓
CaptureRequestedEvent (from admin)
          ↓
PaymentIntent.capture()
          ↓
PaymentCapturedEvent
          ↓
Contract: AUTHORIZED → READY_TO_COMMIT
          ↓
Order created
```

---

## Tasks

### 1. Update StripeCheckoutReturnHandler

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

```php
private function handlePaymentIntentStatus(
    PaymentContract $contract,
    string $paymentIntentId,
    string $status
): void {
    switch ($status) {
        case 'succeeded':
            // Payment authorized AND captured (automatic mode)
            $this->dispatchPaymentAuthorizedEvent($contract, $paymentIntentId, true);
            break;

        case 'requires_capture':
            // Payment authorized but NOT captured (manual mode)
            $this->handleRequiresCaptureStatus($contract, $paymentIntentId);
            break;

        case 'requires_action':
            // Needs additional authentication
            $this->handleRequiresActionStatus($contract, $paymentIntentId);
            break;

        default:
            $this->logger->warning('Unexpected PaymentIntent status', [
                'status' => $status,
                'payment_intent_id' => $paymentIntentId,
            ]);
    }
}

private function handleRequiresCaptureStatus(
    PaymentContract $contract,
    string $paymentIntentId
): void {
    $this->logger->info('Payment authorized, awaiting capture', [
        'contract_id' => $contract->getId(),
        'payment_intent_id' => $paymentIntentId,
    ]);

    // Store PaymentIntent ID for later capture
    $contract->setMetadataValue('provider_payment_id', $paymentIntentId);

    // Transition to AUTHORIZED (not READY_TO_COMMIT)
    $contract->authorize();
    $this->contractRepository->save($contract);

    // Emit authorization event (NOT PaymentCapturedEvent!)
    $this->eventDispatcher->dispatch(new ContractAuthorizedEvent(
        contract: $contract,
        providerPaymentId: $paymentIntentId,
        authorizedAmount: $this->getAuthorizedAmount($contract),
        context: $this->buildEventContext($contract)
    ));
}
```

### 2. Update PaymentAuthorizedEventHandler

**File:** `src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php`

The existing handler needs to check capture mode:

```php
public function handle(PaymentAuthorizedEvent $event): void
{
    $contract = $this->contractRepository->findById($event->getContractId());

    if ($contract === null) {
        return;
    }

    // Check if this is auto-captured (succeeded) or just authorized
    if ($event->isCaptured()) {
        // Auto-capture mode: proceed to READY_TO_COMMIT
        $this->fulfillPaymentCondition($contract);
        $contract->transitionToReadyToCommit();
    } else {
        // Manual capture mode: stay in AUTHORIZED state
        // The contract transition was already done in the return handler
        $this->logger->info('Payment authorized, awaiting manual capture', [
            'contract_id' => $contract->getId(),
        ]);
    }

    $this->contractRepository->save($contract);
}
```

### 3. Retrieve PaymentIntent Status

Ensure we're correctly checking the PaymentIntent status:

```php
private function getPaymentIntentFromSession(Session $session): PaymentIntent
{
    $paymentIntentId = $session->payment_intent;

    if (empty($paymentIntentId)) {
        throw new \RuntimeException('No PaymentIntent found in checkout session');
    }

    return $this->stripeAdapter->retrievePaymentIntent($paymentIntentId);
}
```

### 4. Update Thank You Page Behavior

For manual capture mode, the thank you page should indicate that the payment is authorized but not yet charged:

**File:** `src/Component/Controller/Core/ThankyouController.php`

```php
public function getPaymentStatus(): string
{
    $contract = $this->getContract();

    if ($contract === null) {
        return 'unknown';
    }

    if ($contract->getState()->isAuthorized()) {
        return 'authorized'; // Payment reserved, not charged yet
    }

    if ($contract->getState()->isFulfilled()) {
        return 'completed';
    }

    return 'processing';
}
```

---

## Unit Tests

**File:** `tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php`

Add tests for `requires_capture` status:

```php
public function testHandleRequiresCaptureTransitionsToAuthorized(): void
{
    // Arrange
    $contract = $this->createPendingContract('contract-123');
    $session = $this->createCheckoutSession('cs_123', 'pi_123');
    $paymentIntent = $this->createPaymentIntent('pi_123', 'requires_capture');

    $this->stripeAdapter->method('retrievePaymentIntent')
        ->willReturn($paymentIntent);

    $this->contractRepository->expects($this->once())
        ->method('save')
        ->with($this->callback(function (PaymentContract $contract) {
            return $contract->getState()->isAuthorized();
        }));

    $this->eventDispatcher->expects($this->once())
        ->method('dispatch')
        ->with($this->isInstanceOf(ContractAuthorizedEvent::class));

    // Act
    $this->handler->handle($this->createReturnEvent($session, $contract));

    // Assert
    $this->assertTrue($contract->getState()->isAuthorized());
}

public function testHandleSucceededTransitionsToReadyToCommit(): void
{
    // Arrange
    $contract = $this->createPendingContract('contract-123');
    $session = $this->createCheckoutSession('cs_123', 'pi_123');
    $paymentIntent = $this->createPaymentIntent('pi_123', 'succeeded');

    $this->stripeAdapter->method('retrievePaymentIntent')
        ->willReturn($paymentIntent);

    // Act
    $this->handler->handle($this->createReturnEvent($session, $contract));

    // Assert
    $this->assertTrue($contract->getState()->isReadyToCommit());
}
```

---

## Acceptance Criteria

- [ ] `requires_capture` status transitions contract to AUTHORIZED
- [ ] `succeeded` status transitions contract to READY_TO_COMMIT (existing behavior)
- [ ] PaymentIntent ID is stored in contract metadata for later capture
- [ ] `ContractAuthorizedEvent` is emitted for `requires_capture`
- [ ] Thank you page shows appropriate message for authorized state
- [ ] Existing automatic capture flow unchanged
- [ ] All unit tests pass
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Status Mapping

| PaymentIntent Status | Capture Mode | Contract State | Order Created |
|---------------------|--------------|----------------|---------------|
| `succeeded` | Automatic | READY_TO_COMMIT → COMMITTED → FULFILLED | Yes |
| `requires_capture` | Manual | AUTHORIZED | No (wait for capture) |
| `requires_action` | Either | PENDING | No |
| `canceled` | Either | CANCELLED | No |

---

## Test Commands

```bash
# Run return handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php

# Run all handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  tests/Unit/Stripe/EventSystem/Handler/
```

---

## Notes

- The `requires_capture` status is specific to Stripe's manual capture mode
- The PaymentIntent ID must be stored for later capture
- Order should NOT be created until payment is captured (for manual mode)
- Customer sees "Payment authorized" message, not "Payment completed"
