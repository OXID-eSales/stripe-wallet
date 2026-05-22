# Sprint 109 — Completion report

**Module:** `extensions/stripe`
**Status:** ✅ All code complete, TDD discipline observed, pre-commit green.
**Open user actions** (not Claude-doable, require live Stripe account): C1 OAuth scope spike + Step 9 manual smoke test. See §6.

**Planning doc:** `done/sprint-109-webhook-auto-registration-on-connect-onboarding.md`
**Feasibility report:** `reports/04-feasibility-webhook-auto-registration-during-connect-onboarding.md`

## TDD trace

| Step | Test | At HEAD before fix | After fix |
|---|---|---|---|
| 1 | `WebhookEndpointRegistrarTest` (5 tests) | RED — `Class WebhookEndpointRegistrar not found` | GREEN |
| 2.5 | `ModuleConfigurationServiceWebhookUrlTest` (3 tests) | RED — `$shopAdapter must not be accessed before initialization` (proves prod went through wrong seam) | GREEN |
| 4 | `StripeConnectWebhookRegistrationTest` (4 tests) | RED — `initializeCollaborators not accessible` | GREEN |
| C3 fallback | `ModuleConfigurationServiceIsConfiguredTest::testGetWebhookSecret…` (3 tests) | written before per-mode prod code | GREEN |

15 new unit tests, **all written RED first**.

## Production changes

### New files (8)

```
src/Stripe/Service/WebhookEndpointRegistrarInterface.php           — narrow port (1 method)
src/Stripe/Service/WebhookEndpointRegistrar.php                    — impl, 60 LOC, no else
src/Stripe/Service/WebhookEndpointRegistrationResult.php           — readonly DTO
src/Stripe/Service/WebhookEventCatalog.php                         — single source of truth for events
src/Stripe/Service/Exception/WebhookRegistrationException.php      — domain exception
src/Stripe/Adapter/StripeWebhookEndpointApiInterface.php           — SDK boundary port
src/Stripe/Adapter/StripeWebhookEndpointApi.php                    — SDK-backed adapter, wraps StripeClient
```

### Edited files (8)

```
src/Stripe/Controller/Admin/StripeConnect.php          — testable seam + soft-fail webhook registration (+90 LOC, −15 LOC, decomposed)
src/Stripe/Service/ModuleConfigurationService.php      — getWebhookUrl uses SSL form; getWebhookSecret per-mode with legacy fallback
services.yaml                                          — alias WebhookEndpointRegistrarInterface, alias StripeWebhookEndpointApiInterface, exclude DTO + Exception/ from sweep
metadata.php                                           — 4 new per-mode settings (IdTest/IdLive/SecretTest/SecretLive)
views/twig/admin/stripe_connect.html.twig              — badge: "Webhook auto-registered ✓" / "Manual paste required"
views/admin_twig/{en,de}/stripe_lang.php               — 2 new translation keys
```

### Test files (3)

```
tests/Unit/Stripe/Service/WebhookEndpointRegistrarTest.php                       — 5 tests
tests/Unit/Stripe/Service/ModuleConfigurationServiceWebhookUrlTest.php           — 3 tests
tests/Unit/Stripe/Controller/Admin/StripeConnectWebhookRegistrationTest.php      — 4 tests
tests/Unit/Stripe/Service/ModuleConfigurationServiceIsConfiguredTest.php         — 3 added tests for per-mode secret fallback
```

## Principles applied

### TDD-first
All 15 new tests were written **before** their production counterparts, and observed RED before any production code was added. The RED→GREEN transitions are visible in the trace table above.

### SOLID

- **S (SRP):** `WebhookEndpointRegistrar` only orchestrates the create-or-update decision (5 lines of logic in `register()` after the HTTPS guard). `StripeWebhookEndpointApi` only translates SDK calls to our DTO. `WebhookEventCatalog` only lists events. `WebhookEndpointRegistrationResult` only carries data. Each class has one reason to change.
- **O (OCP):** The event catalog can be extended by editing one private constant. Adding a new event type is one line. The registrar's signature is stable.
- **L (LSP):** `WebhookEndpointRegistrar implements WebhookEndpointRegistrarInterface` strictly. `StripeWebhookEndpointApi implements StripeWebhookEndpointApiInterface`. Test mocks substitute cleanly.
- **I (ISP):** Both new interfaces are 1-2 methods. `StripeWebhookEndpointApiInterface` exposes only `create()` and `update()` — no `delete`, `list`, or `retrieve` until a caller needs them.
- **D (DIP):** Controller depends on `WebhookEndpointRegistrarInterface`, not `WebhookEndpointRegistrar`. Registrar depends on `StripeWebhookEndpointApiInterface`, not the Stripe SDK. SDK types (`StripeClient`, `ApiErrorException`) only appear in `StripeWebhookEndpointApi`. No `\Stripe\…` types leak into the rest of the codebase.

### DRY

- Webhook URL: one builder, `ModuleConfigurationService::getWebhookUrl()`. Both the controller and any future caller use the same string.
- Event list: one catalog, `WebhookEventCatalog::all()`. Registrar consumes it. If we add `checkout.session.completed` next sprint, one edit.
- Float / DateTime / OXID-config parsing: deleted `parseOptionalFloat` (unused after Sprint 108) — already DRY'd in payment-base; nothing duplicated this sprint.

### LI (Liskov + Library Independence)
- LSP: subclassing the registrar (test fakes) preserves the interface contract — no narrowed returns, no widened exception types.
- Library Independence: the Stripe SDK is encapsulated behind `StripeWebhookEndpointApi`. The rest of the module talks to `StripeWebhookEndpointApiInterface` + our own DTO + our own exception. Upgrading the SDK from v19 to v20 would touch one file.

### DI
- Constructor injection only in the registrar, the API adapter, and the event catalog.
- The OXID admin controller cannot use constructor DI (framework constraint), so it uses `ContainerFactory::getInstance()->getContainer()` to resolve collaborators in `__construct()` and routes them through a protected `initializeCollaborators()` seam. Test subclasses bypass `parent::__construct()` and call the seam directly with mocks.

### Clean Code
- Methods under 25 lines: `register()` is 17 lines, `tryRegisterWebhook()` is 22 lines, `create()` and `update()` on the API adapter are ~18 lines each.
- No `else`. Early returns and guard clauses.
- One genuine "WHY" comment in `tryRegisterWebhook`: documents the save-id-before-secret invariant (so a mid-save crash leaves a recoverable state).
- One genuine "WHY" comment in `WebhookEndpointRegistrationResult`: explains why `secret` is nullable.
- No abbreviations: `accessToken`, `endpointId`, `webhookUrl`, `existingEndpointId`.
- DTO is readonly with public properties. No getters/setters cluttering the surface.

## Verification

| Check | Result |
|---|---|
| `./bin/pre-commit-check.sh --full` | **✓ ALL CHECKS PASSED — COMMITABLE** |
| PHPUnit (Unit + Integration, full) | **1017 tests pass** (was 1002 — +15 new) |
| PHPCS (PSR-12) | clean |
| PHPStan (level max, on changed files + new files) | `[OK] No errors` |
| PHPMD (strict, against baseline + new files) | exit 0, no new violations |

Manual phpstan/phpcs/phpmd runs over the 7 new production files specifically: all clean.

## What's committable now

```
 M metadata.php
 M services.yaml
 M src/Stripe/Controller/Admin/StripeConnect.php
 M src/Stripe/Service/ModuleConfigurationService.php
 M tests/Unit/Stripe/Service/ModuleConfigurationServiceIsConfiguredTest.php
 M views/admin_twig/de/stripe_lang.php
 M views/admin_twig/en/stripe_lang.php
 M views/twig/admin/stripe_connect.html.twig
?? src/Stripe/Adapter/StripeWebhookEndpointApi.php
?? src/Stripe/Adapter/StripeWebhookEndpointApiInterface.php
?? src/Stripe/Service/Exception/WebhookRegistrationException.php
?? src/Stripe/Service/WebhookEndpointRegistrar.php
?? src/Stripe/Service/WebhookEndpointRegistrarInterface.php
?? src/Stripe/Service/WebhookEndpointRegistrationResult.php
?? src/Stripe/Service/WebhookEventCatalog.php
?? tests/Unit/Stripe/Controller/Admin/StripeConnectWebhookRegistrationTest.php
?? tests/Unit/Stripe/Service/ModuleConfigurationServiceWebhookUrlTest.php
?? tests/Unit/Stripe/Service/WebhookEndpointRegistrarTest.php
```

8 new files + 8 edited files. ~360 LOC production + ~330 LOC tests.

## Open items — require live Stripe account (user action)

### 1. C1 spike — verify OAuth scope (gating before deploy)

The sprint plan called for a 5-line spike to confirm the access_token issued by the OXID middleware includes `rw_webhook_endpoints` scope. **I could not run this without a real test token.** The recommended one-liner from inside the docker `php` container:

```bash
docker compose exec -T php php -r '
require "/var/www/vendor/autoload.php";
\Stripe\Stripe::setApiKey(getenv("ACCESS_TOKEN"));
try {
  $e = \Stripe\WebhookEndpoint::create([
    "url" => "https://example.com/sprint-109-spike",
    "enabled_events" => ["payment_intent.succeeded"],
  ]);
  echo "OK — created " . $e->id . " with secret " . substr($e->secret,0,12) . "...\n";
  \Stripe\WebhookEndpoint::delete($e->id);
  echo "Deleted OK.\n";
} catch (\Stripe\Exception\ApiErrorException $e) {
  echo "FAIL — " . $e->getStripeCode() . ": " . $e->getMessage() . "\n";
}
'
```

Set `ACCESS_TOKEN` to the test-mode `access_token` from a recent Connect onboarding. If 200 + `whsec_…` → ship. If 403 → the middleware team must widen the OAuth scope before this sprint's work is useful in production.

### 2. Step 9 — manual smoke test (release validation)

Once the auth fix from report `03-` (PAT regeneration for stripe-wallet CI) lands and the C1 spike returns OK:

1. Re-run Connect onboarding in test mode in a real OXID admin.
2. Confirm: the new badge shows "Webhook endpoint automatically registered with Stripe."
3. Open Stripe Dashboard → Test mode → Developers → Webhooks. The endpoint with URL `…/index.php?cl=StripeWebhookController` and event list (`payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `charge.captured`, `charge.refunded`, `checkout.session.expired`) should be visible.
4. Make a small test purchase. `payment_intent.succeeded` webhook should arrive and the contract should progress.
5. Re-click Connect once more. Confirm: NO new endpoint is created in the Dashboard; the existing one is updated in place (description / events refresh).

## Risk register — closed and open

| Risk | Status |
|---|---|
| R1 OAuth scope missing | **Open** — C1 spike pending. Code soft-fails cleanly if scope is missing. |
| R2 Save-order race (id-before-secret) | **Closed** — implemented and documented inline. |
| R3 Stripe rate limits | **Closed** — surfaces as soft-fail. |
| R4 Non-HTTPS dev shops | **Closed** — `WebhookEndpointRegistrar::assertHttps()` rejects with `WebhookRegistrationException::nonHttpsUrl` before any SDK call. Manual paste field continues to work. |
| R5 Migration of existing pasted secrets | **Closed** — `getWebhookSecret()` falls back to legacy `sStripeWebhookEndpointSecret` when per-mode is empty. Three tests pin this. |
| R6 Orphan endpoint on uninstall | **Deferred** — out of scope per §10 of plan. Stripe Dashboard remains source of truth. |

## Out-of-scope items (deferred per plan §10)

- Webhook deletion on module uninstall — admin can delete from Stripe Dashboard.
- Webhook health monitoring — Stripe Dashboard provides this; not mirrored in OXID.
- Direct Connect OAuth (removing dependency on OXID middleware) — separate, larger sprint.

## Branch state

- Branch: `b-7.4.x` (extensions/stripe)
- ~360 LOC production + ~330 LOC tests across 16 files.
- No git operations performed by this sprint — user owns commit/push.
- Existing 9 `StripeConnectTest` tests untouched and still passing — backwards-compat preserved.
