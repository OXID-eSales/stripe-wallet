# GRAPH-QL / PS7 — The order ends with the webhook, not with the redirect (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-stripe-provider-story.md](../sprints/GRAPH-QL-stripe-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## Ask (live, after PS6)

> "we need to end the order with a webhook, not with a redirect to the shop"

The shopper paid order 794 through `bin/graph-ql-cli-test.sh pay`; the browser landed on the client's return URL
(a 404 in the shop, by design — the URL belongs to the headless client) and the order stayed `NOT_FINISHED` with the
contract `pending`. Two reasons, one of them a product gap.

## What was found

| Finding | Kind |
|---|---|
| **Stripe never called this shop.** The module's key is a *Standard connected account* (`acct_1TuDSd…`): such accounts may not create webhook endpoints by API ("You are not permitted to configure webhook endpoints on a connected account"). The admin "Create webhooks" button registers a *Connect* webhook on the account of the configured platform key — but that key's account is not a Connect platform of this shop's account (`platform_account_required`), so the two Connect endpoints there (one from April, one created and deleted again today) can never receive this shop's events. `oe_payments_webhooklogs` had no Stripe row ever; the 400s in Apache were the Integration suite's fake webhooks. | environment |
| **Manual capture is invisible to the PS3 webhook commit.** `sStripeCaptureMode = manual`: the Checkout Session completes with `payment_status = unpaid` and the intent is `requires_capture`. `checkout.session.completed` skips ("not paid"), `payment_intent.succeeded` only comes after the merchant captures. The authorization arrives as `payment_intent.amount_capturable_updated`, which the module did not subscribe to (noted as a follow-up in the PS3 report). A headless shopper who never returns leaves the money reserved and the order `NOT_FINISHED`. | **product gap** |
| The shop's container is compiled into `var/cache/container/` (sCompileDir), not `source/tmp/` — a `services.yaml` change is live only after that directory is cleared. | dev note |

## What changed

| Piece | Job |
|---|---|
| `Webhook\Handler\PaymentIntentAmountCapturableUpdatedWebhookHandler` (new, tagged `stripe.webhook_handler`) | `payment_intent.amount_capturable_updated` with `status = requires_capture`: find the contract (by intent id, else by metadata `contract_id` — a Checkout Session contract still carries the session id) and commit it through payment-base's `ContractCommitService` **with `requiresCapture`**: order created, not marked paid, capture fulfils later as in the Twig flow. Already committed (the return leg was first) ⇒ skipped; refused commit ⇒ webhook failure so Stripe retries; other statuses ⇒ skipped |
| `AbstractStripeWebhookHandler::commitOpenContract()` | optional `bool $requiresCapture = false` → `PaymentConfirmation::$requiresCapture` (payment-base's `ContractCommitmentHandler` reads it from the context) |
| `WebhookEventCatalog` | `+ payment_intent.amount_capturable_updated` — the registrar subscribes new endpoints to it |
| `bin/graph-ql-cli-test.sh` / `.md` | return URL defaults to the shop's start page (no 404 for a hand test); `pay` explains that a registered webhook completes the order by itself and `return` then only reports; docs: webhook section (connected account, Dashboard endpoint, CLI forwarding, manual capture) |

## Red → green

`Unit\Stripe\Webhook\Handler\PaymentIntentAmountCapturableUpdatedCommitTest` (6): supports its event only; an
authorized intent commits the pending contract found through metadata with `requiresCapture = true`, amount from
`amount_capturable`, source `webhook`, no fulfilment call; not awaiting capture ⇒ skipped; already committed ⇒ skipped
with the contract id; refused ⇒ failure naming the reason; no contract ⇒ skipped. `WebhookEventCatalogTest` + 1.

## Proof on the real order

Order 794 (contract `7bbe210f…`, intent `pi_3UNXEl…` `requires_capture`): Stripe's own event
`evt_3UNXElDy2vmJsUOt0T9Ohzdd` retrieved by API and replayed to
`https://daniil.oxiddev.de/index.php?cl=StripeWebhookController` with a signature over the module's secret, exactly
as Stripe sends it:

```
HTTP 200 {"received":true,"action":"contract_authorized"}
contract  committed
order 794 OXTRANSSTATUS OK, OXTRANSID pi_3UNXElDy2vmJsUOt0sv8gfXX, OXPAID empty (manual capture: not paid yet)
oe_payments_webhooklogs: payment_intent.amount_capturable_updated processed, contract 7bbe210f…
```

No browser return, no `stripeCheckoutReturn`. The admin's Stripe tab now offers Capture for 794.

## Getting Stripe to call this shop (dev environment)

The shop's account is a Standard connected account, so an endpoint must be created where the account owner can:
**Stripe Dashboard → Developers → Webhooks → Add endpoint**, URL `https://daniil.oxiddev.de/index.php?cl=StripeWebhookController`,
the seven events of `WebhookEventCatalog`, and the endpoint's signing secret into the module setting
`sStripeWebhookEndpointSecret` (admin: Stripe → Webhooks). Alternative for a developer: `stripe listen --forward-to <that URL>`
after `stripe login` into the shop's account, and its `whsec_…` into the same setting — `stripe listen --api-key` with
the module's key does **not** work (`oauth_not_supported`: the key is an OAuth access token of the connected account). The admin button's Connect
registration only applies when the platform key really is this account's platform.

## Gates

- Unit (standalone) **1631** green (7 new) · PHPStan level max No errors · phpcs clean · phpmd clean
- CI: see status (Stripe workflows pinned to payment-base `b-7.4.x-GRAPH-QL`, `f164517`, TEMPORARY)
