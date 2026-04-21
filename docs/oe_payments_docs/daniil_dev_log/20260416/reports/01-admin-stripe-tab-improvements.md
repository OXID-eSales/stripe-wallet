# Admin Stripe Tab Improvements — 2026-04-17

**Branch:** `b-7.4.x`
**Scope:** Admin order detail → Stripe tab — refund UX, partial refunds, loading indicator, dead code cleanup

---

## Summary

Four areas of work on the admin Stripe tab:

| # | Area | Files Changed | Tests |
|---|------|--------------|-------|
| 1 | Remove redundant "fully refunded" notice | 3 | — |
| 2 | Multiple partial refunds support | 8 | 840 unit (2 new), 1 Playwright spec (4 tests) |
| 3 | Confirm dialogs on all actions | 3 | Playwright page object updated |
| 4 | Loading indicator | 4 | 1 Playwright spec (2 tests) |

**Totals:** 11 source files modified, 2 new Playwright specs, 840 unit tests passing, PHPStan clean.

---

## 1. Remove Redundant "Fully Refunded" Notice

### Problem

After a successful refund, two messages appeared simultaneously:
- "Refund was successful." (success alert)
- "This order has been refunded completely already." (warning notice)

The warning was legacy — redundant when a success message is already shown.

### Changes

| File | Change |
|------|--------|
| `views/twig/admin/stripe_order_refund.html.twig` | Removed `elseif blIsOrderRefundable == false` warning block |
| `views/admin_twig/en/stripe_lang.php` | Removed `STRIPE_ORDER_NOT_REFUNDABLE` |
| `views/admin_twig/de/stripe_lang.php` | Removed `STRIPE_ORDER_NOT_REFUNDABLE` |

---

## 2. Multiple Partial Refunds

### Problem

Only one refund was possible per order. After a successful refund, the refund box disappeared — preventing subsequent partial refunds. Additionally, the refund amount entered in the form was ignored; every refund was always a full refund.

### Root Cause (2 bugs)

**Bug A — Refund box hidden after first refund:**
`OrderRefund::isOrderRefundable()` had an early return that hid the refund section when `_blSuccessfulRefund === true && fnc == 'fullRefund'`. This was legacy logic from when only full refunds were supported.

**Bug B — Partial amount ignored:**
The amount flowed correctly from the form → controller → `EventContext` → `StripeRefundRequestEvent::getAmount()`, but:
- `StripeRefundRequestHandler::executeRefund()` called `processFullRefund()` **without** passing the amount
- `RefundService::processFullRefund()` had **no amount parameter** — always passed `null` (= full refund) to the Stripe adapter
- The adapter's `createRefundByCharge(?int $amount)` already supported partial refunds — only the intermediate layers were broken

### Fix

| File | Change |
|------|--------|
| `src/Stripe/Controller/Admin/OrderRefund.php` | Removed `_blSuccessfulRefund` early return in `isOrderRefundable()`; removed dead `isFullRefundAvailable()` method |
| `src/Stripe/Service/RefundServiceInterface.php` | Renamed `processFullRefund()` → `processRefund()`, added `?float $amount` parameter |
| `src/Stripe/Service/RefundService.php` | Added `$amount` parameter, converts to cents (`round($amount * 100)`), passes to `executeRefundByCharge()` |
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Passes `$event->getAmount()` to `processRefund()`; `updateContractState()` records partial amounts instead of skipping them |
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | Added `getRemainingRefundableRaw()` and `getCaptureableRaw()` returning raw floats for HTML number inputs |
| `src/Stripe/Controller/Admin/OrderRefund.php` | Added `getRemainingRefundableRaw()` and `getCaptureableRaw()` controller methods |
| `views/twig/admin/stripe_order_refund.html.twig` | Input `value`/`max` use raw floats (not locale-formatted strings); removed dead "Refund notice" description field |

### Dead Code Removed

- `OrderRefund::isFullRefundAvailable()` — never called from any template
- Translation keys: `STRIPE_FULL_REFUND`, `STRIPE_FULL_REFUND_TEXT`, `STRIPE_FULL_REFUND_NOT_AVAILABLE`, `STRIPE_REFUND_REMAINING`, `STRIPE_REFUND_DESCRIPTION`, `STRIPE_REFUND_DESCRIPTION_PLACEHOLDER` (all unused)
- Handler idempotency guard that skipped contract update for partial refunds

### Unit Tests

| File | Change |
|------|--------|
| `tests/Unit/Stripe/Service/RefundServiceTest.php` | All `processFullRefund` calls → `processRefund`; added `testProcessPartialRefundPassesAmountInCents` |
| `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php` | Replaced `testSkipsContractUpdateWhenNotFullRefund` with `testPartialRefundUpdatesContractWithPartialAmount`; removed obsolete idempotency guard test |

### Playwright E2E

**New spec:** `tests/admin/stripe-partial-refund.spec.ts` — 4 serial tests:
1. Refund 1.00 EUR → refund box still visible
2. Refund 0.10 EUR → refund box still visible
3. Refund 12.00 EUR → refund box still visible
4. Refund remaining → refund box disappears

**Page object updates** (`AdminStripeOrderPage.ts`):
- `executeRefund()` accepts optional `amount` parameter for partial refunds
- `getRefundableAmount()` reads the input field value
- `isOrderAlreadyRefunded()` → renamed to `isOrderFullyRefunded()` (checks absence of refund section)

---

## 3. Confirm Dialogs on All Actions

### Problem

Only "Cancel Authorization" had a browser `confirm()` dialog. Capture and Refund submitted immediately without confirmation.

### Fix

| File | Change |
|------|--------|
| `views/twig/admin/stripe_order_refund.html.twig` | Added `onclick="return confirm('...')"` to capture and refund submit buttons |
| `views/admin_twig/en/stripe_lang.php` | Added `STRIPE_CAPTURE_CONFIRM`, `STRIPE_REFUND_CONFIRM` |
| `views/admin_twig/de/stripe_lang.php` | Added `STRIPE_CAPTURE_CONFIRM`, `STRIPE_REFUND_CONFIRM` |

**Playwright:** `executeRefund()` and `executeCapture()` in `AdminStripeOrderPage.ts` updated to accept the `confirm()` dialog (same pattern as `executeCancelAuthorization()`).

---

## 4. Loading Indicator

### Problem

The Stripe tab makes 3 synchronous Stripe API calls during server-side rendering. Users see a blank iframe for 1-5 seconds with no feedback.

### Design

- **Default state:** Loading overlay visible, content hidden (CSS only)
- **`DOMContentLoaded`:** JS hides overlay, reveals content
- **Form submit:** JS re-shows overlay when capture/refund/cancel form is submitted (fires after `confirm()` dialog)
- **Server errors (500/40x):** OXID core shows its own error page — our template never renders
- **Stripe API errors:** Already handled by `hasStripeApiError()` alert

### Changes

| File | Change |
|------|--------|
| `views/twig/admin/stripe_order_refund.html.twig` | Loading overlay CSS, overlay HTML (conditional on `isStripeOrder()`), `s-content-loading` class on content wrapper, inline `<script>` |
| `views/admin_twig/en/stripe_lang.php` | Added `STRIPE_LOADING` = "Loading Stripe data..." |
| `views/admin_twig/de/stripe_lang.php` | Added `STRIPE_LOADING` = "Stripe-Daten werden geladen..." |

### Playwright

**Page object** (`AdminStripeOrderPage.ts`):
- `isLoadingOverlayVisible()` — checks overlay doesn't have `s-hidden` class
- `waitForContentLoaded()` — waits for overlay to get `s-hidden` class

**New spec:** `tests/admin/stripe-loading-indicator.spec.ts` — 2 tests:
1. Overlay is hidden after page load, content visible
2. Overlay re-appears on action form submit (uses `preventDefault()` to inspect state)

---

## Verification

```
PHPStan:  0 errors (level max)
Unit:     840 tests, 1993 assertions — all pass
PHPCS:    0 errors
```

## All Modified Files

### Source (11 files, 210 insertions, 118 deletions)

```
src/Stripe/Controller/Admin/OrderRefund.php
src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php
src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php
src/Stripe/Service/RefundService.php
src/Stripe/Service/RefundServiceInterface.php
tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php
tests/Unit/Stripe/Service/RefundServiceTest.php
views/admin_twig/de/stripe_lang.php
views/admin_twig/en/stripe_lang.php
views/twig/admin/stripe_order_refund.html.twig
docs/oe_payments_docs/daniil_dev_log/20260416/reports/01-admin-stripe-tab-improvements.md
```

### Playwright (4 modified, 2 new)

```
playwright/pages/admin/AdminStripeOrderPage.ts          (modified)
playwright/tests/admin/stripe-admin-order.spec.ts       (modified)
playwright/tests/admin/stripe-admin-refund.spec.ts      (modified)
playwright/tests/admin/stripe-partial-refund.spec.ts    (new)
playwright/tests/admin/stripe-loading-indicator.spec.ts (new)
```
