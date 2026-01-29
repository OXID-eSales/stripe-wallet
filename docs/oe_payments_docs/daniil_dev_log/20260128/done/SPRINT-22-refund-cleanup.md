# Sprint 22: Refund Architecture Cleanup

**Date:** 2026-01-28
**Priority:** HIGH
**Status:** TODO

---

## Objective

Clean up dead code and simplify refund architecture:
1. Delete dead `OrderRefundUpdateService` (writes to non-existent fields)
2. Delete redundant `PaymentRefundService` (empty default implementation)
3. Remove partial refund from Stripe module (Stripe = full refund only)
4. Keep partial refund in payment-component for other providers

---

## Tasks

### Task 1: Delete Dead Code - OrderRefundUpdateService (Stripe)

**Files to DELETE:**
```
src/Stripe/Service/OrderRefundUpdateService.php
src/Stripe/Service/OrderRefundUpdateServiceInterface.php
tests/Unit/Stripe/Service/OrderRefundUpdateServiceTest.php
```

**Files to MODIFY:**
- `services.yaml` - Remove service registration
- `StripeRefundRequestHandler.php` - Remove injection and call

**Why:** Service writes to non-existent database fields (`STRIPEDELCOSTREFUNDED`, etc.)

---

### Task 2: Delete Redundant PaymentRefundService (payment-component)

**Files to DELETE:**
```
extensions/payment-component/src/Service/PaymentRefundService.php
extensions/payment-component/tests/Unit/Service/PaymentRefundServiceTest.php
```

**Why:** Empty class that just extends `AbstractPaymentRefundService` with no customization.
`StripeRefundService` already extends abstract directly.

---

### Task 3: Remove Partial Refund from RefundService

**File:** `src/Stripe/Service/RefundService.php`

**Changes:**
- Remove `processPartialRefund()` method
- Update `processRefundByCharge()` to always do full refund (remove `$amountCents` parameter or ignore it)

**File:** `src/Stripe/Service/RefundServiceInterface.php`

**Changes:**
- Remove `processPartialRefund()` method signature

---

### Task 4: Remove partialRefund from OrderRefund Controller

**File:** `src/Stripe/Controller/Admin/OrderRefund.php`

**Changes:**
- Remove `partialRefund()` method
- Update template to not show partial refund option

---

### Task 5: Clean Dead STRIPE* Field References

**File:** `src/Stripe/Controller/Admin/OrderRefund.php`

**Method:** `isFullRefundAvailable()`

**Current (dead code):**
```php
if (
    ($oOrder->oxorder__stripedelcostrefunded->value ?? 0) > 0
    || ($oOrder->oxorder__stripepaycostrefunded->value ?? 0) > 0
    // ... more non-existent fields
) {
    return false;
}
```

**Fix:** Remove dead field checks, use Stripe API data instead (already available via `getStripeApiOrderLastCharge()`)

---

### Task 6: Update StripeRefundService to Reject Partial

**File:** `src/Stripe/Service/StripeRefundService.php`

**Changes:**
```php
class StripeRefundService extends AbstractPaymentRefundService
{
    /**
     * Override to reject partial refunds.
     * Stripe module only supports full refunds.
     */
    protected function validateRefundAmount(string $contractId, float $refundAmount, float $availableForRefund): void
    {
        // Stripe only supports full refund
        if (abs($refundAmount - $availableForRefund) > 0.01) {
            throw new RefundFailedException(
                $contractId,
                'Stripe module only supports full refunds. Use Stripe Dashboard for partial refunds.'
            );
        }

        parent::validateRefundAmount($contractId, $refundAmount, $availableForRefund);
    }
}
```

---

### Task 7: Update StripeRefundRequestHandler

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`

**Changes:**
- Remove `OrderRefundUpdateServiceInterface` from constructor
- Remove call to `$this->orderRefundUpdateService->updateOrderAfterFullRefund($order)`
- Simplify `updateOrderAfterRefund()` method

---

### Task 8: Update Tests

#### Tests to DELETE (entire files)

| File | Reason |
|------|--------|
| `tests/Unit/Stripe/Service/OrderRefundUpdateServiceTest.php` | Service deleted |
| `extensions/payment-component/tests/Unit/Service/PaymentRefundServiceTest.php` | Service deleted |

#### Tests to MODIFY

**File:** `tests/Unit/Stripe/Service/RefundServiceTest.php`

Remove these test methods (partial refund tests):
- `testProcessPartialRefundWithPaymentIntentId()`
- `testProcessPartialRefundRequiresPaymentIntentId()`
- `testProcessPartialRefundIncludesMetadata()`

Keep these test methods (full refund + processRefundByCharge):
- `testRefundResultSuccessCreation()`
- `testRefundResultFailureCreation()`
- `testRefundResultPendingStatus()`
- `testServiceImplementsInterface()`
- `testProcessRefundByChargeSuccessful()`
- `testProcessRefundByChargeFullRefund()`
- `testProcessRefundByChargePendingStatus()`
- `testProcessRefundByChargeFailedStatus()`
- `testProcessRefundByChargeHandlesAdapterException()`
- `testProcessFullRefundSuccess()`
- `testProcessFullRefundHandlesChargeObjectInPaymentIntent()`
- `testProcessFullRefundFailsWhenNoCharge()`
- `testValidRefundReasons()`
- `testSuccessfulRefundIsLogged()`
- `testFailedRefundIsLoggedAsError()`

---

**File:** `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php`

Changes:
- Remove `OrderRefundUpdateServiceInterface` mock (line 12, 33, 42)
- Remove `OrderRefundUpdateServiceInterface` from constructor call
- Remove `$this->refundService->expects($this->never())->method('processPartialRefund')` (line 82)
- Update handler constructor in `createHandler()` method

---

**File:** `tests/Integration/Stripe/Controller/Admin/OrderRefundControllerTest.php`

Changes:
- Remove `partialRefund()` related tests (if any)
- Remove tests checking STRIPE* fields (if any)

---

**File:** `tests/Unit/Stripe/Service/StripeRefundServiceTest.php`

Changes:
- Add test for partial refund rejection
- `testRejectsPartialRefundAmount()` - verify exception thrown when amount !== availableForRefund

---

### Task 9: Run Pre-Commit Check (Full)

```bash
./bin/pre-commit-check.sh --full
```

**Note:** Use `--full` flag to run both Unit AND Integration tests.

Expected: All tests pass, PHPStan/PHPCS/PHPMD clean

---

## Acceptance Criteria

### Code Cleanup
- [ ] No `OrderRefundUpdateService` references in codebase
- [ ] No `PaymentRefundService` in payment-component (abstract is enough)
- [ ] No `processPartialRefund` in Stripe module
- [ ] No `partialRefund()` in OrderRefund controller
- [ ] No STRIPE* field references in OrderRefund controller
- [ ] `StripeRefundService` rejects partial refund requests

### Tests
- [ ] `OrderRefundUpdateServiceTest.php` deleted
- [ ] `PaymentRefundServiceTest.php` (payment-component) deleted
- [ ] `RefundServiceTest.php` - partial refund tests removed (3 methods)
- [ ] `StripeRefundRequestHandlerTest.php` - OrderRefundUpdateService mock removed
- [ ] `StripeRefundServiceTest.php` - partial rejection test added
- [ ] All remaining tests pass (Unit + Integration)
- [ ] `./bin/pre-commit-check.sh --full` passes

---

## Definition of Done

1. All tasks completed
2. Tests updated and passing
3. Pre-commit check passes
4. Move this file to `done/SPRINT-22-refund-cleanup.md`
5. Update `status.md`
