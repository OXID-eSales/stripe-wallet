# Sprint 114.10a Completion Report

**Sprint:** 114.10a — Agnostic boundary: low-risk subset (L3, A3, A2, §6.2 currency-DTO)
**Branch:** `b-7.4.x-code-review-STRP-145`
**Date:** 2026-05-28

---

## R-1…R-10 Gate Checklist

- [x] R-1 TDD: RED shown before GREEN for all 4 commits; no method-under-test re-implemented
- [x] R-2 SOLID: no god-object; PHPMD baseline unchanged (3 entries)
- [x] R-3 LI: all 4 instanceof PaymentContract downcasts removed; PHPStan level max passes
- [x] R-4 DI: PaymentIntentResolver constructor-injected into StripeRefundRequestHandler; wired in services.yaml
- [x] R-5 Clean Code: early returns only; explicit imports; no magic literals; methods ≤25 lines
- [x] R-6 DevOps-first: `pre-commit-check.sh --full` green; no new suppressions
- [x] R-7 Event-driven: no new events/handlers; existing paths unchanged
- [x] R-8 Contract-aware: contract transitions via interface methods; no generic setState()
- [x] R-9 No overengineering: dead oxNew(Order) path removed, not abstracted
- [x] R-10 Persistence: writes via event→service→repository; no new direct writes

---

## Commits

### stripe repo (branch: `b-7.4.x-code-review-STRP-145`)

| # | Hash | Message |
|---|------|---------|
| 1 | `4f3e822` | STRP-145 Sprint 114.10a (L3): drop spurious instanceof PaymentContract downcasts |
| 2 | `d7f2255` | STRP-145 Sprint 114.10a (A3): refund handler resolves PI via agnostic resolver |
| 3 | `350b013` | STRP-145 Sprint 114.10a (A2): move normalized status constants to payment-base (additive) |
| 4 | `4469d35` | STRP-145 Sprint 114.10a (currency-DTO): add optional currency field to Capture/RefundPaymentRequest (additive) |

### payment-base repo (branch: `b-7.4.x`)

| # | Hash | Message |
|---|------|---------|
| A | `3450ce7` | STRP-145 Sprint 114.10a (A2): add NormalizedPaymentStatus to payment-base (additive) |
| B | `d8709e9` | STRP-145 Sprint 114.10a (currency-DTO): add optional currency to Capture/RefundPaymentRequest (additive) |

---

## Commit 1 — L3: Drop spurious instanceof PaymentContract downcasts

**Finding:** `WebhookContractFulfillmentHandler` had 4 `instanceof PaymentContract` guards
before calling `fail()`, `cancel()`, `getCapturedAmount()`, and the refund recorder.
All 4 methods are on `PaymentContractInterface` — the downcasts were spurious (R-3.2 violation).

**Files changed:**
- `src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php`
  - Removed all 4 `instanceof PaymentContract` guards (keeping bodies intact)
  - Updated 3 private helper method signatures: `PaymentContract $contract` → `PaymentContractInterface $contract`
  - Removed `PaymentContract` import (no longer needed)
- `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php`
  - 3 new RED→GREEN parity tests:
    - `handlePaymentFailedWorksWithPaymentContractInterface`
    - `handlePaymentCanceledWorksWithPaymentContractInterface`
    - `handleChargeRefundedRecordsRefundAmountOnInterface`

**RED evidence:** 2 tests failing (`handlePaymentFailed`/`handlePaymentCanceled` returned `false` instead of `true` for interface mock, proving the guards blocked them).

**Test counts:** 15 → 18 tests, 42 → 53 assertions.

---

## Commit 2 — A3: Refund handler resolves PI via agnostic resolver

**Finding:** `StripeRefundRequestHandler` called `oxNew(Order::class)` + read
`$order->oxorder__oxtransid->value` to obtain the PaymentIntent ID — coupling the
handler to OXID's magic-property API. Capture and cancel handlers already used
`PaymentIntentResolver` (from 114.8) for agnostic resolution.

**Files changed:**
- `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`
  - Removed `loadOrder()` method entirely (oxNew(Order::class) gone)
  - Removed `Order` import
  - Added `PaymentIntentResolver` import and optional constructor param
  - New `resolvePaymentIntentId()` method mirrors `StripeCaptureRequestHandler::getPaymentIntentId()`
  - `logRefundRequest()` and `setSuccessResults()` now take `string $orderId` (not `Order`)
  - `handleRefundResult()` signature updated (Order removed)
- `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php`
  - 3 new RED→GREEN tests:
    - `processRefundResolvesPaymentIntentIdViaContractRepository`
    - `processRefundUsesExplicitPaymentIntentIdWhenProvided`
    - `processRefundSetsErrorWhenPaymentIntentIdCannotBeResolved`
- `services.yaml`
  - Wired `$paymentIntentResolver: '@OxidEsales\Payments\Stripe\Service\PaymentIntentResolver'`

**RED evidence:** 3 tests failing (1+2: refundService->processRefund was never called because oxNew(Order) failed in unit tests; 3: error message differed).

**Test counts:** 20 → 23 tests, 53 → 69 assertions.

---

## Commit 3 — A2: Move normalized status constants to payment-base

**Finding:** `StripeStatusMapper` declared 7 `public const STATUS_*` strings with docblock
"used across all providers" — meaning they conceptually belong in payment-base, not the
Stripe adapter layer.

**payment-base files added:**
- `src/Adapter/Response/NormalizedPaymentStatus.php` — new class, 7 constants
- `tests/Unit/Adapter/Response/NormalizedPaymentStatusTest.php` — 8 tests

**stripe files changed:**
- `src/Stripe/Adapter/StripeStatusMapper.php`
  - Added `use NormalizedPaymentStatus`
  - 7 `STATUS_*` constants now delegate: `public const STATUS_PENDING = NormalizedPaymentStatus::PENDING`
  - Updated docblock pointing to canonical location
  - All existing callers of `StripeStatusMapper::STATUS_*` unchanged (backwards-compatible aliases)

**grep result (no paypal/OPC references):**
```
$ grep -rn "StripeStatusMapper::STATUS_" extensions/paypal/ extensions/one-page-checkout/
(no output)
```

**Test counts (payment-base):** 906 → 914 tests, 2083 → 2101 assertions (+8 new tests).

---

## Commit 4 — §6.2: Add optional currency to Capture/RefundPaymentRequest

**Finding:** `CapturePaymentRequest` and `RefundPaymentRequest` had no `currency` field,
so `AmountConverter::toMinorUnits(amount, '')` defaulted to 2-decimal math — converting
1000 JPY to 100000 minor units (wrong; should be 1000).

**payment-base files changed (additive):**
- `src/Adapter/Request/CapturePaymentRequest.php` — added `?string $currency = null` as trailing optional param
- `src/Adapter/Request/RefundPaymentRequest.php` — same
- `tests/Unit/Adapter/Request/CapturePaymentRequestTest.php` — 2 new tests for currency field
- `tests/Unit/Adapter/Request/RefundPaymentRequestTest.php` — 2 new tests for currency field

**stripe files changed:**
- `src/Stripe/Adapter/Helper/PaymentIntentHelper.php:300` — `toMinorUnits($amount, $request->currency ?? '')`
- `src/Stripe/Adapter/Helper/RefundHelper.php:89` — same
- `src/Stripe/Service/CaptureService.php` — threads `$contract->getCurrency()` into `executeCapture()`
- `tests/Unit/Stripe/Core/AmountConverterTest.php` — 3 new tests:
  - `captureRequestWithJpyCurrencyConvertsToCorrectMinorUnits` (1000.0 JPY → 1000)
  - `refundRequestWithJpyCurrencyConvertsToCorrectMinorUnits` (1000.0 JPY → 1000)
  - `withoutCurrencyEmptyStringDefaultsToTwoDecimalsForJpy` (regression proof: '' → 100000 (wrong), 'JPY' → 1000 (correct))

**Additive guarantee:** all existing `new CapturePaymentRequest(...)` / `new RefundPaymentRequest(...)` calls with 3-4 positional args are unchanged; the new `currency` parameter is trailing and optional.

---

## Payment-base Files Touched (Additive Audit)

Files added in payment-base:
- `src/Adapter/Response/NormalizedPaymentStatus.php` — new class (A2)
- `tests/Unit/Adapter/Response/NormalizedPaymentStatusTest.php` — new test (A2)

Files modified in payment-base:
- `src/Adapter/Request/CapturePaymentRequest.php` — `?string $currency = null` appended (§6.2)
- `src/Adapter/Request/RefundPaymentRequest.php` — `?string $currency = null` appended (§6.2)
- `tests/Unit/Adapter/Request/CapturePaymentRequestTest.php` — 2 tests added (§6.2)
- `tests/Unit/Adapter/Request/RefundPaymentRequestTest.php` — 2 tests added (§6.2)

No existing payment-base class signatures changed. No interface methods added. All consumers (paypal, one-page-checkout) need zero edits.

---

## Test Suite Results

### Before sprint (baseline)
- Stripe Unit: 1001 tests, 2387 assertions
- Payment-base Unit: 906 tests, 2083 assertions
- PayPal Unit: 449 tests, 798 assertions
- OPC Unit: 220 tests, 557 assertions, 1 pre-existing error (PayPal class chain)

### After all 4 commits (final)
- Stripe Unit: **1010 tests, 2422 assertions** (+9 tests, +35 assertions) — GREEN
- Stripe Integration: 141 tests, 356 assertions, 53 skipped — GREEN
- Payment-base Unit: **918 tests, 2101 assertions** (+12 tests, +18 assertions) — GREEN
- PayPal Unit: **449 tests, 798 assertions** — UNCHANGED, GREEN
- OPC Unit: **220 tests, 557 assertions, 1 pre-existing error** — UNCHANGED

### Quality gate
- PHPCS: 0 errors
- PHPStan: 0 errors (level max)
- PHPMD: 0 new violations (baseline unchanged, 3 entries)
- `pre-commit-check.sh --full`: ALL CHECKS PASSED

---

## End-state grep verification

```
# instanceof PaymentContract in WebhookContractFulfillmentHandler
$ grep -n "instanceof PaymentContract" src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php
(no output)  ✓

# oxNew(Order::class) in StripeRefundRequestHandler
$ grep -n "oxNew(Order::class)" src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php
(no output)  ✓

# Remaining oxNew(Order) in stripe/src — all legitimate OXID adapter seams
$ grep -rn "oxNew(Order::class)" src/Stripe/
  src/Stripe/Service/OxidStockRestorationService.php:50   — OXID adapter seam
  src/Stripe/Service/OxidContractLinkedOrderUpdater.php:57 — OXID adapter seam
  src/Stripe/Admin/StripePanelOrderLoader.php:27          — OXID admin seam
  src/Stripe/Adapter/OxidShopOrderService.php:51          — OXID adapter seam
  src/Stripe/Adapter/OxidShopOrderService.php:219         — OXID adapter seam

# Remaining instanceof PaymentContract in stripe/src — NOT in scope of this sprint
$ grep -rn "instanceof PaymentContract" src/Stripe/
  src/Stripe/Service/RetryCleanupService.php:83           — different sprint scope
  src/Stripe/PaymentHandler/StripePaymentHandler.php:202  — different sprint scope
```

---

## Notes

- **OPC pre-existing error:** `OxidEsales\Payments\PayPal\Controller\PaymentController_parent not found` — this is a test-environment class-chain issue predating this sprint, confirmed by running the OPC suite both with and without the sprint changes.
- **A1 (big DTO migration) deferred** to 114.10b as planned.
- **PHPMD baseline unchanged** at 3 entries (LazyStripeAdapter, StripeAdapter, OrderRefund) — no new complexity added.
