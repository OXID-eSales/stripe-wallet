# Bug Report: Refund Handler Calls Non-Existent `setState()` on PaymentContract

**Date:** 2026-02-06
**Severity:** HIGH (blocks all admin refund operations)
**Error:** `Call to undefined method OxidEsales\PaymentComponent\Contract\PaymentContract::setState()`

---

## 1. Error Reproduction

**Trigger:** Admin panel > Order > Stripe tab > Full Refund button

**Log output:**
```
Logger.ERROR: Refund handler exception {
  "error": "Call to undefined method OxidEsales\\PaymentComponent\\Contract\\PaymentContract::setState()",
  "order_id": "3a11e6e2b654ad02d43a93caaec67557"
}
```

**Impact:** The Stripe API refund call **succeeds** (money is returned to the customer), but the post-refund contract update crashes, causing:
1. Error shown in admin UI instead of success message
2. Contract state not updated (stays `fulfilled` instead of reflecting refund)
3. Request log entry not written (line 204 never reached)
4. Success results not set in context (line 205 never reached)

---

## 2. Root Cause

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php:220`

```php
private function updateContractState(StripeRefundRequestEvent $event): void
{
    $contractId = $event->getContractId();
    if ($contractId === null || !$event->isFullRefund()) {
        return;
    }

    $contract = $this->contractRepository->findById($contractId);
    if ($contract === null) {
        return;
    }

    $contract->setState('REFUNDED');  // <-- BUG: This method does not exist
    $this->contractRepository->save($contract);
}
```

The handler calls `$contract->setState('REFUNDED')`, but `PaymentContract` has **no `setState()` method**. Additionally, `'REFUNDED'` is **not a valid contract state**.

### Why `setState()` doesn't exist

`PaymentContract` uses a **state machine pattern** with explicit transition methods. State changes are only allowed through named domain methods:

| Method | Transition |
|--------|-----------|
| `transitionToNotFinished($orderId)` | DRAFT -> NOT_FINISHED |
| `transitionToPending()` | NOT_FINISHED -> PENDING |
| `authorize()` | PENDING -> AUTHORIZED |
| `captureAuthorization()` | AUTHORIZED -> READY_TO_COMMIT |
| `fulfillCondition($type)` | (may trigger) PENDING -> READY_TO_COMMIT |
| `commitToOrder($orderId)` | READY_TO_COMMIT -> COMMITTED |
| `fulfill()` | COMMITTED -> FULFILLED |
| `cancel($reason)` | any non-terminal -> CANCELLED |
| `fail($reason)` | any non-terminal -> FAILED |
| `expire()` | any non-terminal -> EXPIRED |

There is no `setState()` method and no `REFUNDED` state in `ContractState::VALID_STATES`.

### Valid states (from `ContractState.php`):
```
draft, not_finished, pending, authorized, ready_to_commit, committed, fulfilled, cancelled, expired, failed
```

### Why `'REFUNDED'` is not a state

Refund is tracked **not as a state transition** but as **financial data** on the contract:
- `addRefundedAmount(float $amount)` — accumulates refunded amounts
- `setRefundedAt(DateTimeInterface $date)` — records refund timestamp

This design is correct: a refund doesn't change the contract lifecycle (the order is still fulfilled). The `ChargeRefundedHandler` (webhook handler) already does this correctly at lines 69-71:
```php
$contract->addRefundedAmount($refundAmount);
$contract->setRefundedAt(new \DateTimeImmutable());
$this->contractRepository->save($contract);
```

---

## 3. Call Flow Analysis

```
Admin clicks "Full Refund"
  → OrderRefund::fullRefund()
    → dispatches StripeRefundRequestEvent
      → StripeRefundRequestHandler::handle()
        → processRefund()
          → executeRefund()          ✅ Stripe API call succeeds (money returned)
          → handleRefundResult()
            → updateContractState()  ❌ CRASHES HERE: setState() doesn't exist
            → logRefundRequest()     ⛔ Never reached
            → setSuccessResults()    ⛔ Never reached
        → catch block catches the error
          → handleException()        ✅ Logs error, sets error in context
```

**Critical:** The refund is processed by Stripe (money sent back) but the admin sees an error. This creates a confusing state where the refund happened but OXID doesn't know about it.

---

## 4. Why Tests Didn't Catch This

The unit test file `StripeRefundRequestHandlerTest.php` tests the event/context handling but **does not test the `updateContractState` flow**. No test exercises the path where:
1. A successful `RefundResponse` is returned
2. A `contractId` is present in the event
3. The contract repository returns a contract

The handler's `handle()` method calls `processRefund()` which requires loading an OXID `Order` object via `oxNew(Order::class)`, which can't run in unit tests. The integration test suite would need to cover this path.

---

## 5. Solution Options

### Option A: Use existing refund tracking methods (RECOMMENDED)

Replace `setState('REFUNDED')` with the same pattern used by `ChargeRefundedHandler`:

```php
private function updateContractState(StripeRefundRequestEvent $event): void
{
    $contractId = $event->getContractId();
    if ($contractId === null || !$event->isFullRefund()) {
        return;
    }

    $contract = $this->contractRepository->findById($contractId);
    if ($contract === null) {
        return;
    }

    $contract->addRefundedAmount($contract->getAmount());
    $contract->setRefundedAt(new \DateTimeImmutable());
    $this->contractRepository->save($contract);
}
```

**Pros:**
- Uses existing, tested API on PaymentContract
- Consistent with webhook handler (`ChargeRefundedHandler`)
- Records actual financial data (amount + timestamp)
- No changes to payment-component needed

**Cons:**
- May double-count if webhook also fires (both admin handler and webhook record the refund)

### Option B: Remove `updateContractState()` entirely

The webhook `charge.refunded` from Stripe will handle the contract update via `ChargeRefundedHandler`. The admin handler doesn't need to do it.

```php
private function handleRefundResult(
    RefundResponse $result,
    StripeRefundRequestEvent $event,
    Order $order,
    EventContext $context
): void {
    if (!$result->isSuccessful()) {
        $context->set('error', $result->errorMessage);
        $context->set('errorCode', $result->errorCode);
        $context->set('refundSuccess', false);
        return;
    }

    // Removed: updateContractState() - webhook handles this
    $this->logRefundRequest($result, $order);
    $this->setSuccessResults($context, $result, $order);
}
```

**Pros:**
- Simplest fix — just remove the broken code
- No double-counting risk
- Single source of truth (webhook handler)

**Cons:**
- Contract won't reflect refund until webhook arrives (seconds delay)
- If webhook fails/is delayed, contract data is stale

### Option C: Add `REFUNDED` state to ContractState (NOT RECOMMENDED)

Add a `REFUNDED` terminal state and a `refund()` transition method to `PaymentContract`.

**Cons:**
- Requires changes to `payment-component` (separate package)
- "Refunded" is not a lifecycle state — it's a financial event
- A contract can be partially refunded (not a terminal state)
- Breaks the state machine design philosophy

---

## 6. Recommendation

**Option A** is recommended as the primary fix, with an idempotency guard to handle webhook overlap:

```php
private function updateContractState(StripeRefundRequestEvent $event): void
{
    $contractId = $event->getContractId();
    if ($contractId === null || !$event->isFullRefund()) {
        return;
    }

    $contract = $this->contractRepository->findById($contractId);
    if ($contract === null) {
        return;
    }

    // Only update if not already refunded (webhook may have arrived first)
    if ($contract->getRefundedAmount() === null || $contract->getRefundedAmount() < 0.01) {
        $contract->addRefundedAmount($contract->getAmount());
        $contract->setRefundedAt(new \DateTimeImmutable());
        $this->contractRepository->save($contract);
    }
}
```

This ensures:
- Immediate contract update in admin (no waiting for webhook)
- No double-counting if webhook arrives later
- Consistent with existing PaymentContract API

---

## 7. Test Coverage Needed

A unit test must be added for `updateContractState` by mocking the `ContractRepositoryInterface`:

```php
public function testUpdateContractStateAfterSuccessfulRefund(): void
{
    // Arrange: mock contract with getAmount(), addRefundedAmount(), setRefundedAt()
    // Act: trigger handler with contractId and successful refund
    // Assert: addRefundedAmount() called with contract amount
    // Assert: setRefundedAt() called
    // Assert: contractRepository->save() called
}
```

---

## 8. Files Affected

| File | Change |
|------|--------|
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php:208-222` | Fix `updateContractState()` method |
| `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php` | Add test for contract update |

---

## 9. Related

- `ChargeRefundedHandler.php` — Webhook handler that correctly uses `addRefundedAmount()` / `setRefundedAt()`
- `PaymentContract.php` — Domain model with state machine (no `setState()`)
- `ContractState.php` — Value object with 10 valid states (no `REFUNDED`)
- Sprint 10 refund handler refactor docs reference `$contract->setState('REFUNDED')` — this was a design error from the original sprint that was never tested against the actual PaymentContract API
