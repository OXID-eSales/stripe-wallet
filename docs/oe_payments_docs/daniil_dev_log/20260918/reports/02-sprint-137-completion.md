# Sprint 137 — Completion report (2026-09-18)

**Branch:** `b-7.4.x-iframe-05-fresh-session-sheet` (stripe, from `b-7.4.x`) · E2E submodule
branch `fix/iframe-fresh-session-spec`. **Decision:** option B (mount on consent), Daniil 2026-09-18.

## What changed

| Area | File | Change |
|---|---|---|
| Template | `views/twig/extensions/themes/default/page/checkout/order.html.twig` | `mountOnConsent` flag; button `hidden` in every iframe state, `disabled` while unconsented; consent hint |
| i18n | `translations/{en,de}/stripe_lang.php` | `STRIPE_AGB_CONTINUE_TO_PAYMENT` |
| JS | `resources/build/js/controllers/order_submit_controller.js` → `assets/js/stripe-frontend.{js,min.js,min.js.map}` | `armConsentMount()` / `mountWhenPayable()` / `hideConsentHint()`; `consentHint` target; `mountOnConsent` value |
| Template | `views/twig/frontend/base_js.html.twig` | CSP `<meta>` removed |
| Tests | `tests/Integration/Checkout/OrderPageIframeConsentTemplateTest.php` (4) · `tests/Integration/Frontend/BaseJsTemplateTest.php` (1) | new, TDD RED first |
| E2E | `stripe-order-page-fresh-session-iframe.spec.ts` (new) · `stripe-order-page-iframe` · `stripe-checkout` · `stripe-eager-mount-single-session` | contract of iframe mode: no button, ever |
| Docs | CHANGELOG `[Unreleased]`, this log | |

**No PHP under `src/` changed.** No server behaviour changed: the consent guard in
`createCheckoutSession()` still answers 400 without `ord_agb=1`; the tick simply sends it.

## Verification

| Check | Result |
|---|---|
| `OrderPageIframeConsentTemplateTest` + `BaseJsTemplateTest` + `SingleShippingOrderTemplateTest` | 9/9 (after `rm -rf var/cache/*`) |
| Stripe Integration suite | 98 tests, 420 assertions, OK |
| PHPCS (`src/`) | clean |
| PHPStan level max (`src/`) | 0 errors |
| E2E `stripe-order-page-fresh-session-iframe` (fresh context) | ✓ 46 s — button hidden+disabled on load, hint visible, 0 iframes, 0 session calls; tick → 1× `createCheckoutSession…&ord_agb=1` 200, iframe attached, hint gone; reload → `eager=true`, sheet on load; **0 console errors, 0 page errors** |
| E2E `stripe-order-page-iframe` | ✓ 26 s |
| E2E `stripe-eager-mount-single-session` | ✓ 30 s — exactly one session call |
| E2E `stripe-checkout` EN + DE (adaptive) | ✓ 27 s + 28 s — mount-on-consent branch, sheet inline, no redirect (card completion inside the sheet stays best-effort, as before) |
| Console errors per checkout | 14 → **0** |

## Pre-existing gate failures — not introduced here

Reported so nobody hunts them in this diff (`git diff b-7.4.x -- src/` is empty):

1. **PHPMD** `StripeOrderController.php:57 TooManyMethods` — 27 non-getter/setter methods, limit 25.
   Present on `b-7.4.x`; candidate for a follow-up extraction (return-leg handling, e.g.
   `checkoutSuccess`/`checkoutCancel` + their helpers into a `CheckoutReturnController` collaborator).
2. **Unit suite aborts at test collection** with
   `Class "OxidEsales\Payments\Mollie\Controller\PaymentController_parent" not found`
   (`ModuleChainsGenerator` building the `payment` chain from a unit-test fixture while Mollie +
   Stripe + PayPal are active). This is the documented multi-PSP chain re-entrancy problem; the
   Stripe unit fixtures that extend chain members need the `PreloadsModuleClassChain`-style
   `setUpBeforeClass()` guard Mollie already carries, or the suite must run with one PSP active.
   The Integration suite (which does not hit it) is green.

## Follow-up (same day, Daniil's review)

The "Creating checkout session…" status line stayed under the mounted sheet. `mountEmbeddedCheckout()`
now clears the status right after `mount()`; the redundant re-set before the mount is gone. Pinned in
`stripe-order-page-fresh-session-iframe.spec.ts` (RED with the exact text, GREEN after the rebuild)
on both the tick path and the eager reload path.

## Follow-up 2 (same day, Daniil): single payment method + single delivery set

Both payment-base skip flags act only when the customer is offered exactly one payment method and
one delivery set (confirmed: the payment step redirects straight to `cl=order` for the test user).
On that page Stripe's private copy of `shippingAndPayment` dropped **both** cards entirely — so the
page the customer confirms never named the carrier. payment-base's own template (non-Stripe orders)
keeps the shipping heading + carrier name and drops only the form/pencil (Sprint 07, revised
2026-08-31), and drops the payment card whole (Sprint 06).

Stripe's copy now agrees, and mirrors core's structure (edit form inside the heading, body
separate): `<h4 data-stripe-order-card="shipping">` always renders with the carrier name; the
`#orderShipping` form + pencil only when several sets exist. `<h4 data-stripe-order-card="payment">`
+ `#orderPayment` + the Stripe method include render only when several methods exist.

TDD: `SingleShippingOrderTemplateTest` gained the shown-not-changeable contract (3 tests RED → GREEN,
probe ship set with a real title); Integration checkout tests 10/10. Live: new adaptive spec
`stripe-order-page-single-method-cards.spec.ts` GREEN (shipping heading visible, 0 pencils, carrier
named, 0 `#orderShipping`, 0 `#orderPayment`); `stripe-order-page-fresh-session-iframe` still GREEN.
Not touched: Mollie's own order-template copy, if it has the same divergence.

## Deploy notes
- JS: `npm run build:prod && npm run build:dev` in `extensions/stripe`; the shop serves
  `out/modules/oe_payments_stripe_wallet` → `extensions/stripe/assets` via symlink — no install-assets needed.
- Twig: `rm -rf var/cache/*` (the shop's compiled templates live there, not in `source/tmp`).
- Translations: new key picked up after the cache wipe.
