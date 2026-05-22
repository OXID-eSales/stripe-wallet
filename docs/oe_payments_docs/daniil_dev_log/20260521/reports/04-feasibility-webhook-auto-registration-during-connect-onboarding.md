# Feasibility — auto-register Stripe webhook during Connect onboarding

**Question:** can we eliminate the manual "copy webhook secret from Dashboard → paste into module config" step by registering the webhook endpoint programmatically during the Connect onboarding callback?

**Short answer:** Yes, technically feasible and small. The Stripe SDK supports it, we already have the credentials at the right moment, and the natural insertion point is a 10-line addition. There are three real constraints to confirm before committing — listed at the end.

## Current onboarding flow

1. Admin clicks "Connect with Stripe" in the OXID admin (`ModuleConfiguration` controller, lines 91-102) → redirected to OXID middleware:
   - test: `https://stripe-middleware-test.oxid-esales.com/stripe-connect`
   - live: `https://osm.oxid-esales.com/stripe-connect`
2. Middleware handles the OAuth dance with Stripe (the module does not call `Stripe\Account::create()` or `AccountLink::create()` — that's middleware-owned).
3. Stripe → middleware → shop redirect back to `?cl=StripeConnect&fnc=stripeFinishOnBoarding` with three query params:
   - `access_token` — the connected account's secret API key
   - `publishable_key` — the publishable key
   - `shop_param` — `test` or `live`
4. `StripeConnect::stripeFinishOnBoarding()` (`src/Stripe/Controller/Admin/StripeConnect.php:39-65`) saves the two keys to module settings (`sStripeLiveToken` / `sStripeTestToken` / `sStripeLivePk` / `sStripeTestPk`) and renders a success page.
5. **The webhook secret (`sStripeWebhookEndpointSecret`) is still empty at this point** — admin must visit Stripe Dashboard → Developers → Webhooks → "Add endpoint", paste the URL, copy the signing secret, paste it into the OXID admin field. This is the gap.

The webhook URL the admin would paste is deterministic — `ModuleConfigurationService::getWebhookUrl()` already builds it: `rtrim($shopUrl, '/') . '/index.php?cl=StripeWebhookController'`. So we know it before onboarding finishes.

## What Stripe's API supports

`stripe/stripe-php ^19.3` is in `composer.json`. `\Stripe\WebhookEndpoint::create()` (https://docs.stripe.com/api/webhook_endpoints/create) does exactly what we need:

```php
$endpoint = \Stripe\WebhookEndpoint::create([
    'url'            => $this->moduleConfigurationService->getWebhookUrl(),
    'enabled_events' => [...],
    'description'    => 'OXID eShop ' . $shopUrl . ' (' . $mode . ')',
]);
// $endpoint->secret    — the whsec_… signing secret, returned ONLY on creation
// $endpoint->id        — we_… endpoint identifier, needed for later update/delete
```

Two important Stripe-API behaviours that fit our flow:

- **The signing secret is returned only on creation.** You cannot retrieve it later via `WebhookEndpoint::retrieve()`. So the create-and-save-once pattern is the only viable shape — there's no need to design a "fetch existing secret" path because Stripe doesn't expose one. If we lose the secret, we delete the endpoint and recreate.
- **Each create is a new endpoint.** Re-running onboarding would create duplicate endpoints unless we either delete the old one or call `WebhookEndpoint::update()` on the stored ID.

The codebase already creates other Stripe resources programmatically in this style: `Checkout\Session::create()`, `PaymentIntent::create()/capture()`, `Refund::create()`, `Customer::create()`. Adding `WebhookEndpoint::create()` is a natural extension of an existing pattern, not a new direction.

## The events to subscribe to

Pulled from `src/Stripe/WebhookHandler/*` and `src/Stripe/Adapter/StripeWebhookEvent.php`:

| Event | Handler |
|---|---|
| `payment_intent.succeeded` | `PaymentIntentSucceededHandler` |
| `payment_intent.payment_failed` | `WebhookContractFulfillmentHandler::handlePaymentIntentPaymentFailed()` |
| `payment_intent.canceled` | `WebhookContractFulfillmentHandler::handlePaymentIntentCanceled()` |
| `charge.captured` | `WebhookContractFulfillmentHandler::handleChargeCaptured()` |
| `charge.refunded` | `ChargeRefundedHandler` |
| `checkout.session.expired` | `WebhookContractFulfillmentHandler::handleCheckoutSessionExpired()` |

A `checkout.session.completed` subscription would also be sensible (currently the success path is via the return URL; webhook is a redundancy). Worth confirming with the team. Keeping the list narrow (rather than `*`) means fewer noisy events and a smaller blast radius if Stripe ever adds a flood-prone event type.

## Insertion point — surgical

`src/Stripe/Controller/Admin/StripeConnect.php`, after line 58 (the four `moduleSettingService->save(...)` calls), before the view-data block at line 61. The controller already has the access token in `$sAccessToken` — we can pass it to a new service that:

1. Configures a `StripeClient` with `$sAccessToken`.
2. Calls `WebhookEndpoint::create()` with the deterministic URL and the event list above.
3. On success: saves `$endpoint->secret` to `sStripeWebhookEndpointSecret` and `$endpoint->id` to a new setting `sStripeWebhookEndpointId` (needed for idempotency on re-onboarding).
4. On any Stripe API failure: logs at warning level and **does not block onboarding** — the admin can still paste manually. Failure here must be soft.

Sketch (illustrative, not the implementation):

```php
// after the four ->save() calls in stripeFinishOnBoarding()
try {
    $this->webhookEndpointRegistrar->registerOrUpdate($sAccessToken, $sMode);
    $blWebhookAutoRegistered = true;
} catch (\Stripe\Exception\ApiErrorException $e) {
    $this->logger->warning('Webhook auto-registration failed; admin must paste manually', [
        'reason'   => $e->getMessage(),
        'stripe_code' => $e->getStripeCode(),
    ]);
    $blWebhookAutoRegistered = false;
}
```

The registrar is one small class with one public method. SOLID: separate responsibility from the controller (which today is glue), testable as a unit by mocking `WebhookEndpoint::create()`.

## Idempotency on re-onboarding

If the admin re-runs Connect (e.g. rotated keys, accidentally re-clicked, switching between test/live modes), we must not pile up duplicate endpoints in the Stripe Dashboard. Two reasonable strategies, pick one:

- **A. Store endpoint ID, update on re-run.** Save `sStripeWebhookEndpointId` (per mode: live and test) alongside the secret. On re-onboarding, if the ID exists, call `WebhookEndpoint::update($id, ['enabled_events' => …])` to refresh the event list; only create if no ID is stored. Cheaper, no Stripe call to enumerate endpoints. The secret stays the same across updates.
- **B. Create unconditionally, delete-by-URL afterward.** Simpler conceptually, but requires listing existing endpoints (`WebhookEndpoint::all()`), finding the duplicate, and deleting — that's three API calls vs. one. Also a new endpoint means a new secret, which means re-saving and any in-flight webhooks signed by the old secret fail.

**Lean: A.** Less surface area, fewer round-trips, preserves the existing secret on re-run.

## Three real constraints — verify before committing

These are blockers if any one of them turns out wrong.

1. **OAuth scope of the access token.** Stripe's standard OAuth Connect tokens have scopes; creating webhook endpoints requires `rw_webhook_endpoints` (or that the connected account allows it on a Standard account API key, which it typically does — the access_token IS the connected account's secret key for Standard). The OXID middleware controls what OAuth scopes are requested. **Action:** confirm with the middleware team that the granted scopes include webhook write. If not, they need to extend the OAuth scope request.

2. **Shop URL reachability and protocol.** `WebhookEndpoint::create()` requires `https://` (Stripe accepts `http://` only for very specific localhost cases in test mode, and unreliably). Shops on `localhost.local`, internal staging hosts, or behind firewalls will get a 400 from Stripe. **Action:** the registrar must check `parse_url($webhookUrl)['scheme'] === 'https'` and skip-with-warning otherwise — this keeps local dev working without forcing every developer to expose a tunnel. The manual paste field stays as the fallback.

3. **Test vs. live keys produce different endpoints.** Stripe test mode and live mode are entirely separate worlds. A webhook endpoint created with a test key is invisible to live, and vice versa. The module already separates `sStripeLiveToken` / `sStripeTestToken`. The new setting `sStripeWebhookEndpointId` likewise must be per-mode: `sStripeWebhookEndpointId_live` and `sStripeWebhookEndpointId_test` (or similar). Same for the secret — though I notice the existing schema has only one `sStripeWebhookEndpointSecret`, not split per mode. That's a small pre-existing inconsistency: in live + test split mode, the *secret* should also be per-mode because Stripe will sign with different keys. **Action:** check whether the current single-secret setting works because admins re-paste when switching modes; if so, splitting it is a related improvement worth bundling.

## Effort and shape

- ~1 new service class (`WebhookEndpointRegistrar`) — 60-80 LOC including idempotency and per-mode keying.
- ~10-line addition to `StripeConnect::stripeFinishOnBoarding()` with soft-fail handling.
- 1 new metadata.php setting per mode (`sStripeWebhookEndpointIdLive`, `sStripeWebhookEndpointIdTest`); possibly also split `sStripeWebhookEndpointSecret` per mode.
- View-data change: success template should show "Webhook auto-registered ✓" or "Webhook auto-registration skipped — paste signing secret below" depending on the flag.
- Tests: unit on the registrar with a mocked `StripeClient`; integration test on `stripeFinishOnBoarding()` that asserts both happy path and soft-fail path.

Estimated 1-2 days TDD-first including PHPCS/PHPStan/PHPMD pass. No DB schema change. No new dependencies.

## Recommendation

Worth doing. It removes a known friction point in the admin onboarding flow (the "copy this string from one tab to another" step is exactly the kind of manual operation users get wrong), and the implementation is small and self-contained. Confirm the three constraints above first — particularly the OAuth scope — because the OXID middleware change (if needed) is on someone else's plate and would gate this work.

Suggested next step: write a small spike that, given a fresh test-mode access token, manually creates and immediately deletes a webhook endpoint against `https://example.com/webhook` (a deliberately wrong URL — Stripe still creates it, you just can't validate delivery). If `WebhookEndpoint::create()` returns 200 with a `whsec_…` secret, scope is fine and we proceed. If 403 / "insufficient permissions", the middleware needs to widen scopes first.

---

**Cross-reference:** the existing webhook secret field (`metadata.php:92`, `sStripeWebhookEndpointSecret`) is consumed by `ModuleConfigurationService::getWebhookSecret()` and used in `StripeAdapter::constructEvent()` for signature verification. None of the consumers need to change — auto-registration just populates the same field, replacing manual paste with a Stripe API call.
