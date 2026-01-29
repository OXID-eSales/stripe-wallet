# Sprint 6: Contract-Aware Webhook Processing - DONE

**Date:** 2024-12-04
**Status:** COMPLETED
**Branch:** `b-7.4.x-auth-STRP-70`

## Summary

Implemented contract-aware webhook processing that bridges Stripe webhooks to the contract state machine, resolving the "two paths" ambiguity identified in the architecture review.

## Problem Statement (From Yesterday's Analysis)

```
BEFORE: WebhookProcessingService bypassed contract layer
- Direct SQL updates to oe_payments_order_state, oxorder
- Contract stuck in COMMITTED state forever
- No ContractFulfilledEvent dispatched
- State machine integrity violated
```

## Solution Implemented

### New Components Created

| File | Purpose |
|------|---------|
| `src/Stripe/Handler/WebhookContractFulfillmentHandlerInterface.php` | Interface for contract-aware webhook handling |
| `src/Stripe/Handler/WebhookContractFulfillmentHandler.php` | Implementation bridging webhooks to contract state machine |
| `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php` | TDD tests (10 tests, 27 assertions) |

### Handler Methods

```php
interface WebhookContractFulfillmentHandlerInterface
{
    // Handles payment_intent.succeeded, checkout.session.completed
    public function handlePaymentSucceeded(string $providerOrderId): ?bool;

    // Handles charge.captured
    public function handleChargeCaptured(string $providerOrderId): ?bool;

    // Handles charge.refunded
    public function handleChargeRefunded(string $providerOrderId, float $refundAmount): ?bool;

    // Handles payment_intent.payment_failed
    public function handlePaymentFailed(string $providerOrderId, string $failureReason): ?bool;
}
```

### Return Value Semantics

| Return | Meaning |
|--------|---------|
| `true` | Contract found, state transitioned successfully |
| `false` | Contract found but skipped (idempotent/wrong state) |
| `null` | Contract not found (legacy order, use fallback) |

## Implementation Details

### 1. WebhookContractFulfillmentHandler Flow

```
handlePaymentSucceeded(providerOrderId)
    │
    ├── findByProviderOrderId(pi_xxx)
    │   └── ContractRepository lookup
    │
    ├── Idempotency check: isFulfilled()?
    │   └── return false (already processed)
    │
    ├── State validation: isCommitted()?
    │   └── return false if not COMMITTED
    │
    ├── contract.fulfill()
    │   └── Transitions COMMITTED → FULFILLED
    │
    ├── contractRepository.save(contract)
    │
    └── dispatch(ContractFulfilledEvent)
        └── Event for downstream handlers
```

### 2. WebhookProcessingService Integration

Modified `WebhookProcessingService` to:
1. Accept `WebhookContractFulfillmentHandlerInterface` as **required** dependency
2. Try contract-aware path first for all payment events
3. Fall back to legacy direct SQL for orders without contracts

```php
// Sprint 6: Try contract-aware fulfillment first
$contractResult = $this->contractFulfillmentHandler->handlePaymentSucceeded($paymentIntent->id);

if ($contractResult === true) {
    // Contract fulfilled - update order fields for backward compat
    $this->updateOrderFieldsAfterContractFulfillment($paymentIntent->id);
    return;
}

if ($contractResult === false) {
    // Already fulfilled (idempotent) or not in COMMITTED state
    return;
}

// Contract not found (null) - use legacy fallback
$this->processLegacyPaymentSucceeded($paymentIntent);
```

### 3. Services.yaml Configuration

```yaml
# Interface binding
OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandlerInterface:
  class: OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandler
  arguments:
    $contractRepository: '@...ContractRepositoryInterface'
    $eventDispatcher: '@...EventDispatcherInterface'

# WebhookProcessingService - Now requires handler
OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService:
  arguments:
    $contractFulfillmentHandler: '@...WebhookContractFulfillmentHandlerInterface'
    $eventDispatcher: '@...EventDispatcherInterface'
    $webhookLogRepository: '@...WebhookLogRepositoryInterface'
```

## Test Coverage

### Unit Tests Created (10 tests)

| Test | Purpose |
|------|---------|
| `handlerFindsContractByProviderOrderId` | Contract lookup works |
| `handlerSkipsAlreadyFulfilledContract` | Idempotency |
| `handlerRejectsNonCommittedContract` | State validation |
| `handlerTransitionsContractToFulfilled` | State transition |
| `handlerDispatchesContractFulfilledEvent` | Event dispatch |
| `handlerReturnsOrderIdFromContract` | Order ID retrieval |
| `handlerReturnsNullWhenContractNotFound` | Legacy fallback |
| `handlerHandlesChargeCapturedEvent` | charge.captured |
| `handlerHandlesChargeRefundedEvent` | charge.refunded |
| `handlerHandlesPaymentFailed` | payment_intent.payment_failed |

### Test Results

```
PHPUnit 11.5.44
Tests: 10, Assertions: 27
OK
```

## Files Modified

| File | Changes |
|------|---------|
| `src/Stripe/Service/WebhookProcessingService.php` | Added handler dependency, contract-aware processing for all events |
| `services.yaml` | Added handler service definitions |
| `tests/Unit/Stripe/Webhook/PaymentIntentWebhookTest.php` | Updated for new constructor |
| `tests/Unit/Stripe/Webhook/ChargeWebhookTest.php` | Updated for new constructor |
| `tests/Unit/Stripe/Webhook/DisputeWebhookTest.php` | Updated for new constructor |
| `tests/Unit/Stripe/Service/WebhookProcessingServiceRepositoryTest.php` | Updated for new constructor |
| `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php` | Get service from container |

## Architecture After Sprint 6

```
                    ┌─────────────────┐
                    │     Stripe      │
                    │   (Webhook)     │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │WebhookController│
                    └────────┬────────┘
                             │
              ┌──────────────▼──────────────┐
              │  WebhookProcessingService   │
              │  (Routes events)            │
              └──────────────┬──────────────┘
                             │
    ┌────────────────────────▼────────────────────────┐
    │      WebhookContractFulfillmentHandler (NEW)     │
    │  ┌───────────────────────────────────────────┐  │
    │  │ 1. findByProviderOrderId()                │  │
    │  │ 2. Validate state (COMMITTED?)            │  │
    │  │ 3. contract.fulfill()                     │  │
    │  │ 4. contractRepository.save()              │  │
    │  │ 5. dispatch(ContractFulfilledEvent)       │  │
    │  └───────────────────────────────────────────┘  │
    └─────────────────────────────────────────────────┘
                             │
    ┌────────────────────────▼────────────────────────┐
    │            ContractFulfilledEvent               │
    │  (Downstream handlers can react)                │
    └─────────────────────────────────────────────────┘
```

## Outstanding Items

### PHPStan Errors (Pre-existing)

The following PHPStan errors are **pre-existing** in the codebase and not introduced by Sprint 6:
- Stripe SDK magic property access (`$paymentIntent->id`, etc.)
- These require Stripe SDK type stubs or ignoring at PHPStan level

### Future Improvements

1. **Integration tests** - Add tests that verify contract state in DB after webhook
2. **E2E tests** - Playwright tests triggering webhooks via Stripe CLI
3. **Refund contract state** - Consider adding REFUNDED state to contract

## Verification

```bash
# Run Sprint 6 tests
vendor/bin/phpunit --group sprint-6 --testsuite Unit

# Run all unit tests
vendor/bin/phpunit --testsuite Unit
# Result: 1098 tests, 2449 assertions - OK
```

## Conclusion

Sprint 6 successfully bridges Stripe webhooks to the contract state machine:

| Before | After |
|--------|-------|
| Contract bypassed | Contract looked up by providerOrderId |
| Direct SQL updates | State machine transitions |
| No validation | COMMITTED state validated |
| No events | ContractFulfilledEvent dispatched |
| Contract stuck in COMMITTED | Contract transitions to FULFILLED |
