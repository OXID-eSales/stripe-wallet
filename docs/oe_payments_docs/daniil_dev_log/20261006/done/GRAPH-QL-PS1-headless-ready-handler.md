# GRAPH-QL / PS1 — StripePaymentHandler is contract-first and headless-ready (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-stripe-provider-story.md](../sprints/GRAPH-QL-stripe-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What changed (`src/Stripe/PaymentHandler/StripePaymentHandler.php`)

| Change | Why |
|---|---|
| `implements ContractFirstPaymentHandlerInterface` (payment-base marker) | payment-base's `PaymentHandlerRegistry` only sees handlers that declare themselves contract-first; the OPC standard handler shares the `oe.payment.handler` tag |
| `metadata.headless === true` ⇒ no in-flight reuse | one call per basket in headless; payment-base's attempt guard and retire-by-basket own duplicates |
| `uiMode` ∉ {`hosted`, `embedded`} ⇒ `STRIPE_UI_MODE_UNSUPPORTED` before any contract/order exists | `custom` (Payment Element on a Checkout Session secret) is phase 4 of the epic |
| early order: `CreateOrderRequest{sessionId: metadata.sessionId ?? 'headless:<basketId>', basketId}` for headless; session preparation (basket payment, `paymentid`, skip-address flag) only for OPC, behind `prepareSessionForOrder()` | payment-base's order service builds the shop basket from the `oxuserbaskets` row and restores the address hash itself |
| `basket_id` stamped on the contract when headless | next start for the basket retires this attempt; basket removed on commit (payment-base S3/S6) |
| Checkout Session URLs: headless ⇒ `returnUrl` + `session_id={CHECKOUT_SESSION_ID}` as success/return URL, `cancelUrl` (or the return URL) as cancel URL, `embedded` = `uiMode === 'embedded'`; OPC ⇒ the shop URLs as before (`shopUrls()`) | the client needs the Stripe session id for `stripeCheckoutReturn`; URLs arrive already validated by payment-base's policy |
| result `renderMode`: headless embedded ⇒ `embedded` (OPC keeps `iframe`) | payment-base's `CheckoutStartResult` vocabulary |
| `sessionId()` / `prepareSessionForOrder()` protected seams | the only `Registry::getSession()` uses left, unit-testable |

## Red → green

`tests/Unit/Stripe/PaymentHandler/StripePaymentHandlerHeadlessTest.php` (6): marker; headless start (order request
basket/session ids, Stripe URLs, no reuse lookup, no session seam calls, `basket_id`, PENDING, provider id);
embedded mode (return URL with existing query string, client secret, `renderMode embedded`); unsupported uiMode
refused before creation; missing cancel URL falls back to the return URL; OPC path still prepares the session and
uses the shop URLs.

## Gates

- Unit (standalone `phpunit-unit.xml`) **1593** green (8 warnings / 22 deprecations pre-existing) · Integration (shop PHPUnit) **100** green, 1 skip
- PHPStan level max No errors · phpcs clean · phpmd (`tests/PhpMd/phpmd.xml`) clean

## Notes

- `StripeCheckoutSessionHandler` (the Twig event path) is untouched: the headless start goes through the handler, not
  through `StripeCheckoutSessionRequestEvent`.
- With the marker in place, payment-base's integration test `HeadlessWiringTest::testCorePlaceOrderIsRefusedForAContractFirstPayment`
  stops skipping in this shop (verified in PS2 after the container cache clear).
