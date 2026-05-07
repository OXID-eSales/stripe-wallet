# Sprint 101 Completion Report — AGB Confirmation Enforcement on Stripe Order

**Date completed:** 2026-05-07
**Branch:** `b-7.4.x`
**Engineer:** daniil.tkachev@oxid-esales.com

## Summary

Sprint 101 landed two complementary layers of AGB enforcement:

1. **Backend gate (authoritative):** `StripeOrderController::createCheckoutSession()` now
   rejects requests where `blConfirmAGB` is enabled but `ord_agb != "1"` with HTTP 400.
   The guard runs after session-challenge validation and before cleanup side-effects.

2. **Frontend UX:** `#stripe-checkout-btn` is wrapped in a `data-controller="agb-validation"`
   container. The pre-existing Stimulus controller resolves `#checkAgbTop` in `connect()`
   by its stable apex DOM ID and disables the button until the customer ticks the checkbox.

## Files changed (all under `source/extensions/stripe/`)

| File | Change |
|---|---|
| `src/Stripe/Controller/ControllerRequestHelper.php` | Added `getAgbAcceptedFromRequest()`, `isAgbConfirmationRequired()`, constants `AGB_REQUEST_KEY`, `AGB_ACCEPTED_VALUE` |
| `src/Stripe/Controller/StripeOrderController.php` | Added `ensureAgbAccepted()` guard in `createCheckoutSession()`; extracted `setHttpResponseCode()` seam |
| `views/twig/extensions/themes/default/page/checkout/order.html.twig` | Wrapped stripe-checkout-btn with `data-controller="agb-validation"` container |
| `resources/build/js/controllers/agb_validation_controller.js` | Replaced checkbox Stimulus target with `document.getElementById('checkAgbTop')` in `connect()` |
| `tests/Unit/Stripe/Controller/ControllerRequestHelperAgbReaderTest.php` | New — 8 tests (H1–H5 + 3 config tests) |
| `tests/Unit/Stripe/Controller/StripeOrderControllerAgbConfirmationTest.php` | New — 6 tests (T1–T6) |
| `tests/Unit/Stripe/Controller/StubControllerRequestHelper.php` | Added `agbAcceptedFromRequest`, `agbConfirmationRequired` fields and overrides |
| `tests/Unit/Stripe/Controller/StripeOrderControllerRetryTest.php` | Extended — seed `agbAcceptedFromRequest=true` on happy-path tests; add `setHttpResponseCode` no-op |

## Test results

| Suite | Tests | Assertions | Result |
|---|---|---|---|
| Unit | 821 | 1976 | PASS |
| Integration | 157 | 417 | PASS (53 skipped — DB-dependent, pre-existing) |

New tests added: **14** (8 helper + 6 controller).

## Static analysis

| Tool | Result |
|---|---|
| PHPCS | 0 errors |
| PHPStan level 6 (src/) | 0 new errors (4 pre-existing unrelated errors unchanged) |
| PHPMD | 0 new findings; baseline unchanged |

## TDD cycle

1. H1–H5 + config tests — RED (`getAgbAcceptedFromRequest` undefined) → GREEN after §6.1.
2. T1, T2 negative paths — RED (`ensureAgbAccepted` missing, HTTP 200 instead of 400) → GREEN after §6.2 + `setHttpResponseCode` seam.
3. T3 positive path — GREEN out of the box (helper returns true → guard returns true → existing dispatch path).
4. T4 off-flag path — GREEN out of the box.
5. T5 basket error ordering — GREEN (AGB gate is before basket check in the try block).
6. T6 session ordering — GREEN (AGB gate is after session validation).
7. §4.3 existing retry test — updated to seed `agbAcceptedFromRequest=true` on happy-path tests.

## Deviations from plan

- **`setHttpResponseCode()` seam added** — not explicitly named in §6.2, but required to make T5/T6 assert HTTP status codes without the tests calling `http_response_code()` directly. Analogous to `exitWithJson()`. Zero suppression, zero PHPMD increase.
- **JS `'checkbox'` removed from `static targets`** — the original controller had `'checkbox'` as a Stimulus target. Since the edit boundary forbids adding `data-agb-validation-target="checkbox"` to apex's `#checkAgbTop`, we removed that target and route all checkbox access through `this._coreCheckbox` (set in `connect()`). The `handleSubmit` method was updated accordingly.
- **8 helper tests written instead of 5** — H1–H5 are the plan's tests; the 3 `isAgbConfirmationRequired` tests were added as the natural symmetric coverage for the second new method.

## Manual smoke

Not performed (no live environment access during this session). Steps §7.4 recorded here for operator verification:

1. Set `blConfirmAGB = on`, clear cache, reach Stripe order page.
2. Verify `#stripe-checkout-btn` has `disabled` attribute (Stimulus controller sets it on `connect()`).
3. Force-click via dev console — controller returns HTTP 400 with `{"error": "..."}`.
4. Tick `#checkAgbTop` — button enables; checkout proceeds normally.
5. With `blConfirmAGB = off` — button enabled regardless; no gate (T4 semantics).

## Edit boundary verification

`git diff --name-only HEAD~3..HEAD` (from `source/extensions/stripe/`) confirms all changed
files are under `source/extensions/stripe/`. No edits to `source/source/**` or
`source/vendor/**`.
