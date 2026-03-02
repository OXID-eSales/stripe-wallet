# Sprint 64f — CSRF on Frontend AJAX (H8)

**Date:** 2026-02-24
**Status:** DONE
**Finding:** H8 (No CSRF on Payment Endpoints — frontend part)

## Summary

Added `validateSessionChallenge()` to `StripeOrderController`. Both `createCheckoutSession()` (AJAX/JSON) and `executeStripePayment()` (form POST) now check `checkSessionChallenge()` before proceeding. Updated JS to include `stoken` in all fetch URLs. Added hidden `stoken` input to checkout Twig template.

## Changes

### Modified (3)
- `src/Stripe/Controller/StripeOrderController.php` — Added `validateSessionChallenge()` method, added CSRF check at start of `createCheckoutSession()` (returns 403 JSON) and `executeStripePayment()` (returns 'payment' redirect)
- `resources/build/js/controllers/order_submit_controller.js` — Added `buildUrlWithCsrfToken()` helper, updated `handleStripeCheckout()` and `handlePayment()` fetch calls to include stoken
- `views/twig/extensions/themes/default/page/checkout/order.html.twig` — Added hidden `stoken` input with `oViewConf.getSessionChallengeToken()`

### Created (1)
- `tests/Unit/Stripe/Controller/StripeOrderControllerCsrfTest.php` — 5 tests with `TestableStripeOrderControllerForCsrf` subclass

## Test Results

```
Tests: 5, Assertions: 8, Failures: 0
```

## CSRF Protection Design

| Endpoint | Method | CSRF Failure Response |
|----------|--------|-----------------------|
| `createCheckoutSession` | AJAX POST | HTTP 403 + JSON `{"error": "Session expired..."}` |
| `executeStripePayment` | Form POST | Redirect to `payment` page + error display |

## JS Token Flow

```
1. Twig renders: <input type="hidden" name="stoken" value="{{ oViewConf.getSessionChallengeToken() }}" />
2. JS reads: document.querySelector('input[name="stoken"]')?.value
3. JS appends: url + '&stoken=' + encodeURIComponent(stoken)
4. PHP validates: Registry::getSession()->checkSessionChallenge()
```
