# The webhook endpoint answers before the stale-order sweep (DONE 2026-10-08)

**Branch:** `b-7.4.x-stripe-webhook-endpoint` · second part of "the webhook endpoint should be working!"

## What was wrong

`WebhookController::processWebhook()` ran the STRP-100 stale NOT_FINISHED sweep **before** sending its 200. The sweep
asks Stripe once or twice per stale contract (`CheckoutInFlightGuard::sessionOf()` / `inspect()`), for up to 50
contracts, and contracts whose session is still "usable" are kept and asked again on the next webhook, so the cost
never shrinks. Stripe (and `stripe listen`) abandon a delivery that has not answered within their timeout and retry it.

Measured on the dev shop with `stripe listen` forwarding to the tunnel: first delivery 32 s (`context deadline
exceeded` at the CLI, 200 in Apache), a signed replay 8 s with five stale contracts — every webhook the shop had in
fact handled counted as failed at Stripe.

## What changed

| Piece | Job |
|---|---|
| `WebhookController::processWebhook()` | log result → `sendSuccessResponse(action)` → sweep → `terminate()` |
| `sendSuccessResponse()` (protected seam) | 200 + JSON, `ignore_user_abort`, then `releaseClient()`: `fastcgi_finish_request()` under PHP-FPM (the SDK: Apache → `proxy:fcgi://php:9000`), otherwise `Content-Length` + `Connection: close` and a full flush |
| `terminate()` (protected seam) | the production `exit`, overridable so a test keeps its process |
| `RetryCleanupService` / `WebhookControllerCleanupTest` docblocks | the sweep runs on the released request; one pass stays bounded (STRP-168) |

## Red → green

`Unit\Stripe\Controller\Webhook\WebhookControllerRespondsBeforeSweepTest` (3): skipped and handled results answer
`200 <action>` → sweep → terminate, in that order, through the production `render()`/`processWebhook()`; a failed
result answers 500 and never sweeps. Red on the old code: the controller's inline `exit` killed the PHPUnit process
after printing `{"received":true,"action":"skipped"}`.

## Gates

Unit (standalone) **1641** green · PHPStan level max No errors · phpcs clean · phpmd clean.

## Live proof on the dev shop

- Signed replay through the tunnel: **0.33 s** (was 8–32 s); with the shop log at info for one request,
  `Cleaned up 50 stale NOT_FINISHED order(s)` was written **after** `WEBHOOK_RESULT` — the sweep ran on the released
  request and finished its whole batch.
- `stripe listen --forward-to … --forward-connect-to …` on the controlling platform: triggered
  `payment_intent.canceled` answered `[200]` within one second.
- Order **817** (contract `22e21309…`, `bin/graph-ql-cli-test.sh pay`, manual capture) paid on Stripe's hosted page
  with the shop's return leg intercepted by a stub client page: Stripe sent `connect payment_intent.amount_capturable_updated`
  and `connect checkout.session.completed`, both `[200]` at 12:04:00; the shop answered `contract_authorized` and
  `skipped`; `oxorder` 817 `OXTRANSSTATUS = OK`, contract `committed` at 12:04:00, `OXPAID` unset (authorized, capture
  pending — PS7 by design). No redirect, no `return` mutation.

## Dev-shop webhook setup used (not committed)

`stripe login` lands in **OXID eSales AG Plattform Sandbox** (`acct_1TBvVe…`), and that sandbox *is* the platform
controlling Violet Seesaw (`GET /v1/accounts/acct_1TuDSd…` succeeds there). So the module's "platform key" should be a
secret key of that sandbox, not of OXID eSales AG (`acct_1OyE4t…`) — with it, the fixed "Create webhooks" button
registers a Connect endpoint. Until then: `stripe listen --project-name violet-seesaw --forward-to <URL>
--forward-connect-to <URL> --events <catalog>`; its `whsec_…` is stored in `sStripeWebhookEndpointSecret` (replacing
the dead Dashboard endpoint's secret). The listener runs only while the process lives.

## Follow-ups (not in this story)

- The sweep is provider-agnostic (`findStaleNotFinished` selects every provider's draft/not_finished/pending rows);
  Stripe's webhook cancelling Mollie/PayPal attempts is pre-existing and untouched.
- `sessionOf()` and `inspect()` retrieve the same session twice per stale contract; one retrieval would halve the sweep.
