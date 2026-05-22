# Previous-day review — 2026-05-22

Short recap of the last working day (full details: [`../../20260522/status.md`](../../20260522/status.md)).

## Three threads

**1. Shop unstick.** Local shop returned `offline.html` on every request.
Peeled back three layered causes: dormant Sprint 109 `Safe\array_replace_recursive`
redeclare fatal (harmless after PHP-FPM restart); OXID EE 7-day grace expired
(`blShopStopped = 1`, cleared via DB); `generated_services.yaml` pruned to the
Stripe import only, leaving `TemplateEngineInterface` bound to the null-object
implementation — `composer install` regenerated the imports.

**2. Sprint 109 verdict + Sprint 110/111.** Live-probed Stripe API and
confirmed R1 from the feasibility report: connected-account `access_token`s
cannot manage webhooks (`"not permitted on connected account"`) with or
without `connect: true`. Sprint 109's auto-registration was unfixable as
written. Ported the legacy module's pattern:
- Two-key model per env: `sStripeTest/LiveToken` (OAuth, for charges) +
  `sStripeTest/LiveKey` (platform secret, for webhooks).
- "Create webhooks" button on `module_config` admin form (Sprint 110), moved
  off the OAuth-return view via OXID 7.4 admin-Twig parallel-template extension
  under `views/twig/extensions/themes/admin_twig/`.
- Autofill of URL + secret on success, persisted via `ModuleSettingBridge`;
  URL field made `readonly` (Sprint 111).
- "Clear all webhooks" button with URL-filter (Sprint 111b) so it doesn't
  wipe other shops' endpoints sharing the same Stripe key.

Sprint 109's auto-registration code was deleted; its service stack
(`StripeWebhookEndpointApi`, `WebhookEndpointRegistrar`, DTO, exception,
catalog) was reused 1:1 — only the caller changed.

**3. Critical review + test cleanup.** 10 audit items applied (stale
docblocks, raw-string errors → translated lang keys, single-source-of-truth
for webhook description via `getModuleDescription()`, 7 dead lang keys
removed across `translations/` and `views/admin_twig/`). Replaced fake
`StripeConnectTest` (anonymous-class subclass re-implemented the method
under test — 8 assertions exercised nothing) with a real 5-scenario test.
Fixed 30 `Context::isAdmin(): TypeError` integration errors caused by three
unit-test files leaking `Registry::set` mocks without `tearDown`. Fixed 3
pre-existing PHPStan level-max errors (per the never-suppress rule).

## Quality gates (end of day)

| Tool | Status |
|---|---|
| PHPCS | ✓ |
| PHPStan level max | ✓ (3 pre-existing errors fixed; baseline unchanged) |
| PHPMD strict | ✓ (4 baselined items unchanged) |
| Unit (isolated) | 881 (+12 net vs. start of day) |
| Integration (isolated) | 157 |
| `./bin/pre-commit-check.sh --full` | ✓ ALL CHECKS PASSED — COMMITABLE |

## Open items carried forward

- Live browser smoke for Sprint 110/111 UI: paste real Stripe platform test
  key → click "Create webhooks" → confirm badge flip + Stripe Dashboard
  shows endpoint with correct event list. CLI end-to-end probe already
  succeeded.
- All Sprint 110/111 + cleanup changes still uncommitted on
  `b-7.4.x-webhook-STRP-144`. User owns commits.
- Sprint 109's C1 OAuth-scope spike retroactively answered by today's live
  probe.