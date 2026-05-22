# Sprint 110 — Manual "Create webhooks" button (replaces Sprint 109's broken auto-path)

**Module:** `extensions/stripe`
**Branch base:** `b-7.4.x` (or whatever Sprint 109 merges into)
**Mode:** TDD-first. Single feature branch, one PR, three reviewable commits if split (registrar/api change + tests, controller AJAX action + tests, view+lang+metadata).

## 1. Why

Sprint 109 attempted to auto-register a Stripe webhook endpoint immediately after Connect onboarding by calling `WebhookEndpoint::create()` with the OAuth `access_token`. Live verification today returned:

> Stripe API error: "You are not permitted to configure webhook endpoints on a connected account. Did you mean to create a Connect webhook on your account instead?"

This is not a bug we can fix in code. **A Stripe Connect connected-account access_token is not permitted to call `webhookEndpoints->create` — not with `connect: true`, not without.** The C1 pre-flight in the feasibility report would have caught this (it was deferred). The soft-fail path in Sprint 109 works exactly as designed and the admin sees "manual paste required" — but the "auto" promise can't be delivered with this Connect topology.

The old Smarty-era module (`oxid-parts/stripe-module`) implemented the same feature differently and **it works**:

1. Two distinct key fields per environment:
   - `sStripeTestToken` / `sStripeLiveToken` — connected-account access_token from OAuth (charges).
   - `sStripeTestKey` / `sStripeLiveKey` — **manually pasted** platform secret key from the merchant's Stripe Dashboard (webhook management).
2. A "Create webhooks" button in admin triggers an AJAX call to `webhookEndpoints->create([..., 'connect' => true])` using the manually-pasted platform key.
3. `connect: true` registers a *platform-level Connect webhook* that receives events for every connected account, including this merchant's own connection.

Sprint 110 ports the old workflow into the new module, preserving Sprint 109's correct soft-fail and reusing the registrar/API/exception/catalog/result DTO that Sprint 109 already shipped.

## 2. Goals

- **G1.** Admin can paste a platform secret key into a per-mode setting (`sStripeTestKey` / `sStripeLiveKey`), click "Create webhooks", and the Stripe Dashboard receives a new Connect webhook with the correct URL and event list. The signing secret + endpoint ID are persisted to the per-mode settings Sprint 109 already added (`sStripeWebhookEndpointSecretLive/Test`, `sStripeWebhookEndpointIdLive/Test`).
- **G2.** Re-clicking the button when an endpoint ID is already saved performs an `update` instead of `create`, preserving the existing signing secret (Sprint 109's `WebhookEndpointRegistrar::register()` already does this — we reuse it unchanged).
- **G3.** When the platform key is missing or empty, the AJAX action returns a structured JSON error and the UI shows "Paste your platform key in Module Configuration first." No silent failure, no Stripe API call wasted.
- **G4.** When Stripe API returns an error (bad key, rate limit, scope, network), the AJAX action returns a structured JSON error with the Stripe error message surfaced for the admin. The existing webhook config remains untouched on failure.
- **G5.** The auto-registration call in `StripeConnect::stripeFinishOnBoarding()` is **removed**. It cannot succeed with a connected-account token, and shipping a code path that always fails (even softly) is dead weight and misleading to the admin. The `blWebhookAutoRegistered` view-data flag goes away with it. The post-onboarding view tells the admin where to go next ("Module Configuration → paste platform key, then return here and click Create webhooks").
- **G6.** Test coverage at two levels: unit on the API adapter / registrar / event catalog edits, and unit on the new admin AJAX action (testable-subclass pattern, fake registrar). All tests RED before production code lands.
- **G7.** `./bin/pre-commit-check.sh --full` green. PHPCS, PHPStan max, PHPMD clean against baselines.

## 3. Sprint 109 cleanup — what gets deleted

Yesterday's Sprint 109 commit (`ce96dc8 test`, 2026-05-21) shipped an auto-registration code path that we now know cannot work with Stripe Connect connected-account tokens. The path soft-fails on every onboarding return. **Shipping dead code is a worse outcome than not shipping the feature** — it's noise in `git blame`, dead `Container::get()` calls on every admin onboarding, an extra view-data flag with no useful state, and obsolete tests that pin behavior we're removing.

This sprint deletes the dead code in a single pass. The list below is exhaustive — anything not on it stays.

### 3.1 Production code to delete

| File | What goes | Why |
|---|---|---|
| `src/Stripe/Controller/Admin/StripeConnect.php` | The entire `tryRegisterWebhook(string $accessToken, string $mode): bool` method (~20 LOC). | Always throws `WebhookRegistrationException` under Stripe Connect rules; soft-fails 100% of the time in production. |
| `src/Stripe/Controller/Admin/StripeConnect.php` | The call to `tryRegisterWebhook(...)` inside `stripeFinishOnBoarding()` (4 LOC around line 89). | Dead caller of dead method. |
| `src/Stripe/Controller/Admin/StripeConnect.php` | The `$blWebhookAutoRegistered` local variable + corresponding view-data assignment (3 LOC around line 95). | Replaced by `$blPlatformKeyConfigured` which reflects an actually-useful state. |

**Not deleted** (load-bearing, reused by the new AJAX action in §6):
- `$webhookRegistrar`, `$moduleConfig`, `$logger` collaborator properties — the new action consumes all three.
- `initializeCollaborators(...)` testable seam — same pattern, same usage.
- `endpointIdKey(string $mode): string` / `endpointSecretKey(string $mode): string` — reused for persisting the AJAX result.
- `WebhookEndpointRegistrar`, `WebhookEndpointRegistrationResult`, `WebhookEventCatalog`, `WebhookRegistrationException`, `StripeWebhookEndpointApi`/`Interface` — entire Sprint 109 service stack carries through; only the *caller* changes.

### 3.2 Tests to delete

| File | What goes | Why |
|---|---|---|
| `tests/Unit/Stripe/Controller/Admin/StripeConnectWebhookRegistrationTest.php` | `testStripeFinishOnBoardingRegistersWebhookOnSuccess` | Pins behavior (registrar called on onboarding return) we're explicitly removing. |
| `tests/Unit/Stripe/Controller/Admin/StripeConnectWebhookRegistrationTest.php` | `testStripeFinishOnBoardingSoftFailsWhenWebhookRegistrationThrows` | Same — soft-fail path no longer exists, the registrar isn't called from this controller method anymore. |
| `tests/Unit/Stripe/Controller/Admin/StripeConnectWebhookRegistrationTest.php` | Any test asserting `$blWebhookAutoRegistered` view-data value (likely 1-2 tests). | Flag removed. |

Decision rule for what to keep in `StripeConnectWebhookRegistrationTest`: if a test's name or assertion mentions `tryRegisterWebhook`, `blWebhookAutoRegistered`, or auto-registration outcome, **delete it**. If a test only verifies credential persistence (`sStripeTestToken` save, etc.), **keep it** unchanged — that's still load-bearing.

After deletion, rename the file to `StripeConnectCredentialPersistenceTest.php` if it ends up containing only credential-persistence tests — the old name no longer describes what's inside (DRY for filenames: name says what the file does *now*, not what it did when written).

### 3.3 Translation strings to delete

| Key | Lang files | Reason |
|---|---|---|
| `STRIPE_WEBHOOK_AUTO_REGISTERED` (or the actual key Sprint 109 used for the green badge) | `views/admin_twig/{en,de}/stripe_lang.php` | Badge no longer shown. |
| `STRIPE_WEBHOOK_MANUAL_PASTE_REQUIRED` (or actual key for the amber notice) | `views/admin_twig/{en,de}/stripe_lang.php` | Notice no longer shown; replaced by the new "Webhook setup" section text. |

Concrete keys to find/remove: `git diff b-7.4.x..HEAD -- views/admin_twig/` after Sprint 109 will show exactly the 2 lines added per language. Cross-check before deletion so we don't drop something that's referenced elsewhere — `grep -r STRIPE_WEBHOOK_ src/ views/` should find zero refs to the deleted keys after the template edit in §6 Step 6.

### 3.4 Template fragments to delete

| File | What goes | Why |
|---|---|---|
| `views/twig/admin/stripe_connect.html.twig` | The Sprint 109 auto-registration badge block (the `{% if blWebhookAutoRegistered %}` … `{% else %}` … `{% endif %}` region). | Replaced by the new "Webhook setup" section (status + button + helper text). |

### 3.5 Things that stay (despite being yesterday's work)

These are all from Sprint 109 and Sprint 110 builds on them rather than replacing them:

- The 4 per-mode metadata.php settings (`sStripeWebhookEndpointIdLive/Test`, `sStripeWebhookEndpointSecretLive/Test`) — the new action persists into them.
- `ModuleConfigurationService::getWebhookUrl()` SSL-unconditional fix (Step 2.5 of Sprint 109).
- `ModuleConfigurationService::getWebhookSecret()` per-mode resolution with legacy fallback.
- `services.yaml` aliases for `WebhookEndpointRegistrarInterface` and `StripeWebhookEndpointApiInterface`.
- The PHPStan baseline and PHPMD baseline as Sprint 109 left them — no new entries expected from this sprint.
- All 15 RED-first tests from Sprint 109 minus the obsolete subset listed in §3.2.

### 3.6 Deletion verification

After the cleanup commits land, the following commands must all produce **empty output**:

```bash
grep -rn 'tryRegisterWebhook' src/ tests/
grep -rn 'blWebhookAutoRegistered' src/ views/ tests/
grep -rn 'STRIPE_WEBHOOK_AUTO_REGISTERED\|STRIPE_WEBHOOK_MANUAL_PASTE_REQUIRED' src/ views/ tests/
```

If any line comes back, that's an orphan and the cleanup is incomplete.

## 4. Scope inventory

### New files

| File | Purpose |
|---|---|
| `tests/Unit/Stripe/Controller/Admin/StripeCreateWebhookEndpointActionTest.php` | Unit tests for the new AJAX action (5 tests). Uses the testable-subclass seam established in Sprint 109. |

### Edited files

| File | Change |
|---|---|
| `metadata.php` | Add two new `moduleSettings` entries: `sStripeTestKey` (position 22), `sStripeLiveKey` (position 32). Both `type: str`. Description identifies them as "platform secret key for webhook management — paste from Stripe Dashboard → Developers → API keys". |
| `src/Stripe/Adapter/StripeWebhookEndpointApiInterface.php` | Add `bool $isConnect = false` parameter to `create()` (last positional, default `false` to preserve LSP). `update()` is unchanged — Stripe doesn't allow toggling `connect` after creation. |
| `src/Stripe/Adapter/StripeWebhookEndpointApi.php` | When `$isConnect === true`, include `'connect' => true` in the payload sent to `webhookEndpoints->create`. One conditional line; no other behavior change. |
| `src/Stripe/Service/WebhookEndpointRegistrarInterface.php` | Add `bool $isConnect = false` parameter to `register()`, passed straight through to the API adapter. Same LSP rationale. |
| `src/Stripe/Service/WebhookEndpointRegistrar.php` | Forward the new parameter to `$this->api->create(...)`. `assertHttps()` and the create/update branching stay untouched. |
| `src/Stripe/Service/ModuleConfigurationService.php` | Add `getPlatformKey(): string` that returns `sStripeTestKey` or `sStripeLiveKey` based on `sStripeMode`. Mirrors `getSecretKey()`/`getToken()`. ~6 LOC. |
| `src/Stripe/Controller/Admin/StripeConnect.php` | **Add** the AJAX action method `stripeCreateWebhookEndpoint()` plus its private helpers (`platformKeyKey`, `persistEndpoint`, `readStoredEndpointId`, `respondJson`). **Set** the new `blPlatformKeyConfigured` view-data flag (replacing `blWebhookAutoRegistered` — see §3.1). Reuse existing collaborator properties (`$webhookRegistrar`, `$moduleConfig`, `$logger`, `$moduleSettingService`) and helpers (`endpointIdKey`, `endpointSecretKey`, `tokenKey`, `publishableKeyKey`) from Sprint 109 without modification. Net: +60 LOC added, ~25 LOC removed (per §3.1) = +35 LOC. |
| `views/twig/admin/stripe_connect.html.twig` | Replace the auto-registration badge block with: webhook status (configured / not configured), a "Create webhooks" button (disabled if no platform key), helper text pointing to Module Configuration when the platform key is missing, and inline JS (≤30 LOC) that POSTs to `stripeCreateWebhookEndpoint` and updates the status. |
| `views/admin_twig/{en,de}/stripe_lang.php` | Add lang keys (4 new: `STRIPE_WEBHOOK_CREATE_BUTTON`, `STRIPE_WEBHOOK_PLATFORM_KEY_MISSING`, `STRIPE_WEBHOOK_CONFIGURED`, `STRIPE_WEBHOOK_CREATE_ERROR`). Remove obsolete Sprint 109 keys for auto-registration outcome messages. |
| `views/admin_twig/{en,de}/module_options.php` (or wherever module setting labels live) | Add labels and help text for `sStripeTestKey`/`sStripeLiveKey`. |
| `services.yaml` | No new services. Confirm Sprint 109's aliases for `WebhookEndpointRegistrarInterface` and `StripeWebhookEndpointApiInterface` remain. |
| `tests/Unit/Stripe/Service/WebhookEndpointRegistrarTest.php` | Add one test: `testRegisterPassesConnectFlagThroughToApi`. Existing tests must continue to pass unchanged (default `$isConnect = false` preserves behavior). |
| `tests/Unit/Stripe/Controller/Admin/StripeConnectWebhookRegistrationTest.php` | **Delete** obsolete tests per §3.2. **Add** one test: `testStripeFinishOnBoardingSetsBlPlatformKeyConfiguredViewData`. After deletion, rename the file if its remaining tests are only about credential persistence (see §3.2 decision rule). Net: ~−150 LOC. |

### Explicitly *not* touched

- `WebhookController`, `WebhookHandler/*`, webhook signature verification, contract state machine — none of this changes.
- `WebhookEndpointRegistrar::update()` — Stripe doesn't allow updating the `connect` flag, so update stays identical.
- The OXID middleware (`osm.oxid-esales.com`) — out of scope. The OAuth `access_token` it returns continues to be saved to `sStripe*Token`; we just stop trying to use it for webhook management.
- Webhook deletion on module uninstall — still deferred per Sprint 109's plan §10.
- Composer dependencies, Stripe SDK version.
- Sprint 109's per-mode-secret split — already done; we use it.

## 5. Code-quality principles — concrete application

### TDD

- No production code without a RED test first. Order of work in §6 is RED → GREEN → REFACTOR per step.
- The registrar's `$isConnect` parameter has a dedicated test written first.
- The API adapter's payload inclusion of `'connect' => true` is asserted via a test double for `StripeClient` (the existing pattern from Sprint 109 — `StripeWebhookEndpointApi` already accepts a client factory).
- The admin AJAX action has 5 tests written first; all RED at HEAD before any controller code is added.
- The deletion of `tryRegisterWebhook()` is performed only after the obsolete tests are removed; the new view-data test passes first.

### SOLID

**S — Single Responsibility**
- `ModuleConfigurationService::getPlatformKey()` only knows "which setting name for which mode" and returns the value. No business logic.
- The new AJAX action method on `StripeConnect` does exactly four things in this order: validate session, resolve platform key, delegate to registrar, return JSON. No webhook URL building (delegated to `ModuleConfigurationService`), no event list (delegated to `WebhookEventCatalog`), no SDK calls (delegated to `StripeWebhookEndpointApi`).

**O — Open/Closed**
- `register()` and `create()` gain an optional `$isConnect` parameter with a `false` default. Callers that never want a Connect webhook (none today, but future provider-account-level webhooks) pass nothing; callers that need one pass `true`. No new methods, no inheritance gymnastics.

**L — Liskov Substitution**
- Both interface changes are **strictly additive with safe defaults**. Existing test fakes that implement `WebhookEndpointRegistrarInterface` continue to satisfy the contract without modification because the new parameter has a default. The fake used in the new AJAX-action tests honors the same contract — calling `register($token, $url, null, true)` returns a real `WebhookEndpointRegistrationResult`, no widened exceptions, no narrowed return.
- Production `WebhookEndpointRegistrar` still throws only `WebhookRegistrationException`; the new parameter does not change exception types.

**I — Interface Segregation**
- We do not introduce a parallel `ConnectWebhookEndpointRegistrarInterface`. One method on one interface with a flag is simpler, satisfies all callers, and respects the rule that interfaces should be minimal. We are **not** widening the interface with `delete()`, `list()`, or `retrieve()` — none of those are needed in this sprint.

**D — Dependency Inversion**
- The admin controller resolves `WebhookEndpointRegistrarInterface` (not the concrete class) from the container via the existing seam Sprint 109 introduced. The registrar depends on `StripeWebhookEndpointApiInterface` (also unchanged). Stripe SDK types stay encapsulated inside `StripeWebhookEndpointApi`.
- The action method receives no SDK types in its signature; the JSON returned to the browser never contains an `\Stripe\…` object.

### DRY

- The webhook URL builder lives in `ModuleConfigurationService::getWebhookUrl()` — Sprint 109 already made it HTTPS-unconditional. The new action reuses it; no duplicate URL string anywhere.
- The event list lives in `WebhookEventCatalog::all()`. The new code path passes through it unchanged. Adding/removing events remains a one-line edit in one place.
- The "save endpoint ID first, secret second" invariant lives inside `WebhookEndpointRegistrar` (Sprint 109). We reuse it; the AJAX action just calls `register()` and persists the returned ID + secret with the same ordering.
- Per-mode key resolution (`live`/`test` → setting name) is consolidated. `tokenKey()`/`publishableKeyKey()`/`endpointIdKey()`/`endpointSecretKey()` already exist on `StripeConnect` (Sprint 109) — we add `platformKeyKey()` next to them. No new helper class for one-line mode-mapping.

### LSP / Library Independence (LI)

- Liskov: covered under SOLID above.
- Library Independence: the Stripe SDK call `webhookEndpoints->create([..., 'connect' => true])` lives only in `StripeWebhookEndpointApi`. The rest of the codebase still talks to `StripeWebhookEndpointApiInterface` + our DTO + our exception. Upgrading from Stripe SDK v19 to v20 still touches one file.

### DI

- All collaborators constructor-injected in services; the OXID admin controller uses `ContainerFactory::getInstance()` only in its constructor (framework constraint), routing values into the protected `initializeCollaborators()` seam.
- Test subclasses bypass `parent::__construct()` and call the seam directly with fakes — same pattern Sprint 109 established.
- No `new` of collaborators in method bodies. No singleton lookups outside the constructor.

### Clean Code

- Methods 15-25 lines. The AJAX action method body decomposes into named helpers (`validateSessionChallenge`, `resolvePlatformKey`, `registerWebhook`, `persistEndpoint`, `respondWithJson`) so the orchestration reads top-to-bottom in one screen.
- No `else`. Guard clauses and early returns.
- No abbreviations: `platformKey`, `webhookUrl`, `endpointId`. Not `pk`, `whUrl`, `epId`.
- Comments only for genuine "why-non-obvious." The required comment in this sprint: the rationale on the `connect: true` payload entry (one line: "Stripe rejects this call from connected accounts; only a platform secret key can configure Connect webhooks").
- JSON envelope shape is `{ "success": bool, "endpointId"?: string, "message"?: string }` — flat, no nesting, no `code`/`status`/`body` triple wrap from the old module. Smaller surface for the browser to parse.
- No premature abstraction: no `JsonResponseBuilder` service, no `PlatformKeyValidator` service, no `AjaxActionInterface`. The action is ~30 LOC in one method on the controller.

### No overengineering

- No new controller class. The action lives on the existing `StripeConnect` admin controller alongside `stripeFinishOnBoarding()`. Both are admin-only routes; both deal with webhook setup.
- No new template files. The existing `stripe_connect.html.twig` gains a status block and a button.
- No abstract `AjaxAction` base. PHP's `header()` + `echo` + `exit()` is fine for one endpoint that returns JSON. (Yes, exit() is normally a smell, but OXID admin AJAX actions follow this convention — see `WebhookController::process()` for precedent.)
- No webhook deletion, listing, or health-check actions in this sprint. Each is its own sprint if/when needed.
- No client-side framework. ~30 LOC of vanilla JS in the template handles the XHR and DOM update, matching the old module's approach.

## 6. Implementation plan — TDD-first

### Step 1 — RED: registrar pass-through test

In `tests/Unit/Stripe/Service/WebhookEndpointRegistrarTest.php`, add:

```php
public function testRegisterPassesConnectFlagThroughToApi(): void
{
    $api = $this->createMock(StripeWebhookEndpointApiInterface::class);
    $api->expects($this->once())
        ->method('create')
        ->with(
            'sk_test_platform',
            'https://shop.example/index.php?cl=StripeWebhookController',
            $this->isType('array'),
            $this->isType('string'),
            true,   // ← isConnect
        )
        ->willReturn(new WebhookEndpointRegistrationResult('we_123', 'whsec_abc'));

    $registrar = new WebhookEndpointRegistrar($api, new WebhookEventCatalog());
    $registrar->register(
        'sk_test_platform',
        'https://shop.example/index.php?cl=StripeWebhookController',
        null,
        true,
    );
}
```

Observe RED — `Too few arguments to function StripeWebhookEndpointApiInterface::create`. Commit nothing.

### Step 2 — GREEN: interface + implementation changes

- `StripeWebhookEndpointApiInterface::create()` gets `bool $isConnect = false` at the end.
- `StripeWebhookEndpointApi::create()` adds `if ($isConnect) { $payload['connect'] = true; }` before the SDK call.
- `WebhookEndpointRegistrarInterface::register()` gets `bool $isConnect = false`.
- `WebhookEndpointRegistrar::register()` forwards the flag to `$this->api->create(...)`.

Run unit suite — all 5 existing registrar tests still pass (defaults preserve behavior), the new test passes. Commit.

### Step 3 — REFACTOR (sanity check)

- Confirm `register()` body remains ≤ 25 lines.
- Confirm no `else` branches introduced.
- Re-run unit suite — green.

### Step 4 — RED: admin AJAX action tests

Create `tests/Unit/Stripe/Controller/Admin/StripeCreateWebhookEndpointActionTest.php` with 5 tests, all written before any controller code:

1. `testReturnsSuccessJsonAndPersistsEndpointWhenRegistrarSucceeds` — given a configured platform key and a registrar that returns `('we_123', 'whsec_abc')`, assert: JSON output is `{"success":true,"endpointId":"we_123"}`, the `sStripeWebhookEndpointIdTest` setting is saved to `we_123`, the `sStripeWebhookEndpointSecretTest` setting is saved to `whsec_abc`, the registrar was called exactly once with `$isConnect = true`.
2. `testReturnsErrorJsonWhenPlatformKeyMissing` — given the configured platform key is empty, assert: JSON output is `{"success":false,"message":"<lang key>"}`, the registrar was **not** called, no settings were modified.
3. `testReturnsErrorJsonWhenRegistrarThrowsWebhookRegistrationException` — given the registrar throws `WebhookRegistrationException::fromApiError('rate_limit', '…')`, assert: JSON output is `{"success":false,"message":"…the exception message…"}`, no settings were modified.
4. `testReturns403WhenSessionChallengeInvalid` — given `checkSessionChallenge()` returns false, assert: HTTP 403 set on the response, JSON output is `{"success":false,"message":"<access denied>"}`, the registrar was **not** called.
5. `testUsesPlatformKeyForCurrentMode` — given `sStripeMode = 'live'`, assert: the registrar received the value of `sStripeLiveKey` (not `sStripeTestKey`); flip to `test`, assert the inverse.

Use the testable-subclass pattern (`TestableStripeConnect extends StripeConnect`) bypassing `parent::__construct()` and calling `initializeCollaborators(...)` with fakes. Capture `echo`'d JSON via `ob_start()` / `ob_get_clean()`.

Observe RED — `Call to undefined method stripeCreateWebhookEndpoint`. Commit nothing.

### Step 5 — GREEN: admin AJAX action implementation

Add to `StripeConnect.php`:

```php
public function stripeCreateWebhookEndpoint(): void
{
    if (!Registry::getSession()->checkSessionChallenge()) {
        $this->respondJson(403, ['success' => false, 'message' => 'access_denied']);
        return;
    }

    $mode        = $this->moduleConfig->getMode();
    $platformKey = $this->moduleConfig->getPlatformKey();

    if ($platformKey === '') {
        $this->respondJson(400, ['success' => false, 'message' => 'platform_key_missing']);
        return;
    }

    try {
        $existingId = $this->readStoredEndpointId($mode);
        $result     = $this->webhookRegistrar->register(
            $platformKey,
            $this->moduleConfig->getWebhookUrl(),
            $existingId,
            true, // Connect webhook — connected-account keys cannot create webhooks at all.
        );
        $this->persistEndpoint($mode, $result);
        $this->respondJson(200, ['success' => true, 'endpointId' => $result->endpointId]);
    } catch (WebhookRegistrationException $e) {
        $this->respondJson(400, ['success' => false, 'message' => $e->getMessage()]);
    }
}
```

Add `platformKeyKey($mode)`, `persistEndpoint($mode, $result)`, `readStoredEndpointId($mode)`, `respondJson($code, $payload)` as small private helpers. Re-use `endpointIdKey()`/`endpointSecretKey()` from Sprint 109.

Run the AJAX-action tests — all 5 green. Commit.

### Step 6 — Apply Sprint 109 cleanup (§3)

Execute the cleanup checklist from §3 in this order (so tests stay green at every commit):

1. **Add the new green test first.** In `StripeConnectWebhookRegistrationTest.php` (or its renamed successor), add `testStripeFinishOnBoardingSetsBlPlatformKeyConfiguredViewDataBasedOnMode` — asserts the new view-data flag is set correctly under three states (test mode + key set, test mode + key empty, live mode + key empty). Observe RED. Commit nothing.
2. **Add the view-data flag in production code.** Set `$blPlatformKeyConfigured` in `stripeFinishOnBoarding()` based on `$this->moduleConfig->getPlatformKey() !== ''`. The new test passes; the old `testStripeFinishOnBoardingRegistersWebhookOnSuccess` should also still pass since `tryRegisterWebhook()` is still there.
3. **Delete the obsolete tests** listed in §3.2. Suite stays green because the production code those tests pinned is still present.
4. **Delete `tryRegisterWebhook()` and its caller** from `StripeConnect.php` (§3.1). Delete the `$blWebhookAutoRegistered` local + view-data assignment.
5. **Delete the lang keys (§3.3) and template fragments (§3.4).** Run the §3.6 verification grep — must produce empty output.
6. **Rename `StripeConnectWebhookRegistrationTest.php`** if applicable per §3.2 decision rule.

Run full unit suite after each step — green. Commit as one logical change (cleanup) or split into 2 commits (production delete / test delete) if reviewer prefers.

### Step 7 — Template + lang + metadata

- Update `views/twig/admin/stripe_connect.html.twig`:
  - Remove the Sprint 109 auto-registration badge.
  - Add a "Webhook setup" section with:
    - Webhook URL displayed (read-only) so the admin can verify what Stripe will be told.
    - Current state: "Configured" (green) if `sStripeWebhookEndpointIdLive/Test` for the current mode is non-empty, else "Not configured" (amber).
    - "Create webhooks" button — disabled when `blPlatformKeyConfigured === false`, with a help line pointing to Module Configuration.
    - Inline `<script>` (≤ 30 LOC of vanilla JS, no jQuery): wires the button to `XMLHttpRequest` against `?cl=StripeConnect&fnc=stripeCreateWebhookEndpoint&stoken=…`, parses the JSON, updates the badge.
- Update `metadata.php`:
  - Add `sStripeTestKey` at position 22, type `str`, value `''`.
  - Add `sStripeLiveKey` at position 32, type `str`, value `''`.
- Update lang files:
  - `STRIPE_WEBHOOK_CREATE_BUTTON`, `STRIPE_WEBHOOK_PLATFORM_KEY_MISSING`, `STRIPE_WEBHOOK_CONFIGURED`, `STRIPE_WEBHOOK_NOT_CONFIGURED`, `STRIPE_WEBHOOK_CREATE_ERROR`.
  - Setting labels: `SHOP_MODULE_sStripeTestKey`, `SHOP_MODULE_sStripeLiveKey`, plus `HELP_SHOP_MODULE_*` for both.
  - Remove obsolete Sprint 109 keys.

Run integration smoke (manual): activate module, visit Module Configuration, confirm the new fields render with help text, paste a test platform key, visit `?cl=StripeConnect`, confirm the button is enabled and the status is correct. (Actual Stripe API call is exercised by the live smoke in §6 Step 9.)

### Step 8 — Final verification

- `./bin/pre-commit-check.sh --full` green.
- PHPCS, PHPStan max, PHPMD against existing baselines — all clean.
- Test count: roughly +5 (new AJAX-action tests) +1 (registrar passthrough) −3 (obsolete Sprint 109 tests removed) = net +3 tests vs. Sprint 109 baseline.

### Step 9 — Live smoke (user action — requires live Stripe test account)

1. Open Stripe Dashboard → Developers → API keys. Copy the **Standard** test secret key (`sk_test_…`).
2. In OXID admin → Extensions → Module Configuration → Stripe Wallet, paste it into `sStripeTestKey`. Save.
3. Navigate to `…/admin/?cl=StripeConnect`. Confirm: "Webhook not configured" + button is enabled.
4. Click "Create webhooks". Confirm: badge flips to "Webhook configured ✓" + endpoint ID is shown.
5. Open Stripe Dashboard → Developers → Webhooks → Connect tab. Confirm: a new endpoint exists with URL `…/index.php?cl=StripeWebhookController`, the right event list, and **"Listens to: Connected accounts"**.
6. Make a test purchase. `payment_intent.succeeded` should deliver and the contract should progress.
7. Click "Create webhooks" again — confirm no duplicate endpoint appears in the Dashboard; the existing one is updated in place.

## 7. Risk register

- **R1 — Stripe platform key has insufficient permissions.** A restricted API key without `webhooks` write would fail. Mitigated by surfacing the Stripe error in the JSON `message` field — the admin sees the exact reason and can switch to a non-restricted key. No code branch needed.
- **R2 — Race between save endpoint-id and save secret.** Same as Sprint 109. Already handled by `WebhookEndpointRegistrar`'s "ID first, secret second" ordering, which we reuse unchanged.
- **R3 — Browser session expires mid-flow.** Session challenge check returns 403; the JS surfaces this. No state corruption — nothing was saved.
- **R4 — Admin accidentally pastes a *test* platform key while in *live* mode.** Stripe will accept the call and create a test-mode webhook (because the key namespace determines the mode, not our `sStripeMode`). The Dashboard will show the endpoint in the wrong tab. Acceptable — same trap exists in the old module. Helper text on the metadata setting calls this out.
- **R5 — Admin re-clicks during long Stripe latency.** The button disables itself while the XHR is in flight (one line of JS); double-clicks are no-ops. Stripe's create-endpoint is also idempotent enough at the contract level — duplicates would still need ID drift.

## 8. Acceptance checklist

- [ ] `sStripeTestKey`, `sStripeLiveKey` added to `metadata.php` with sensible labels and help text.
- [ ] `WebhookEndpointRegistrarInterface::register()` and `StripeWebhookEndpointApiInterface::create()` accept `bool $isConnect = false`, with one new unit test each covering the pass-through.
- [ ] `StripeConnect::stripeCreateWebhookEndpoint()` action implemented, with 5 unit tests written RED first.
- [ ] `tryRegisterWebhook()` and its caller removed from `StripeConnect`. Obsolete Sprint 109 tests removed.
- [ ] `stripe_connect.html.twig` shows the new "Webhook setup" section with status, button, and inline XHR.
- [ ] Lang keys added (EN + DE) and obsolete Sprint 109 keys removed.
- [ ] `./bin/pre-commit-check.sh --full` green.
- [ ] Live smoke per §6 Step 9 passes against a real Stripe test account.
- [ ] Sprint 109 cleanup verification grep (§3.6) produces empty output for all three patterns.
- [ ] PR description includes: link to Sprint 109's completion report, screenshot of the new button, screenshot of the Stripe Dashboard Connect-webhook entry, brief note on the architectural shift (auto → manual button).

## 9. Files & LOC budget

| Area | Production LOC | Test LOC |
|---|---|---|
| Adapter changes (`StripeWebhookEndpointApi*`) | +6 | 0 |
| Registrar changes (`WebhookEndpointRegistrar*`) | +4 | +20 (1 new test) |
| `ModuleConfigurationService::getPlatformKey` | +8 | +15 (1 new test for the getter) |
| `StripeConnect` action + helpers (new in §6 Step 5) | +60 | +180 (5 new tests) |
| **Sprint 109 cleanup** (§3.1 — code) | −25 | 0 |
| **Sprint 109 cleanup** (§3.2 — tests) | 0 | −150 |
| **Sprint 109 cleanup** (§3.3, §3.4 — lang + template) | −15 | 0 |
| Template + lang + metadata (new content) | +50 | 0 |
| **Net** | **~+88** | **~+65** |

Target: ~150 net LOC. Single reviewer should read it in 20–25 minutes. The cleanup rows are split out explicitly so the reviewer can see the deletions aren't hidden inside the "edited files" entries.

## 10. Out of scope (explicitly deferred)

- **Webhook deletion on module uninstall.** Same call as Sprint 109's plan §10 — admin owns the Stripe Dashboard, we don't delete behind their back.
- **Multiple webhook endpoints per shop.** The data model is one ID + one secret per mode. If multi-endpoint support is ever needed (it isn't today), it's a separate sprint.
- **Auto-discovery of an existing webhook on the platform account.** If the admin already has a Connect webhook pointed at this shop URL, our `create` call will produce a duplicate. Stripe accepts this; admin can clean up via the Dashboard. We could `list` and detect, but that's noise we don't need yet.
- **Restricted API key flow.** Stripe supports issuing API keys scoped to webhook management only — more secure than handing the module a full platform key. Sprint plan-able if security review demands it; not in this sprint.
- **The OXID middleware OAuth flow itself.** Still out of scope. Sprint 109 already documented this.

---

**Status:** ⏸ awaiting approval. No code will be written until approved.

**Approval needed on:**
1. The architectural shift: dropping auto-registration in favor of a manual button + manually-pasted platform key (matches the old module's behavior; documented above as the only viable path with Stripe Connect's permission model).
2. The decision to keep the platform key as a `str` setting in `metadata.php` rather than introducing per-mode encrypted storage — same posture as the old module, same posture as `sStripeTestToken`. Encrypted storage is a separate, broader sprint.
3. The JSON envelope shape (`{success, endpointId?, message?}`) — flatter than the old module's `{code, status, body}`. Confirm before committing the contract.
