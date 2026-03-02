# BUG: "Can only add refunded amount in FULFILLED state" on Admin Refund

**Date:** 2026-02-26
**Severity:** HIGH
**Status:** FIXED

## Symptom

When processing a refund in the OXID admin Stripe panel:

1. First attempt: Error message "Can only add refunded amount in FULFILLED state"
2. Second attempt: "This order has been refunded completely already."

The refund actually goes through on Stripe's side, but the admin reports an error. This is a **silent success with false error** — the worst kind of bug because:
- The admin thinks the refund failed and retries
- Stripe rejects the retry (already refunded)
- The contract state is never updated with the refund amount

## Root Cause

In `StripeRefundRequestHandler::updateContractState()` (line 210-231), the method calls `$contract->addRefundedAmount()` without checking if the contract is in `FULFILLED` state.

`PaymentContract::addRefundedAmount()` enforces a domain invariant:
```php
public function addRefundedAmount(float $amount): void
{
    if (!$this->state->isFulfilled()) {
        throw new DomainException('Can only add refunded amount in FULFILLED state');
    }
    // ...
}
```

**The sequence:**

| Step | What happens | Problem |
|------|-------------|---------|
| 1 | `RefundService::processFullRefund()` calls Stripe API | Refund succeeds |
| 2 | `handleRefundResult()` calls `updateContractState()` | |
| 3 | `updateContractState()` calls `$contract->addRefundedAmount()` | Contract is in `COMMITTED` state, not `FULFILLED` |
| 4 | `addRefundedAmount()` throws `DomainException` | |
| 5 | Exception propagates to `handle()` catch block (line 73) | Error reported to admin |
| 6 | Admin shows: "Can only add refunded amount in FULFILLED state" | **Despite Stripe refund succeeding** |
| 7 | Admin retries → Stripe says "already refunded" | Second error |

**Why the contract is not FULFILLED:**
The contract lifecycle is: `COMMITTED → FULFILLED`. Fulfillment happens via webhook (`charge.succeeded` or `checkout.session.completed`). If the webhook hasn't arrived or the fulfillment step was skipped/failed, the contract stays in `COMMITTED` state. The admin refund action doesn't check or require fulfillment first.

## Fix

Added a state guard in `updateContractState()` before calling `addRefundedAmount()`. If the contract is not in `FULFILLED` state, log a warning and return gracefully instead of letting the `DomainException` propagate.

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`

```diff
+        // addRefundedAmount() requires FULFILLED state. Skip recording the refund amount
+        // on the contract if it hasn't been fulfilled yet (e.g. still COMMITTED).
+        // The Stripe refund already succeeded at this point — we must not throw here
+        // as that would report an error to the admin despite the refund being processed.
+        if (!$contract->getState()->isFulfilled()) {
+            $this->logger->warning('Cannot record refund on contract: not in FULFILLED state', [
+                'contractId' => $contractId,
+                'state' => $contract->getState()->getValue(),
+            ]);
+            return;
+        }
+
         $contract->addRefundedAmount($contract->getAmount());
```

**Test:** `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php`
- Updated `testSuccessfulRefundUpdatesContractRefundTracking` to mock `getState()` returning `fulfilled`
- Added new test `testSkipsRefundAmountWhenContractNotInFulfilledState` verifying graceful skip with warning log
- Added `ContractState` import

## Verification

- Unit tests: 715 passed, 1713 assertions (full suite, +1 test from previous 714)
- StripeRefundRequestHandlerTest: 21 passed, 47 assertions
- No regressions

## Relationship to Bug #01

This bug is closely related to the order totals zeroing bug (report #01). Both occur during the refund flow:
- Bug #01: `OxidStockRestorationService` zeroes order totals via `recalculateOrder()`
- Bug #02: `StripeRefundRequestHandler` throws DomainException on non-fulfilled contract

Both bugs share the same root flow: `RefundService::handleRefundResponse()` triggers both stock restoration and contract state update after the Stripe API refund succeeds.
