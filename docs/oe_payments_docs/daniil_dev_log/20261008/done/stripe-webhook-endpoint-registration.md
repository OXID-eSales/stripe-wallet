# Webhook endpoint registration works for the shop's own account (DONE 2026-10-08)

**Branch:** `b-7.4.x-stripe-webhook-endpoint` · **Ask:** "create a branch in stripe and fix it — the webhook endpoint should be working!"

## What was wrong

`ModuleConfiguration::stripeCreateWebhookEndpoint()` ("Create webhooks") **required** a platform key and registered a
Connect webhook on whatever account that key belonged to. It never tried the shop's own key, so for every ordinary
Stripe account the button was unusable without a key the merchant does not have; and for a connected account whose
platform is *not* the configured one, the Connect endpoint registered fine on an unrelated account and never received
an event — the module stored its secret and reported success. That is the dev shop since April: endpoint
`we_1TuDVD…` on OXID eSales AG, zero deliveries, every GRAPH-QL webhook proof for Stripe had to be replayed by hand.

## What changed

| Piece | Job |
|---|---|
| `Service\WebhookEndpointRegistrar::registerForShop(accountKey, platformKey, url, existingId, description)` (+ interface) | 1. a plain endpoint with the **shop's own key** (every ordinary account); 2. if Stripe refuses because the account is a connected account (or an endpoint id from the old flow is unknown to the account): a **Connect** webhook on the platform — only after `platformControlsAccount()` confirmed the platform really controls the shop's account; 3. otherwise a `WebhookRegistrationException` that names both accounts and tells the merchant what to do in the Dashboard (URL + the module's events + "paste the signing secret"). Unrelated Stripe errors are rethrown unchanged |
| `Adapter\StripeWebhookEndpointApi(Interface)` | `accountId(key)` (`GET /v1/account`), `platformControlsAccount(platformKey, accountId)` (`GET /v1/accounts/{id}`, false on refusal) |
| `Service\Exception\WebhookRegistrationException` | carries Stripe's error code; `isConnectedAccountRefusal()`, `isMissingResource()`; the two new refusals with the Dashboard instruction |
| `Controller\Admin\ModuleConfiguration::stripeCreateWebhookEndpoint()` | needs the shop's secret key (`STRIPE_WEBHOOK_API_KEY_MISSING`), the platform key is optional; calls `registerForShop()`; the exception message reaches the admin as before |
| EN / DE help texts of `sStripeTestKey` / `sStripeLiveKey` | "only when your account is a connected account AND you own its platform; leave empty otherwise" |

## Red → green

- `Unit\Stripe\Service\WebhookEndpointRegistrarForShopTest` (6): ordinary account → own key, no platform lookups;
  connected account + controlling platform → Connect webhook on the platform; connected account + unrelated platform
  → refusal naming both accounts with URL, events and "Dashboard"; connected account without platform key → Dashboard
  instruction; unrelated Stripe error not retried; an endpoint id from the old platform flow is updated on the platform.
- `ModuleConfigurationWebhookActionTest` adapted: success with own key + platform key, works without a platform key,
  refuses without the shop's key, existing id passed through, error path unchanged.

## Gates

Unit (standalone) **1638** green · PHPStan level max No errors · phpcs clean · phpmd clean.

## On the dev shop (what the button now answers)

> The platform key belongs to Stripe account acct_1OyE4t…, which does not control your account acct_1TuDSd…: a Connect
> webhook registered there would never receive this shop's events. Paste the secret key of the platform that onboarded
> your account, or create the endpoint in your account's Dashboard (Developers → Webhooks → Add endpoint) with the URL
> https://daniil.oxiddev.de/index.php?cl=StripeWebhookController and the events payment_intent.succeeded, …,
> payment_intent.amount_capturable_updated, then paste its signing secret into "Webhook Endpoint Secret".

Verified against Stripe: the shop key may neither create nor list endpoints ("not permitted … on a connected
account", "application does not have the required permissions"), and the OXID eSales AG key cannot see the shop account
("Only Stripe Connect platforms can work with other accounts"). The module cannot change that; the merchant can, in
one minute, in the Dashboard. For a developer, `stripe login` into the Violet Seesaw account plus `stripe listen
--forward-to <webhook URL>` (its `whsec_…` into the setting) does the same without a Dashboard endpoint.
