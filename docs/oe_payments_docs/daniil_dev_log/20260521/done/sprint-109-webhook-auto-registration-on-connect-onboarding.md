# Sprint 109 — Auto-register webhook endpoint during Stripe Connect onboarding

**Module:** `extensions/stripe`
**Mode:** TDD-first. One feature branch off `b-7.4.x`, one PR. Atomic-ish — split into 3 small commits if needed for review (registrar + tests, controller integration, template/view-data) but the whole sprint ships together.
**Trigger:** Feasibility report `reports/04-feasibility-webhook-auto-registration-during-connect-onboarding.md`.
**Wait for approval before starting. Confirm the three constraints in §2 first.**

## 1. Why

Today, after the Stripe Connect onboarding callback (`StripeConnect::stripeFinishOnBoarding()`) saves the API keys, the admin still has to:

1. Open Stripe Dashboard → Developers → Webhooks → **Add endpoint**
2. Paste the URL `…/index.php?cl=StripeWebhookController`
3. Pick the event types
4. Copy the signing secret
5. Switch back to OXID admin → Module config → paste into `sStripeWebhookEndpointSecret`
6. Save

Every one of those steps is a chance for human error: wrong URL, wrong events, wrong mode (test vs live), forgot a step, pasted into wrong field. We have the Stripe API token at the exact moment after onboarding finishes — we can do all five steps in one API call and store the result. The admin sees "Webhook configured ✓" instead of a half-set-up module.

The Stripe SDK (`stripe/stripe-php ^19.3`) supports `\Stripe\WebhookEndpoint::create()`. The codebase already creates `Checkout\Session`, `PaymentIntent`, `Refund`, `Customer` programmatically — adding `WebhookEndpoint` is the same idiom.

## 2. Pre-flight — verify before writing any code

Three constraints from the feasibility report. **Do not start Step 3 until all three are confirmed.** If any one fails, the sprint either pauses (waiting on middleware team) or its scope shrinks.

| # | Constraint | How to verify | If false |
|---|---|---|---|
| C1 | OAuth scope of the access token includes webhook write (`rw_webhook_endpoints` for Standard accounts, or unrestricted secret key access for Custom) | Spike: take a current test-mode `access_token` from a freshly-onboarded shop, run `WebhookEndpoint::create()` with `url=https://example.com/sprint-109-spike`, expect HTTP 200 with `whsec_…`. Immediately delete. | Pause sprint; raise with middleware team to widen OAuth scope. |
| C2 | Shop URL is HTTPS and publicly reachable from Stripe's servers | Read `Registry::getConfig()->getSslShopUrl()` and assert `parse_url()['scheme'] === 'https'`. (Runtime check, not pre-flight — affects fallback path, not feasibility.) | Soft-fall back to manual paste; log warning. Implementation must handle this branch, not pretend it doesn't exist. |
| C3 | Per-mode signing secret storage is supported by the schema | Read `metadata.php`; check whether `sStripeWebhookEndpointSecret` is single-valued or already split per mode | If single-valued: bundle a split (`sStripeWebhookEndpointSecretLive` / `sStripeWebhookEndpointSecretTest`) into this sprint — it's a related correctness fix. |

The C1 spike is a 5-line throwaway script run from the docker `php` container with composer's autoload — it does not need to live in the repo.

## 3. Goals

- **G1.** After a successful Connect onboarding, `sStripeWebhookEndpointSecret` (or its per-mode equivalent — see C3) is populated automatically, and the matching webhook endpoint exists in the Stripe Dashboard for the connected account.
- **G2.** Re-running onboarding does **not** create a duplicate webhook endpoint. It updates the existing one in place (per stored endpoint ID), preserving the signing secret so in-flight webhook deliveries continue verifying.
- **G3.** If webhook auto-registration fails for any reason (Stripe API error, scope missing, non-HTTPS URL, network outage), onboarding **still completes** — the API keys are saved, the admin sees a clear "Webhook auto-registration failed — paste manually below" message, and the manual paste field continues to work. Hard-fail of webhook setup never blocks Connect onboarding.
- **G4.** New code follows SOLID/DRY/DI/Clean Code as enumerated in §5. No new code is added to `StripeConnect` controller beyond a single delegation call.
- **G5.** Test coverage at two levels: unit on the registrar with a stubbed `StripeClient`, integration on the controller hook covering both happy and soft-fail paths. **Both written before any production code.**
- **G6.** `./bin/pre-commit-check.sh --full` green at the end. PHPCS, PHPStan max, PHPMD all clean against existing baselines.

## 4. Scope inventory

### New files

| File | Purpose |
|---|---|
| `src/Stripe/Service/WebhookEndpointRegistrarInterface.php` | Narrow interface — one public method. DIP entry point. |
| `src/Stripe/Service/WebhookEndpointRegistrar.php` | Implementation. Calls `\Stripe\WebhookEndpoint::create/update` via injected `StripeAdapter`. ~80 LOC. |
| `src/Stripe/Service/WebhookEventCatalog.php` | Static list of the events we subscribe to (extracted from `WebhookHandler/*`). One place, reused. DRY. |
| `src/Stripe/Service/Exception/WebhookRegistrationException.php` | Domain exception so the controller can soft-fail without catching `\Stripe\Exception\ApiErrorException` directly (ISP — controller doesn't depend on SDK exception types). |
| `tests/Unit/Stripe/Service/WebhookEndpointRegistrarTest.php` | TDD tests for the registrar. |
| `tests/Integration/Stripe/Controller/Admin/StripeConnectWebhookIntegrationTest.php` | Tests `stripeFinishOnBoarding()` hook both branches. |

### Edited files

| File | Change |
|---|---|
| `src/Stripe/Controller/Admin/StripeConnect.php` | Inject `WebhookEndpointRegistrarInterface` (via the testable-subclass seam or `ContainerFactory`, matching existing pattern lines 27-32). Add ~8 lines after line 58 to call `register()` inside `try/catch(WebhookRegistrationException)`. Set a new view-data flag `blWebhookAutoRegistered`. |
| `src/Stripe/Service/ModuleConfigurationService.php` | If C3 requires it: add per-mode getters `getWebhookSecretForMode(string $mode): ?string`. Backwards-compatible default reads the existing single-valued setting if per-mode is empty. |
| `services.yaml` | Wire `WebhookEndpointRegistrarInterface` → `WebhookEndpointRegistrar` and tag for autowire. |
| `metadata.php` | Add settings: `sStripeWebhookEndpointIdLive`, `sStripeWebhookEndpointIdTest`. If C3 splits the secret: `sStripeWebhookEndpointSecretLive`, `sStripeWebhookEndpointSecretTest`. |
| `views/twig/admin/stripe_connect.html.twig` | Add a small badge: "Webhook configured automatically ✓" when `blWebhookAutoRegistered`, otherwise a yellow notice with paste instructions. |
| `views/azure/twig/admin/module_config.html.twig` (or wherever the secret field is rendered) | If C3 splits: render the right field per current `sStripeMode`. No template gymnastics — keep simple. |

### Explicitly *not* touched

- `WebhookController`, `WebhookHandler/*`, signature verification path — they consume the secret, they don't care how it got there.
- The OXID middleware (`stripe-middleware-test.oxid-esales.com`). Out of scope. C1's failure path involves them but no code change here.
- Stripe SDK version, composer dependencies.
- Contract / payment-base layer.
- Existing OAuth callback URL handling.
- Webhook deletion on module uninstall — see §10 (deferred).

## 5. Code-quality principles — concrete application

The user enumerated **TDD, SOLID, DRY, LI, DI, Clean Code**. For each, here's what it means *in this sprint*:

### TDD
- No production class shipped without a unit test that observed RED first.
- Order of work in §6 is RED → GREEN → REFACTOR per step. Commits in that order if split.
- The integration test on `StripeConnect` is written before the controller line is added. Confirm RED.

### SOLID

**S — Single Responsibility**
- `WebhookEndpointRegistrar` does exactly one thing: create-or-update a Stripe webhook endpoint and return the resulting secret + ID. No I/O on module settings (that's the controller's job). No logging glue that's not its own concern.
- `WebhookEventCatalog` does exactly one thing: list events. Static, immutable, no state.

**O — Open/Closed**
- Registrar accepts the event list via constructor injection (default = `WebhookEventCatalog::all()`), so adding/removing events is config not code change. Don't preemptively over-design — *parameter accepts a list*, that's enough.

**L — Liskov Substitution**
- `WebhookEndpointRegistrar` honors `WebhookEndpointRegistrarInterface` strictly. Test substitutes a fake implementation; production swaps in nothing weird later.
- Do **not** widen exception types in subclasses or specialize return types in ways that break the interface contract.

**I — Interface Segregation**
- The interface has **one method**, `register(string $accessToken, string $mode, string $webhookUrl): WebhookEndpointRegistrationResult`. No `delete()`, no `list()`, no `update()` — those are private mechanics of the implementation. Callers don't need them.
- A separate result value object (`WebhookEndpointRegistrationResult`) with `getSecret(): string` / `getEndpointId(): string`. Not an array; not a tuple — a small DTO with two read-only properties.

**D — Dependency Inversion**
- Controller depends on `WebhookEndpointRegistrarInterface`. Implementation depends on `StripeAdapterInterface` (already in DI container) — **not** on the SDK directly. This keeps the Stripe SDK behind one adapter, consistent with how `Checkout\Session::create()` is wrapped in `CheckoutHelper`.
- Wire via `services.yaml`. Constructor injection only. No `ContainerFactory::getInstance()` inside the registrar (controller uses it because OXID admin controllers don't support DI — the registrar is not bound by that limitation).

### DRY
- Webhook URL: reuse `ModuleConfigurationService::getWebhookUrl()`. Do not rebuild the string in the registrar.
- Event list: defined **once** in `WebhookEventCatalog`. Stripe registration uses it; future feature requests ("also subscribe to X") edit one constant.
- Mode-to-settings mapping (`live` → `sStripeLiveToken`; `test` → `sStripeTestToken`; same for endpoint ID): keep this in `StripeConnect` or a tiny `ModuleSettingKeyForMode` helper. The registrar **takes the access token as a string argument** — it does not know about module settings.

### LI (Liskov / Library Independence)
- Treating this as Liskov substitution (covered under SOLID above).
- Also: the registrar must not leak SDK types in its public surface. `\Stripe\WebhookEndpoint` is an implementation detail. Public return = our own `WebhookEndpointRegistrationResult`. Public exception = our own `WebhookRegistrationException`. This is what makes the Stripe SDK version a swappable detail (Library Independence — same effect via DIP).

### DI
- All collaborators constructor-injected:
  ```php
  public function __construct(
      private readonly StripeAdapterFactoryInterface $adapterFactory,
      private readonly ModuleConfigurationServiceInterface $config,
      private readonly LoggerInterface $logger,
      private readonly WebhookEventCatalogInterface $eventCatalog,
  ) {}
  ```
- No `new` of collaborators inside method bodies (other than DTOs / value objects). No singleton lookups.

### Clean Code
- Method size target: 15-25 lines. The registrar's `register()` body decomposes into `findExistingEndpointId()`, `createEndpoint()`, `updateEndpoint()` — each 5-10 lines.
- No `else`. Early returns and guard clauses.
- No abbreviations in identifiers. `accessToken`, not `accTk`. `webhookUrl`, not `wUrl`.
- Comments only for **why-non-obvious**. The "save secret immediately before saving endpoint ID, because if we crash between the two Stripe never gives us the secret again" comment is mandatory — that's a genuine non-obvious invariant. Most lines need no comment.
- No premature abstraction. There's no `WebhookEndpointRegistrarFactory`, no `AbstractRegistrar`, no `BaseStripeService`. One class implementing one interface.

## 6. Implementation plan — TDD-first

### Step 0 — Pre-flight spike (C1)

5-line throwaway:

```bash
docker compose exec -T php php -r '
require "/var/www/vendor/autoload.php";
\Stripe\Stripe::setApiKey(getenv("ACCESS_TOKEN"));
$e = \Stripe\WebhookEndpoint::create([
    "url" => "https://example.com/sprint-109-spike",
    "enabled_events" => ["payment_intent.succeeded"],
]);
echo "OK secret=" . substr($e->secret, 0, 10) . "...\n";
\Stripe\WebhookEndpoint::delete($e->id);
'
```

If 200 + `whsec_…` → C1 satisfied, proceed. If 403 → pause sprint, escalate to middleware team.

### Step 1 — RED: `WebhookEndpointRegistrarTest`

Unit test, stubbed `StripeClient`. Tests in order of writing:

1. `testRegisterCreatesNewEndpointWhenNoExistingIdProvided` — given no existing ID, calls `WebhookEndpoint::create` once with the right URL and event list, returns the SDK's secret and ID wrapped in the result DTO.
2. `testRegisterUpdatesExistingEndpointWhenIdProvided` — given an existing ID, calls `WebhookEndpoint::update($id, …)` (not create), returns the existing secret (not re-fetched — preserved from prior storage).
3. `testRegisterThrowsWebhookRegistrationExceptionOnStripeApiError` — SDK raises `ApiErrorException`; registrar wraps it in our domain exception with the Stripe error code preserved.
4. `testRegisterRefusesNonHttpsUrl` — webhook URL with `http://`; registrar throws `WebhookRegistrationException::nonHttpsUrl(...)` without calling Stripe at all.
5. `testRegisterUsesEventCatalogList` — assert the SDK was called with `enabled_events` matching `WebhookEventCatalog::all()` exactly.

All 5 tests RED at HEAD (no production code yet). Commit nothing until red is observed for all five.

### Step 2 — GREEN: minimal `WebhookEndpointRegistrar`

Just enough to pass tests 1-5. Resist temptation to add "while we're here" features (deletion, listing, scope inspection). Run unit suite — all green. Commit.

### Step 2.5 — Fix `getWebhookUrl()` to be HTTPS-unconditional

**Why:** `ModuleConfigurationService::getWebhookUrl()` today calls `Registry::getConfig()->getCurrentShopUrl()` (via `OxidShopAdapter::getShopUrl()`), which returns the scheme of the *current request*. If the admin happens to be browsing the OXID backend over HTTP, the webhook URL we send to Stripe is `http://…` and Stripe rejects it with `invalid_request_error`. Latent bug, would surface as a confusing soft-fail in Step 5 if not addressed.

**Steps (TDD):**
1. RED: add unit test on `ModuleConfigurationService::getWebhookUrl()` that asserts the returned URL starts with `https://`, regardless of whether the injected `ShopAdapter` returns HTTP or HTTPS for `getShopUrl()`. (The test means: webhook URL must use SSL form unconditionally.)
2. GREEN: extend `ShopAdapterInterface` with `getSslShopUrl(): string`. Implement in `OxidShopAdapter` as `Registry::getConfig()->getSslShopUrl()`. Change `getWebhookUrl()` to call the SSL variant. Fallback path (when adapter is null) uses `Registry::getConfig()->getSslShopUrl()` instead of `getShopUrl()`.
3. Update any existing tests that asserted the legacy behavior.

5 lines of production diff plus one new test. Bundles cleanly into this sprint because Step 5 depends on the URL being HTTPS-clean.

### Step 3 — REFACTOR

- Confirm `register()` body ≤ 25 lines; extract `createEndpoint()` / `updateEndpoint()` if not.
- Confirm no `else` branches.
- Confirm DTO + exception are sealed (`final` if PHPStan-friendly).
- Re-run unit suite — still green.

### Step 4 — RED: integration test on `StripeConnect`

Two new tests in `StripeConnectWebhookIntegrationTest`:

1. `testStripeFinishOnBoardingRegistersWebhookOnSuccess` — given a successful onboarding return, asserts:
   - `sStripeLiveToken` / `sStripeLivePk` saved (existing behavior preserved).
   - `WebhookEndpointRegistrarInterface::register()` called once.
   - Returned secret and endpoint ID saved to the per-mode settings.
   - View data: `blWebhookAutoRegistered === true`.
2. `testStripeFinishOnBoardingSoftFailsWhenWebhookRegistrationThrows` — given the registrar throws `WebhookRegistrationException`:
   - `sStripeLiveToken` / `sStripeLivePk` still saved.
   - View data: `blWebhookAutoRegistered === false`.
   - The exception did **not** propagate; the controller returned normally.
   - Warning logged with the exception's reason.

Use the testable-subclass pattern (memory: works for OXID admin controllers — see `TestableOrderRefundForVisibility` example in CLAUDE.md). Inject a fake `WebhookEndpointRegistrarInterface` via constructor seam.

Both RED at HEAD. Commit nothing until observed.

### Step 5 — GREEN: controller integration

Add the ~8-line hook to `stripeFinishOnBoarding()` after the existing key-save block:

```php
$blWebhookAutoRegistered = false;
try {
    $result = $this->webhookRegistrar->register(
        $sAccessToken,
        $sMode,
        $this->moduleConfig->getWebhookUrl()
    );
    $this->moduleSettingService->save($this->endpointIdKey($sMode), $result->getEndpointId(), Module::MODULE_ID);
    $this->moduleSettingService->save($this->endpointSecretKey($sMode), $result->getSecret(), Module::MODULE_ID);
    $blWebhookAutoRegistered = true;
} catch (WebhookRegistrationException $e) {
    $this->logger->warning('Webhook auto-registration failed', ['reason' => $e->getMessage()]);
}
```

The `endpointIdKey()` / `endpointSecretKey()` mode-to-setting-name helpers live on the controller (or are pulled into a tiny `ModuleSettingKeyForMode` value if they grow — start simple). Run integration tests — green.

### Step 6 — Template + view-data badge

Update `stripe_connect.html.twig` to render the badge. Tiny CSS class reuse (existing module CSS bundle). No new asset bundle.

### Step 7 — `services.yaml` wiring, metadata.php settings

Register the new service + DTO + catalog + exception (autowire if possible). Add the new metadata.php setting names. `bin/oe-console oe:module:deactivate && oe:module:activate` on local dev shop to confirm activation still works (per memory: services.yaml mistakes break activation).

### Step 8 — Per-mode secret split (only if C3 requires)

If `sStripeWebhookEndpointSecret` is single-valued today, split it. Backwards-compat: `getWebhookSecret()` reads per-mode setting first, falls back to legacy single-valued setting if per-mode is empty. One additional test covering the fallback. Mark legacy setting as deprecated in metadata.php description but don't delete — existing installs would lose their config.

### Step 9 — Final verification

- `./bin/pre-commit-check.sh --full` green (PHPCS, PHPUnit Unit + Integration, PHPStan max, PHPMD against baseline).
- Manual smoke on local shop:
  - Re-run Connect onboarding in test mode.
  - Confirm the badge says "Webhook configured ✓".
  - Open Stripe Dashboard test mode → Developers → Webhooks → confirm endpoint exists with the right URL and event list.
  - Trigger a `payment_intent.succeeded` via a test purchase → confirm webhook delivers and the contract progresses.
  - Re-click Connect → confirm no duplicate endpoint in Dashboard; the existing one was updated.

## 7. Risk register

- **R1 — OAuth scope missing.** Mitigated by C1 spike. If scope is missing, sprint pauses; we don't half-ship a feature that doesn't work in test mode.
- **R2 — Race between save-secret and save-endpoint-id.** If the secret is saved but the endpoint ID save fails, re-onboarding can't find the existing endpoint and creates a duplicate. Mitigation: save endpoint ID **first** (it's idempotent — we can re-derive the secret by deleting and recreating); save secret **second**. Document this invariant inline with a `// WHY` comment.
- **R3 — Stripe rate limits.** Webhook endpoint creation is rate-limited (low — maybe ~1/sec). Onboarding is human-paced; not a concern in normal flow. If a script re-runs onboarding in a tight loop, Stripe's `rate_limit_error` surfaces as `WebhookRegistrationException` and we soft-fail. Acceptable.
- **R4 — Non-HTTPS dev shops.** Mitigated by explicit `nonHttpsUrl` check before SDK call. Soft-fall back to manual paste. Local-dev developers keep the existing flow; no friction added.
- **R5 — Existing shops with manually-pasted secrets.** Migration story: do nothing. The new auto-registration runs on next Connect onboarding; until then, the manual secret keeps working. After Connect runs once, the auto-registered ID and secret replace the manual one — this is fine and intended.
- **R6 — Webhook endpoint orphaned on module uninstall.** Out of scope (see §10). Admin can delete from Dashboard. Worth a small note in the README, not a code change.

## 8. Acceptance checklist

- [ ] C1 spike performed and documented in PR description (Stripe response, endpoint ID created and deleted).
- [ ] C3 decided (single vs per-mode secret); if split, migration & fallback covered by test.
- [ ] `WebhookEndpointRegistrarInterface` and one implementation, with ≥5 unit tests written RED first.
- [ ] `StripeConnect` controller has ≤ 10 added lines plus the new collaborator injection; both happy-path and soft-fail integration tests written RED first.
- [ ] All new code: no `else`, methods ≤ 25 lines, constructor DI only, no SDK types in public surface.
- [ ] `./bin/pre-commit-check.sh --full` green.
- [ ] Manual smoke per §6 Step 9 passes against a real Stripe test account.
- [ ] No regression in existing 1002 Stripe tests.
- [ ] PR description includes: link to feasibility report `04-`, C1 spike output, sample Stripe Dashboard screenshot of the created endpoint, screenshot of the new admin badge.

## 9. Files & line targets

Total expected diff (rough):
- New: ~300 LOC across 6 files (registrar interface + impl + catalog + exception + DTO + 2 test files).
- Edited: ~40 LOC across 5 files (controller, services.yaml, metadata.php, 2 templates).
- Total: ~340 LOC production + tests. PR review effort: medium. Single reviewer should be able to read the whole diff in 30 minutes.

## 10. Out of scope (explicitly deferred)

- **Webhook deletion on module uninstall.** If we have the endpoint ID we technically could `WebhookEndpoint::delete($id)` from `events_deactivate()`. Decision: don't. Stripe's Dashboard is the source of truth for what endpoints exist; removing one out from under an admin who's testing things is bad UX. Document the orphan behavior in the README and let the admin clean up when they decide to.
- **Webhook endpoint health monitoring.** Stripe surfaces delivery failures in the Dashboard; we don't need to mirror that in OXID. Maybe a future sprint if support calls increase.
- **Migrating away from the OXID middleware to direct Connect OAuth.** Much bigger scope, security review needed, separate sprint or multi-sprint.
- **Splitting `sStripeWebhookEndpointSecret` if C3 says it's already split.** Verify and skip the work if the schema is already mode-aware.

---

**Status:** ⏸ awaiting approval. Will not run the C1 spike until approved.

**Approval needed on:**
1. Sprint scope as written.
2. Decision on §5 LI interpretation (Liskov Substitution + Library Independence — both covered above; flag if you meant something else).
3. Whether to bundle the C3 per-mode-secret split or split into its own sprint.
