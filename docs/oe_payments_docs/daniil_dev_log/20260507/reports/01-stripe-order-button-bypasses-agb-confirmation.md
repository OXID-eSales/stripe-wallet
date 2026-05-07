# Report 01 — Stripe "Order now" bypasses AGB confirmation

**Date:** 2026-05-07
**Branch:** `b-7.4.x`
**Reporter:** daniil.tkachev@oxid-esales.com
**Severity:** High — legal/compliance risk: an order can be created and payment captured without the customer confirming Terms & Conditions when the shop admin requires it.
**Status:** Open — sprint plan: [`../sprints/sprint-101-agb-confirmation-enforcement-on-stripe-order.md`](../sprints/sprint-101-agb-confirmation-enforcement-on-stripe-order.md)

## 1. Symptom

On the order confirmation page (`?cl=order`), with the shop admin setting

> System → Core Settings → "Settings" tab →
> "Users have to Confirm General Terms and Conditions during Check-Out" (`blConfirmAGB`) = **on**,

clicking the Stripe **"Order now"** button (`#stripe-checkout-btn`) creates a
Stripe Checkout Session and redirects the user to the Stripe hosted page —
**without** the user having ticked the AGB / "I agree to the Terms and
Conditions" checkbox (`#checkAgbTop`, input name `ord_agb`).

Expected behaviour: while the AGB checkbox is unchecked the "Order now"
button must be disabled (or the request rejected at the controller), so the
order is impossible to submit until the customer has accepted the terms.

## 2. Where it goes wrong

The Stripe order page extension overrides the standard OXID submit flow:

```
source/extensions/stripe/views/twig/extensions/themes/default/page/checkout/order.html.twig
  block checkout_order_next_step_side
    -> renders <button id="stripe-checkout-btn" data-controller="order-submit" ...>
       (no AGB validation wired in)
```

The button is wired to `OrderSubmitController` (Stimulus,
`resources/build/js/controllers/order_submit_controller.js`). On click it
calls `cl=order&fnc=createCheckoutSession` via `fetch()` and redirects the
browser to Stripe.

Two layers fail to enforce AGB:

1. **Frontend:** the standard apex `agb.js`
   (`source/Application/views/apex/build/js/pages/checkout/order/agb.js`) only
   *mirrors* the checkbox value into `<input name=ord_agb>` clones — it does
   not disable the standard OXID order button, and the Stripe button is not
   the standard OXID order button anyway. There is **no listener** on
   `#stripe-checkout-btn` that consults `#checkAgbTop`.
2. **Backend:** `StripeOrderController::createCheckoutSession()` (lines 159-231
   in `src/Stripe/Controller/StripeOrderController.php`) validates the session
   challenge, the basket count, the user, and the API key configuration —
   but **does not check `blConfirmAGB` against the request `ord_agb` flag**.
   OXID's stock `OrderController::execute()` does this check, but
   `createCheckoutSession()` is its own entry point that never reaches that
   path.

A Stimulus controller `agb_validation_controller.js` is already present in
`resources/build/js/controllers/` and registered in `app.js` (line 12, 20),
but **no template wires it up** (`grep agb-validation views/` returns
nothing). It is dead code today.

## 3. Reproduction

1. Log in as admin → System → Core Settings → tab "Settings" →
   set **blConfirmAGB = on**, save.
2. Run `bin/oe-console oe:cache:clear`.
3. As a frontend customer: add a product, go through checkout, choose a
   Stripe payment method (e.g. Stripe Wallet), arrive at the order page.
4. Confirm `#checkAgbTop` is rendered and unchecked.
5. Click **Order now** (`#stripe-checkout-btn`) **without ticking** the AGB
   checkbox.
6. **Observed:** browser is redirected to `https://checkout.stripe.com/...`,
   the contract is created, and on payment success an order is finalized.
7. **Expected:** the button stays disabled until the AGB checkbox is ticked,
   and even with a forged request (curl / dev console) the controller
   rejects the call with an error.

## 4. Why both layers must be fixed

Frontend-only is insufficient: a sophisticated customer (or a
test/automation script that strips the disabled attribute) can still POST to
`createCheckoutSession`. Backend-only is insufficient: the user gets a generic
"failed to create session" error after a network round-trip rather than a
clear, immediate visual cue at the checkbox.

## 5. Constraints / non-goals

- **Do not** modify the apex `agb.html.twig` template
  (`source/source/Application/views/apex/tpl/page/checkout/inc/agb.html.twig`).
  The fix lives in the **Stripe module**: its order template extension and its
  controller. Apex is OXID core; the module owns its overrides.
- **Do not** change the meaning of `blConfirmAGB`. We honour the existing
  OXID setting; we do not introduce a new flag.
- **Do not** auto-tick or hide the checkbox under any circumstance.

## 6. Related code

| File | Lines | Role |
|---|---|---|
| `source/source/Application/views/apex/tpl/page/checkout/inc/agb.html.twig` | 20-25 | Renders `#checkAgbTop`; gated on `oView.isConfirmAGBActive()` |
| `source/source/Application/Controller/OrderController.php` | 394-403 | `isConfirmAGBActive()` returns `blConfirmAGB` config flag |
| `source/extensions/stripe/views/twig/extensions/themes/default/page/checkout/order.html.twig` | 63-89 | Renders `#stripe-checkout-btn` (no AGB wiring) |
| `source/extensions/stripe/src/Stripe/Controller/StripeOrderController.php` | 159-231 | `createCheckoutSession()`; no `blConfirmAGB` check |
| `source/extensions/stripe/resources/build/js/controllers/agb_validation_controller.js` | — | Pre-existing Stimulus controller; **not wired** to any template |
| `source/extensions/stripe/resources/build/js/app.js` | 12, 20 | Registers `agb-validation` |
| `source/extensions/stripe/src/Stripe/Controller/ControllerRequestHelper.php` | — | Where the new request reader for `ord_agb` belongs |

## 7. Suggested fix shape (full TDD plan in sprint 101)

- **Backend (authoritative gate):** in `createCheckoutSession()`, when
  `Registry::getConfig()->getConfigParam('blConfirmAGB')` is true, require
  the request to contain `ord_agb=1`. If absent, return HTTP 400 with
  `{"error": "..."}` and do **not** dispatch `StripeCheckoutSessionRequestEvent`.
  Reading lives in `ControllerRequestHelper`; the controller calls a single
  `ensureAgbAccepted()` (or equivalent) early in the try block.
- **Frontend (UX):** wire `agb-validation` Stimulus controller onto the
  Stripe block in `extensions/stripe/views/twig/extensions/themes/default/page/checkout/order.html.twig`
  so that `#stripe-checkout-btn` is disabled until `#checkAgbTop` is checked,
  matching the visual behaviour customers expect from OXID. The controller
  already exists in `resources/build/js/controllers/agb_validation_controller.js`.
- **Tests first:** Unit test on the controller for the rejection path;
  unit + integration coverage where the existing
  `tests/Unit/Stripe/Controller/StripeOrderControllerRetryTest.php` pattern
  already proves we can drive `createCheckoutSession` from unit tests.

## 8. Test-side notes

- Existing pattern for testing `createCheckoutSession` at unit level:
  `tests/Unit/Stripe/Controller/StripeOrderControllerRetryTest.php` uses an
  anonymous subclass of `StripeOrderController` and a
  `StubControllerRequestHelper`. The new test fits beside it.
- E2E (Playwright) coverage is **out of scope** of report 01. The auth-flow
  flakiness called out in earlier reports still applies; we keep the new
  enforcement covered by unit + integration tests, and add the e2e regression
  in a follow-up sprint once the env stabilises.

## 9. Severity rationale

If a customer disputes a charge or files a Widerruf complaint and the shop
admin claims AGB acceptance was a checkout precondition, an order created
without the `ord_agb=1` flag is evidence that no acceptance was obtained.
This is a legal/compliance defect, not a cosmetic one — hence **High**.