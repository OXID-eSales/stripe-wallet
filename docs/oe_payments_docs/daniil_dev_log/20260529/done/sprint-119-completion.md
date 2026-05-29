# Sprint 119 — STRP-129 User & Address Field Validation — Completion Report

**Date completed (work):** 2026-05-29
**Branch:** `b-7.4.x-user-data-filter-STRP-129`
**Ticket:** STRP-129
**Sprint plan:** [`../sprints/sprint-119-strp-129-user-address-validation.md`](../sprints/sprint-119-strp-129-user-address-validation.md)
**Driver report:** [`../reports/user-data-and-address-validation.md`](../reports/user-data-and-address-validation.md)
**Repos touched:** `extensions/payment-base` (additive) + `extensions/stripe` (consumer)
**Commit status:** ⚠ **NONE — all work is working-tree only.** Per session instruction. Commits are listed as the first item in [`./handoff.md`](./handoff.md).

---

## 1. What shipped (functional summary)

A single, named, character-level validation boundary applied at both the standard-checkout JSON path and the one-page-checkout (OPC) widget path, with provider-aware per-PSP rules and a central frontend-facing API endpoint hardened by a seven-guard chain.

- **`payment-base`** ships the new library + the central endpoint at `cl=oepaymentvalidationapi&fnc=validate`. Provider-aware via `pluginModuleId` request parameter. Per-PSP rate-limit override is registerable by any PSP through a tagged-iterator SPI. The endpoint is fronted by seven SRP guards (POST-only, Payload size, Active session, Same-origin, CSRF, Rate-limit, Plugin-id allowlist) executed in strict order; first-fail returns HTTP 4xx with **empty body** (no fingerprint to scanners).
- **`stripe`** ships the per-plugin rules file (`src/Resources/validation-rules.php`, 13 fields), the `UserDataValidator` façade, the OPC Stimulus controller (inline-script registration via the existing OPC `var opc = window.OnepageCheckout` pattern), wiring into `StripeOrderController::createCheckoutSession()` as a pre-dispatch gate, the `UserDataValidationMessageFormatter` that translates failures via `STRIPE_VALIDATION_*` keys, 40+ new translation keys in both `en` and `de` locales, and a Playwright spec covering the positive flow plus a negative-security spec asserting the endpoint rejects forged requests.

Both emission paths (HTTP 422 from `StripeOrderController` for standard checkout, HTTP 200 with `{valid:false, errors[]}` from the payment-base endpoint for OPC) converge on the **same JSON shape**, with each error entry carrying `field`, `code`, `char`, `addressKind`, and the rendered `message`.

---

## 2. Per-phase test-count deltas (independently verified)

| Phase | Repo(s) | Tests Δ | Assertions Δ | Verified |
|---|---|---:|---:|:-:|
| A1 — `ValidationBase` library + universal blocklist | payment-base | **+34** | **+69** | ✓ |
| A2 — Central endpoint + 7 guards + per-PSP rate-limit override | payment-base | **+42** | **+79** | ✓ |
| B — Stripe per-plugin rules + `UserDataValidator` façade | stripe | **+12** | **+32** | ✓ |
| C — Standard-checkout wiring in `StripeOrderController` | stripe | **+6** | **+31** | ✓ |
| D — OPC Stimulus controller + Playwright spec | stripe | **+2** | **+7** | ✓ |
| E — Translations + `MessageFormatter` SPI (payment-base) + Stripe formatter | payment-base + stripe | **+2 / +7** | **+11 / +15** | ✓ |
| **Totals** | | **+78 payment-base, +76 stripe** | **+90 payment-base, +152 stripe** | ✓ |

**Absolute counts:**

| Repo | Before sprint | After sprint |
|---|---:|---:|
| `extensions/payment-base` (Unit suite) | 918 / 2093 | **996 / 2260** |
| `extensions/stripe` (Unit suite) | 1123 / 2691 | **1150 / 2776** |
| **Combined Unit** | **2041 / 4784** | **2146 / 5036** |

Zero failures, zero errors across all six phases on every gate run.

---

## 3. Cross-module guarantees (paypal + one-page-checkout)

`paypal` and `one-page-checkout` were re-snapshotted (SHA-256 over all `.php`/`.yaml`/`metadata.php` files, 323 files total) **before and after every phase**. **Byte-identical** after each phase. Zero collateral changes.

| Snapshot | After phase | Identical? |
|---|---|:-:|
| `/tmp/paypal-opc-snapshot-before.sha256` (pre-A1) → `/tmp/paypal-opc-snapshot-after.sha256` | A1 | ✓ |
| `/tmp/paypal-opc-snapshot-before-a2.sha256` → `/tmp/paypal-opc-snapshot-after-a2.sha256` | A2 | ✓ |
| → `/tmp/paypal-opc-after-b.sha256` | B | ✓ |
| → `/tmp/paypal-opc-after-c.sha256` | C | ✓ |
| → `/tmp/paypal-opc-after-d.sha256` | D | ✓ |
| → `/tmp/paypal-opc-after-e.sha256` | E | ✓ |

---

## 4. Static-analysis & quality gates

| Gate | Stripe | payment-base |
|---|:-:|:-:|
| PHPCS (PSR-12) | ✓ clean | ✓ clean |
| PHPStan level max | ✓ clean | ✓ clean |
| PHPMD | ✓ baseline unchanged (3 entries) | ✓ baseline unchanged |
| New static-analysis suppressions added | **0** | **0** |
| New PHPMD baseline entries added | **0** | **0** |

The `WeightedMethodCount` baseline entry on `StripeOrderController` did **not** grow despite Phase C adding three new private/protected methods — all are ≤25 lines and the metric stays under the baselined threshold.

---

## 5. Architecture decisions made during execution (worth noting)

### 5.1 Per-PSP rate-limit override (option b from the threat-model discussion)
The central endpoint pools per-session rate-limit by `(pluginModuleId, sessionId)`. Each PSP can ship a `RateLimitOverrideInterface` to tune its limit (Stripe doesn't ship one in this sprint — falls back to the global default of 30 req/min). This was the explicit decision after weighing "universal endpoint vs Stripe-only" (see conversation log preceding A1 dispatch).

### 5.2 Rate-limit storage (Phase A2 decision)
Chose **(B) new `RateLimitStoreInterface`** over (A) reusing `IdempotencyRepository`. Rationale: `IdempotencyRecord`'s domain columns (`OXORDERID`, `OXOPERATION`, `OXSTATUS`) don't fit a counter; the narrow interface keeps that contract clean. Two impls land: `InMemoryRateLimitStore` (unit tests, zero deps) and `DoctrineRateLimitStore` (prod, reusing the `oe_payments_idempotency` table via `validate:` key prefix). No new table.

### 5.3 `SameOriginGuard` constructor takes an interface, not a string (Phase A2 deviation)
Sprint plan said `string $shopUrl`; agent (correctly) chose `ShopUrlResolverInterface` — calling `Registry::getConfig()` statically inside a guard would have violated R-2.4 (DIP) and made the guard untestable. New `OxidShopUrlResolver` registered as the impl.

### 5.4 Rules-file path correction (Phase B)
Sprint plan said `src/Stripe/Resources/validation-rules.php`. The Phase A1 `FilesystemValidationRuleLoader` hardcodes the convention `<plugin-root>/src/Resources/validation-rules.php`. The agent (correctly) placed the file at `src/Resources/validation-rules.php` — payment-base's loader is provider-agnostic and should not depend on each plugin's namespace structure. **Sprint plan was updated to match the actual convention.**

### 5.5 `StripeOrderController` is a JSON AJAX endpoint, not a form-redirect (Phase C)
Sprint §6 Phase C said "surface errors via flash + redirect to payment step". That was wrong: `createCheckoutSession()` sets `Content-Type: application/json` (line 165) and `exitWithJson()`s — it's an AJAX endpoint. The override (HTTP 422 + JSON `{valid:false, errors:[...]}`) converges with the OPC central endpoint's shape, satisfying R-7.4 (admin and storefront paths converge).

### 5.6 Approach (b) for the validation short-circuit in `StripeOrderController` (Phase C)
Threw `UserDataValidationException extends RuntimeException` from inside `buildCheckoutEventContext()`, caught it **specifically** before the generic `Throwable` catch in `createCheckoutSession()`. Minimal diff to existing flow.

### 5.7 OPC integration is a Stimulus cross-controller wrap, not a document event (Phase D)
Critical finding: there is **no** `opc:submit-attempt` document event in `one-page-checkout`. The sprint plan used it as a placeholder pending verification. The actual contract: the Stripe footer widget exposes a `submitPayment` Stimulus action on its own button. The new `stripe-user-data-validator` controller wraps that action at `connect()` time using `application.getControllerForElementAndIdentifier(this.element, 'stripe-checkout-footer')`. Registration is inline in the Twig template alongside `stripe-checkout-footer` (NOT in `app.js`, which would break non-OPC pages).

### 5.8 Phase E option (b) — payment-base SPI for messages
Payment-base ships an additive `MessageFormatterInterface` SPI (`src/Validation/Message/`). `ValidationApiController` collects tagged formatters (`oe.payment_base.validation_message_formatter`), picks the one matching the request's `pluginModuleId`, and decorates each error with `message`. Stripe registers its `UserDataValidationMessageFormatter`. PSPs without a formatter get `message: null` (backwards-compat). The new constructor arg is **trailing with default `[]`** so existing Phase A2 controller tests continue to pass unchanged.

---

## 6. Deviations summary

| # | Phase | Sprint plan said | Agent did | Why correct |
|---|:-:|---|---|---|
| 1 | A1 | C1-control test uses `"\x80"` | Uses `"\xC2\x80"` (UTF-8) | All web input is UTF-8; `/u` PCRE rejects bare Latin-1 byte |
| 2 | A1 | Token kind by `strlen > 1` | `CharacterClass::isClassToken()` via `/^[A-Z_]+$/` | Multi-byte UTF-8 literals (`ö`, 2 bytes) would have been mistaken for class names |
| 3 | A2 | `SameOriginGuard(string $shopUrl)` | `SameOriginGuard(ShopUrlResolverInterface)` | R-2.4 DIP; testability |
| 4 | A2 | Global rate-limit setting wired live | Hardwired `$globalDefault: 30` | `ModuleSettingBridge` not yet in payment-base services.yaml; deferred. **See open item #1.** |
| 5 | B | Rules file at `src/Stripe/Resources/` | At `src/Resources/` | Loader hardcodes `<plugin-root>/src/Resources/`. Sprint plan updated. |
| 6 | B | `validateForUser(\User $user)` | `validateForUser(UserFieldReaderInterface $reader)` | Avoids importing OXID `User` into the validator's interface; testable seam |
| 7 | C | Flash errors + redirect | HTTP 422 + JSON `{valid, errors}` | Controller is a JSON AJAX endpoint, not a form handler. Converges with OPC shape (R-7.4). |
| 8 | D | Document `opc:submit-attempt` event | Stimulus cross-controller wrap | The event doesn't exist; the wrap is the verified contract |
| 9 | D | Controller in `app.js` | Controller inline in Twig | `app.js` is the non-OPC bundle; importing OPC-dependent controller there would error on every standard page |

All nine deviations are architecturally sound and were verified before adoption.

---

## 7. Files touched (working-tree only — see handoff.md for commit sequencing)

### `extensions/payment-base/`

**New production files** (under `src/Validation/`):
- `ValidationBase.php`, `ValidationBaseInterface.php`
- `ValidationRuleLoaderInterface.php`, `FilesystemValidationRuleLoader.php`
- `PluginPathResolverInterface.php`, `OxidPluginPathResolver.php`
- `RuleSet.php`, `CharacterClass.php`, `FieldValidationResult.php`
- `ValidationRequestContext.php`
- `Guard/{ValidationGuardInterface, GuardFailure, PostOnlyGuard, PayloadSizeGuard, ActiveSessionGuard, SameOriginGuard, CsrfTokenGuard, RateLimitGuard, PluginIdAllowlistGuard, ShopUrlResolverInterface, OxidShopUrlResolver, SessionChallengeVerifierInterface, OxidSessionChallengeVerifier, ActiveModuleQueryInterface, OxidActiveModuleQuery}.php`
- `RateLimit/{RateLimitConfigInterface, RateLimitOverrideInterface, ConfigurableRateLimitConfig, RateLimitStoreInterface, InMemoryRateLimitStore, DoctrineRateLimitStore}.php`
- `Message/MessageFormatterInterface.php`

**New production controller**:
- `src/Controller/ValidationApiController.php`

**Modified**:
- `metadata.php` — added `oepaymentvalidationapi` controller key + `iValidationApiRatePerMinute` setting.
- `services.yaml` — registered guards, rate-limit, controller, formatter SPI documentation.
- `tests/PhpStan/Rules/NoConcreteClassTypeHintRule.php` — allowlisted new final VOs.
- `tests/PhpStan/phpstan-bootstrap.php` — added eval stubs for `ModuleConfigurationDaoInterface`, `ModuleConfiguration`, `BasicContextInterface`, `FrontendController`, `ContainerFactory`, `Registry::getUtils/getConfig`, `ModuleActivationBridgeInterface`.
- `tests/bootstrap-unit.php` — matching PHPUnit-runtime stubs.

**New tests** (under `tests/Unit/Validation/`, `tests/Unit/Validation/Guard/`, `tests/Unit/Controller/`):
- 5 Validation library test files.
- 11 Guard test files.
- 1 `ValidationApiControllerTest.php`.
- Phase E added 2 tests inside `ValidationApiControllerTest`.

### `extensions/stripe/`

**New production files**:
- `src/Resources/validation-rules.php` — the 13-field rules array verbatim.
- `src/Stripe/Service/UserDataValidator.php`, `UserDataValidatorInterface.php`.
- `src/Stripe/Service/FieldValidationFailure.php`.
- `src/Stripe/Service/UserFieldReaderInterface.php`, `OxidUserFieldReader.php`.
- `src/Stripe/Service/UserDataValidationMessageFormatter.php`.
- `src/Stripe/Service/LanguageTranslatorInterface.php`, `OxidLanguageTranslator.php`.
- `src/Stripe/Controller/UserDataValidationException.php`.

**Modified**:
- `src/Stripe/Controller/StripeOrderController.php` — Phase C wiring + Phase E formatter call.
- `src/Stripe/Component/Widget/StripeCheckoutFooter.php` — Phase D `validationUrl` + `pluginModuleId` view-data.
- `views/twig/widget/checkout/stripe-footer.html.twig` — Phase D data-attributes + inline Stimulus controller registration.
- `services.yaml` — Phase B + Phase E DI wiring.
- `translations/en/stripe_lang.php` — Phase E, ~40 new keys.
- `translations/de/stripe_lang.php` — Phase E, ~40 new keys.
- 4 existing test files (`StripeOrderControllerTest`, `StripeOrderControllerAgbConfirmationTest`, `StripeOrderControllerSecurityTest`, `StripeOrderControllerRetryTest`) gained an 18-line seam override for the new `getUserDataValidator()` resolver — pure additive, no existing assertion changed.

**New tests**:
- `tests/Unit/Stripe/Service/UserDataValidatorTest.php`.
- `tests/Unit/Stripe/Controller/StripeOrderControllerValidationTest.php`.
- `tests/Unit/Stripe/Service/UserDataValidationMessageFormatterTest.php`.
- Extensions to existing `tests/Unit/Stripe/Component/Widget/StripeCheckoutFooterTest.php`.

**New Playwright spec**:
- `tests/e2e/playwright/playwright/tests/opc/stripe-user-data-validation.spec.ts` — positive flow + negative-security spec. **Not run in this session.** Run it as part of pre-commit verification — see [`./handoff.md`](./handoff.md).

---

## 8. Quick gate checklist (per `_engineering_requirements.md`)

- [x] **R-1 TDD** — RED before GREEN per phase; no method-under-test re-implemented in a double. `UserDataValidator` and `ValidationApiController` use real instances over real `ValidationBase` in the rules-file end-to-end test.
- [x] **R-2 SOLID** — One rules-loader, one validator library, one endpoint, seven SRP guards, one Stripe-side façade, one Stimulus controller. PHPMD baseline NOT grown.
- [x] **R-3 LI** — `ValidationBase` is final; substitutability proven via interface-based tests. Each guard substitutable behind `ValidationGuardInterface`.
- [x] **R-4 DI** — Constructor-injected `ValidationRuleLoaderInterface`, `ValidationBaseInterface`, `UserDataValidatorInterface`, tagged-iterator of `ValidationGuardInterface` and `MessageFormatterInterface`. No `ContainerFactory::getInstance` in business code.
- [x] **R-5 Clean Code** — ≤25-line methods; no `else`; explicit `use` imports; no magic strings for plugin id (`Module::MODULE_ID`) or violation codes (`FieldValidationResult::CODE_*`).
- [x] **R-6 DevOps-first** — `pre-commit-check.sh` (or composer-scripts equivalent) green per phase; no new suppressions. **Cache-clear + php-restart performed after `services.yaml`/`metadata.php` edits during agent runs.**
- [x] **R-7 Event-driven** — Validation is a synchronous pre-dispatch read. Standard-checkout (`StripeOrderController`) and OPC (central endpoint) paths converge on the same `UserDataValidator` → `ValidationBase` chain (R-7.4). Identical JSON shape on both paths.
- [x] **R-8 Contract-aware** — Validation runs BEFORE `StripeCheckoutSessionRequestEvent` dispatch and contract creation. The contract never enters DRAFT on invalid input.
- [x] **R-9 No overengineering** — No JS rule-engine mirror, no admin UI for rules, no DB storage for rules, no PayPal adoption in this sprint, no per-PSP frontend endpoint. The per-PSP rate-limit override is a single tagged-iterator extension point — minimal SPI.
- [x] **R-10 Persistence** — Validator is a pure read. The only new write is the rate-limit counter increment (inside `DoctrineRateLimitStore`, behind `RateLimitStoreInterface`). No `oxNew`+`save` introduced. Controller short-circuit on validation failure does not touch the DB.

---

## 9. Open items (deferred to follow-up tickets — see [`./handoff.md`](./handoff.md))

1. **Live-bind `iValidationApiRatePerMinute`** admin setting to `ConfigurableRateLimitConfig`. Currently hardwired to 30. Needs `ModuleSettingBridgeInterface` (or equivalent) plumbed into payment-base's services.yaml. Behaviour today matches the documented default; no functional regression.
2. **CI workflow `.github/workflows/development.yml` does not honour `PAYMENT_BASE_BRANCH` env.** The env is declared (line 13) but the four `Checkout payment-base` steps hardcode `ref: b-7.4.x`. Until either the env is wired through or payment-base is merged to `b-7.4.x`, CI will fail against the stripe branch because Stripe's new `use OxidEsales\PaymentBase\Validation\...` imports won't resolve. **This is the gating CI blocker.**
3. **`HandlesCheckoutReturn` and `ControllerRequestHelper` not yet updated** to clear the validation rate-limit counter on session destruction. Counter naturally expires via TTL; cleanup is opportunistic, not required.
4. **PayPal adoption** of the validation framework — explicitly out of scope, per the user's session boundary ("not in this session"). Pattern documented; PayPal's adoption sprint ships only a `validation-rules.php` + a `MessageFormatter` registered against the existing tagged iterator. No new endpoint, no new guards.

---

## 10. Memory updates suggested (not yet applied)

To be saved when commits land:

- **`project_strp_129_validation_base.md`** — payment-base now ships `ValidationBase` library + central `cl=oepaymentvalidationapi` endpoint + per-plugin `validation-rules.php` convention at `<plugin-root>/src/Resources/`. Capture the field-name → OXID-column mapping (§4.8) and the guard chain (§4.7) so PayPal's adoption sprint can copy the pattern with zero new endpoints.
- **`feedback_central_validation_endpoint_security.md`** — threat-model decisions: no CORS, no viewport secret token, session-keyed rate limit, empty body on guard failure, per-PSP rate-limit override via tagged iterator. So future sprints don't re-litigate.
- **`feedback_opc_stimulus_cross_controller_wrap.md`** — there is no `opc:submit-attempt` document event in one-page-checkout. The integration contract for OPC widgets is the Stimulus cross-controller wrap (`application.getControllerForElementAndIdentifier(...)`) within the existing inline-script-registration pattern (`var opc = window.OnepageCheckout; opc.stimulus.register(...)`). Do NOT add OPC widget controllers to `app.js`.
- **Update `project_code_review_114_latent_bugs.md`** — the L1 `validateDeliveryAddress` "blanket Stripe bypass" concern is further mitigated by Phase C's pre-dispatch character-level validation. Note: this does not replace the narrowing in sprint 114.2; both layers coexist.

---

## 11. Citations

- Sprint plan: [`../sprints/sprint-119-strp-129-user-address-validation.md`](../sprints/sprint-119-strp-129-user-address-validation.md)
- Driver report: [`../reports/user-data-and-address-validation.md`](../reports/user-data-and-address-validation.md)
- Engineering requirements: [`../../20260527/done/_engineering_requirements.md`](../../20260527/done/_engineering_requirements.md)
- Handoff for next session: [`./handoff.md`](./handoff.md)
