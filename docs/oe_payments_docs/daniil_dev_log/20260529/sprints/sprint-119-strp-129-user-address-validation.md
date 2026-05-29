# Sprint 119 — STRP-129 User & Address Field Validation (payment-base `ValidationBase` + Stripe plugin rules)

**Module:** `extensions/stripe` (consumer) + `extensions/payment-base` (additive)
**Ticket:** STRP-129
**Branch:** `b-7.4.x-user-data-filter-STRP-129` (already created off `b-7.4.x` at `12cb786`; empty so far)
**Mode:** TDD-first. Multi-commit, per-phase. ≈ 6 reviewable commits (A1, A2, B, C, D, E).
**Driver report:** [`../reports/user-data-and-address-validation.md`](../reports/user-data-and-address-validation.md)
**Engineering requirements:** [`../../20260527/done/_engineering_requirements.md`](../../20260527/done/_engineering_requirements.md) — **R-1…R-10 binding** on every commit. Key here: **R-1** (RED before GREEN, real SUT), **R-2** (SRP — one validator class, one rules loader, one rules file per plugin), **R-3** (ValidationBase is final; provider/loader interfaces are honored substitutably), **R-4** (constructor-injected loader interface; no `Registry::get*` in the new code), **R-5** (≤25-line methods, explicit imports, no else, no magic strings), **R-6** (`pre-commit-check.sh --full` green per commit; cache cleared + php restarted), **R-9** (no speculative JS port of the rules engine — backend is the source of truth; only the data flow the spec calls out gets built).

---

## 1. Why

The driver report establishes the gap precisely: **at checkout there is exactly one user-data rule today — `trim() + non-empty` via OXID's `RequiredFieldValidator`.** No format / regex / per-field allowlists anywhere on the Stripe boundary. `CustomerDataSanitizer` is a stripping filter for email + concatenated name; it does not reject, only normalises.

STRP-129's deliverable is a **single, named, per-field validation boundary** that fires on both the standard checkout path (after the user clicks "Checkout") and the one-page-checkout (when the Stripe footer widget loads / on its submit). The validator is provider-aware (it takes a plugin module id) so PayPal and any future provider can adopt the same machinery in a follow-up session without code-duplication — the implementation lives in **`payment-base`** as `ValidationBase`, the per-field rules live **per-plugin** as a returned `array` from a single PHP file.

**Frontend validation is exposed via a single central API endpoint owned by `payment-base`**, NOT by each PSP module. Reason: only one URL needs hardening; every viewport (OPC, Apex theme, any future storefront skin or PSP-provided widget) calls the same endpoint with the plugin module id as a request parameter. The endpoint is hardened against external abuse (POST-only, CSRF token, same-origin Origin/Referer, active OXID session required, per-session rate-limit, small payload cap, plugin-id allowlisted to *activated* modules) so the URL cannot be triggered by scripted external traffic to overload the system.

This sprint is also the first piece of the report's recommendation R1 (a "`UserAddressInputFilter`-equivalent service at the Stripe boundary") — but realised one layer deeper (payment-base) and one layer wider (cross-provider) than the report originally framed, because the user explicitly asked for the unified `ValidationBase` API.

## 2. Goals

- **G1.** New `payment-base` class `ValidationBase` implementing `ValidationBaseInterface`. Public API:
  ```php
  $validationBase = new ValidationBase('oe_payments_stripe_wallet', $ruleLoader);
  $result = $validationBase->validateField('firstName', $value);   // -> FieldValidationResult
  ```
  Constructor signature (R-4): `(string $pluginModuleId, ValidationRuleLoaderInterface $loader)`. Direct `new` is OK because `$loader` is the injected dep; the literal call shape the user spec uses is preserved.
- **G2.** A single per-plugin rules file at `src/Resources/validation-rules.php` returning the `['fields' => [{field, rules}, …]]` array exactly as the user specified — space-separated character strings + named character classes (see §5).
- **G3.** A **universal cross-field blocklist** built into `ValidationBase` itself, applied **before** per-field rules: tabs (`\t`), CR/LF (`\r`, `\n`), null bytes (`\0`), zero-width / invisible Unicode (`U+200B`, `U+200C`, `U+200D`, `U+FEFF`, `U+00AD`, `U+2060`), other C0/C1 control characters (`\x00–\x1F` minus space, `\x7F`, `\x80–\x9F`). Any field carrying any of these fails immediately, regardless of allow/block.
- **G4.** Backend wiring on the **standard checkout** path. Validation runs in `StripeOrderController::createCheckoutSession()` after CSRF + AGB + `ConfigurationValidator`, **before** the `OrderCreatedEvent` dispatch. On failure: the controller does NOT proceed to the Stripe-checkout-session adapter call; the user is returned to the `payment` step with field-level error messages.
- **G5.** Backend wiring on the **one-page-checkout (OPC)** path. The Stripe footer widget (`StripeCheckoutFooter`) ships a new Stimulus controller `stripe-user-data-validator` that, on submit-attempt of the OPC form, POSTs the live form fields to the **central payment-base endpoint** (G5.5 below) with `pluginModuleId=oe_payments_stripe_wallet`, and renders the JSON-returned per-field errors inline. Backend re-uses the **same** `ValidationBase` instance — no rule duplication. If the backend says invalid, the OPC submit is blocked.
- **G5.5.** **One central frontend-facing endpoint owned by `payment-base`** at `cl=oepaymentvalidationapi&fnc=validate` (controller key `oepaymentvalidationapi`). Accepts `pluginModuleId`, a flat field-map, and the session challenge token. Returns the same `{valid, errors[]}` JSON shape used by Phase D. Hardened by a chain of guards (see §4.7): POST-only, CSRF (`Session::checkSessionChallenge`), same-origin (Origin / Referer must match shop URL), active OXID session required, per-session rate-limit (≤ 30 requests / minute), payload-size cap (4 KiB body, ≤ 32 fields per request), plugin-id allowlist (must be in the active modules set). On any guard failure: HTTP 4xx with an empty body — no signal to scanners about which guard tripped.
- **G6.** **No JS port of the rule engine.** The widget receives ONLY the publishable key + the validation endpoint URL via the existing `stripeConfig` tplparam shape; the JS sends raw form values and renders the backend's response. (R-9 — defer a client-side mirror until UX explicitly asks for it.)
- **G7.** User-friendly error messages — every per-field violation surfaces a translated message keyed by `{pluginId}.validation.{fieldName}.{violationCode}` (e.g. `oe_payments_stripe_wallet.validation.firstName.disallowed_character`). Translations land in `views/translations/lang.php` per locale. The error payload also carries the actual offending character (for the message) and the human-friendly field label.
- **G8.** `./bin/pre-commit-check.sh --full` green per commit. **paypal + one-page-checkout** suites byte-identical (assertion counts) before and after the payment-base touches.

## 3. Out of scope (explicit)

| Item | Why deferred |
|---|---|
| PayPal-side adoption of `ValidationBase` | Per the user spec: "SAme can do also paypal plugin but not in this session". A separate, copy-the-pattern sprint after STRP-129 lands. |
| Client-side mirror of the rule engine in JS | R-9: backend is source of truth; the OPC widget does an AJAX call. Mirror only if UX demands offline / pre-blur feedback. |
| Country-specific VAT-format check / Stripe-currency-minimum pre-flight | Mentioned in the driver report as G4/G5 risks; separate sprint scope (those are *amount/currency* checks, not user-data character checks). |
| Re-validation of `aPaymentCountries` / `aPaymentCurrencies` filter on the backend | Driver report G4 — separate sprint, orthogonal to character-level field validation. |
| Filling `billing_details.address` on the dormant `PaymentMethodHelper` wiring | Driver report G2 — defer until that wiring is actually populated by a real flow. STRP-129 closes the *input* validation gap; that gap is the *output* gap. |
| Editing OXID-core registration-time `InputValidator` | The report records the Stripe code does not use it and that is intentional. This sprint does not change that. |
| Storing rules in DB / admin-editable rule UI | Out of spec; PHP-file is explicitly requested. |

## 4. Architecture

### 4.1 Component map

```
extensions/payment-base/                  (additive — backwards-compatible)
├── src/
│   ├── Validation/
│   │   ├── ValidationBaseInterface.php           (NEW — public contract)
│   │   ├── ValidationBase.php                    (NEW — final class)
│   │   ├── FieldValidationResult.php             (NEW — value object: bool valid, ?string violation, ?string offendingChar, string code)
│   │   ├── ValidationRuleLoaderInterface.php     (NEW)
│   │   ├── FilesystemValidationRuleLoader.php    (NEW — resolves <plugin>/Resources/validation-rules.php via OXID ModulePathResolver)
│   │   ├── CharacterClass.php                    (NEW — final, static helpers: isUnicodeLetter, isLetter, isNumber, isSpace; plus the universal-blocklist matcher)
│   │   └── RuleSet.php                           (NEW — internal VO parsed from the rules array: allowTokens[], blockTokens[], allowClasses[])
│   ├── Controller/
│   │   └── ValidationApiController.php           (NEW — cl=oepaymentvalidationapi; single fnc=validate; thin façade running the guards then ValidationBase)
│   └── Validation/Guard/                         (NEW dir — request gates, each guard a single-responsibility ~30 LOC class)
│       ├── ValidationGuardInterface.php          (NEW — public function check(RequestContext): ?GuardFailure)
│       ├── PostOnlyGuard.php                     (NEW — rejects non-POST)
│       ├── CsrfTokenGuard.php                    (NEW — Session::checkSessionChallenge)
│       ├── SameOriginGuard.php                   (NEW — Origin / Referer must match shop URL)
│       ├── ActiveSessionGuard.php                (NEW — OXID session must exist; no session = no validation)
│       ├── RateLimitGuard.php                    (NEW — per-session sliding window; configurable, default 30 req / minute)
│       ├── PayloadSizeGuard.php                  (NEW — body ≤ 4 KiB, ≤ 32 fields)
│       └── PluginIdAllowlistGuard.php            (NEW — pluginModuleId must be an installed + active OXID module)
├── metadata.php                                  (EDIT — register controllers['oepaymentvalidationapi'])
└── services.yaml                                 (EDIT — register ValidationApiController + guards + ValidationBase + loader; guards composed via tagged iterator `oe.payment_base.validation_guard`)

extensions/stripe/                        (consumer)
├── src/Stripe/
│   ├── Resources/                                # at src/Resources/ (payment-base loader resolves `<plugin-root>/src/Resources/validation-rules.php`)
│   │   └── validation-rules.php                  (NEW — return [...] per §5)
│   ├── Service/
│   │   └── UserDataValidator.php                 (NEW — thin Stripe-side façade: holds the ValidationBase instance, maps oxuser__ fields to logical field names, returns aggregate FieldValidationResult[])
│   ├── EventSystem/
│   │   └── (no new handlers — see §4.4 for placement decision)
│   └── Component/Widget/
│       └── StripeCheckoutFooter.php              (EDIT — add validationUrl + pluginModuleId to stripeConfig tplparam, pointing at the payment-base endpoint)
├── views/twig/widget/checkout/
│   └── stripe-footer.html.twig                   (EDIT — emit data-validation-url, data-plugin-id, data-controller="stripe-user-data-validator")
├── views/translations/lang.php (per locale)      (EDIT — add validation strings)
├── assets/js/controllers/
│   └── stripe-user-data-validator-controller.js  (NEW — Stimulus controller; ~80 LOC; POSTs to payment-base endpoint)
└── tests/                                        (NEW — see §6)
```

**No Stripe-side controller is added.** The OPC endpoint is owned by payment-base; Stripe is a pure consumer of the URL.

### 4.2 Universal blocklist (built into `ValidationBase`, applied first)

Reject any field value containing **any** of:

| Class | Code points / regex |
|---|---|
| Tab | `U+0009` |
| Line break | `U+000A`, `U+000D` |
| Null byte | `U+0000` |
| C0 control | `U+0001 – U+001F` minus space (already covered by tab/LF/CR) and **excluding** none — all rejected |
| DEL | `U+007F` |
| C1 control | `U+0080 – U+009F` |
| Zero-width / invisible | `U+200B`, `U+200C`, `U+200D`, `U+FEFF`, `U+00AD`, `U+2060` |

Implementation hint: a single multibyte-aware regex inside `CharacterClass::hasUniversalReject(string $value): ?string` returning the offending character (or `null` if clean). PCRE pattern (escaped for PHP):
```
/(?P<bad>[\x00-\x1F\x7F\x80-\x9F\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}\x{2060}])/u
```

### 4.3 Per-field rule grammar

A field's `rules` array carries `allow` and optional `block`, each a **space-separated** string. Two kinds of tokens:

| Token kind | Examples | Meaning |
|---|---|---|
| **Class token** (uppercase reserved) | `UNICODE_LETTERS`, `LETTERS`, `NUMBERS`, `SPACES` | a Unicode-category match. `UNICODE_LETTERS` = `\p{L}`. `LETTERS` = ASCII `[A-Za-z]`. `NUMBERS` = `\p{N}`. `SPACES` = the regular space character `U+0020` (NOT `\s` — tabs/newlines are universally rejected). |
| **Literal char** | `'`, `-`, `.`, `/`, `#`, `&` | exactly that one character. |

Semantics:
- **`allow` is the strict allowlist.** Every character in the value must match *some* allow token (class OR literal). If not, the field is invalid.
- **`block` is an explicit reject list** applied after the universal blocklist and before allow. A character matched by `block` fails immediately even if it would otherwise match an allow class (e.g. `block: ":"` overrides an `allow: UNICODE_LETTERS …` that happened to include the colon in some peculiar locale).
- **An empty value** is **not** this validator's concern — non-empty enforcement remains OXID's `RequiredFieldsValidator` job. `ValidationBase::validateField` returns `valid=true` for `''` and `null`; the empty case is filtered upstream by the existing required-field check.

### 4.4 Where validation fires on the standard-checkout path

The driver report identifies the precise pre-Stripe dispatch site: `StripeOrderController::createCheckoutSession()` between `validateCheckoutPreconditions()` and the `OrderCreatedEvent` dispatch. **Validation lives there**, not in `StripeContractCreationHandler`, because:

1. By R-10 (persistence: writes via event→service→repository) the validator is a **read** that gates a **dispatch**, not a write. The controller is the correct location.
2. Failure must short-circuit the dispatch (no contract / no order / no Stripe call) — putting it inside the handler would require the handler to throw and rely on caller-side catch, which leaks the failure path through the event bus.
3. The standard-checkout button's POST lands at this controller — the lifetime of a request is short and predictable here, perfect for a synchronous validation.

A new private method `validateUserData(): array` is added to `StripeOrderController`. It returns the array of `FieldValidationResult` failures (empty array = pass). On non-empty: the controller calls `$this->addUserDataValidationErrors($failures)` (sets per-field flash errors via OXID's `Registry::getSession()->setVariable('Errors', …)` shape that the storefront templates already render) and returns to the `payment` step **without** clearing `stripe_skip_addr_check` (the flag was never set yet — we are pre-dispatch). The dispatch is skipped entirely.

### 4.5 Where validation fires on the OPC path

OPC submission lands at a different controller chain (one-page-checkout's own `OrderCreate` controller; Stripe's footer widget is a sibling renderer, not the submit target). For OPC the widget's Stimulus controller calls the **central payment-base endpoint** (§4.7) **before** the OPC form actually submits to its own backend.

- Endpoint URL: `index.php?cl=oepaymentvalidationapi&fnc=validate` (owned by `payment-base`, NOT Stripe).
- POST body (form-encoded or JSON — controller accepts both): `pluginModuleId` + `stoken` (CSRF) + one key per logical field name (`firstName`, `lastName`, `street`, …).
- Response: `application/json`:
  ```json
  {"valid": false, "errors": [{"field": "firstName", "code": "disallowed_character", "char": ":", "message": "…"}]}
  ```
- The Stimulus controller hooks the OPC submit button: `event.preventDefault()` → fetch the endpoint → if `valid: false`, render the errors inline next to each field and keep the submit blocked; if `valid: true`, allow native submit to continue.

This converges (R-7.4) both paths on the **same** `UserDataValidator` → `ValidationBase` chain. No rule duplication, no JS engine, **one** frontend-facing URL across all PSPs.

### 4.6 Why the endpoint lives in payment-base (not Stripe)

- **One URL to harden.** Each PSP exposing its own endpoint multiplies the attack surface; one central endpoint means one chain of guards to audit.
- **Provider-agnostic by construction.** Plugin module id is a request parameter, validated against the *active* module set — so OPC, Apex theme, or any other viewport can call it for whichever PSP is currently active.
- **PayPal adoption in a future sprint is zero-controller.** PayPal only needs to ship its own `validation-rules.php` + Stimulus controller call; no new endpoint, no new guards.
- **Avoids R-7.4 drift.** Standard-checkout pre-dispatch calls `UserDataValidator` directly (no HTTP); OPC widget calls the endpoint that internally uses the **same** `UserDataValidator`. Both paths end up at the same code; the endpoint is purely the HTTP adapter for the OPC viewport.

### 4.7 Central frontend-facing endpoint + security gates (payment-base)

**Threat model:** the endpoint reveals the existence of a per-character validation routine. Without gating, an attacker could (a) script millions of POSTs to exhaust DB / CPU / log storage, (b) probe character-by-character to fingerprint the rule set per provider, (c) use the response as an oracle for valid characters per locale. The acceptable risk profile is: only authenticated browsers on the shop's own origin with an active checkout session can call it, at a per-session rate that comfortably exceeds legitimate UX needs (a user filling a 13-field form fires at most a few dozen blur-time validations) but blocks scripted abuse.

**Guard chain (executed in this strict order; first failure short-circuits with HTTP 4xx + empty body):**

| # | Guard | Failure code | Why this order |
|---|---|---|---|
| 1 | `PostOnlyGuard` | 405 Method Not Allowed (no body) | Cheapest; reject scanners hitting GET. |
| 2 | `PayloadSizeGuard` | 413 Payload Too Large | Reject oversize bodies before any parsing. ≤ 4 KiB body. ≤ 32 fields. |
| 3 | `ActiveSessionGuard` | 401 Unauthorized | No `oxsid` / `phpsessid` cookie → endpoint silently ignores. External scripts without a session never even reach CSRF. |
| 4 | `SameOriginGuard` | 403 Forbidden | `Origin` (preferred) or `Referer` (fallback) header must match `Registry::getConfig()->getShopUrl()` (host + scheme). Browsers send `Origin` for cross-origin POSTs; same-origin POSTs from our own templates send `Referer`. **No CORS headers ever set** — the browser same-origin policy is doing half the work; this guard is the explicit server-side counterpart. |
| 5 | `CsrfTokenGuard` | 403 Forbidden | `Session::checkSessionChallenge` against the `stoken` body field. Existing OXID primitive — re-used, not re-implemented. |
| 6 | `RateLimitGuard` | 429 Too Many Requests | Sliding window keyed by `(pluginModuleId, sessionId)`, persisted in `oe_payments_idempotency` table (re-use the existing TTL-keyed store; new key prefix `validate:{pluginModuleId}:{sessionId}:{minuteBucket}`) OR in-process via PSR-16 cache if available. **Per-PSP override knob**: the limit is resolved through `RateLimitConfigInterface::getLimitForPlugin(string $pluginModuleId): int`. Default impl reads (a) any PSP-supplied override registered via the tagged iterator `oe.payment_base.rate_limit_override`, (b) the global payment-base admin setting `iValidationApiRatePerMinute`, falling back to **30 req / 60 s**. Keying by `(pluginModuleId, sessionId)` is intentional: with two active PSPs and a 30/min cap each, total per-session amplification is 2× — still bounded, and the override lets ops tighten this when warranted. |
| 7 | `PluginIdAllowlistGuard` | 422 Unprocessable Entity | `pluginModuleId` must be a **currently activated** OXID module id (query `Container -> ShopConfigurationDao` or the equivalent). Rejects forged ids and ids for non-installed PSPs (e.g. PayPal id used while PayPal module is deactivated). |
| — | (all passed) | 200 OK | `ValidationApiController::validate()` builds the `ValidationBase` for the plugin id, runs `validateField()` for each posted key, returns the JSON shape. |

Guards are individual SRP classes implementing `ValidationGuardInterface`. They are collected via a Symfony tagged-iterator (`oe.payment_base.validation_guard`) in priority order. New guards added by ops in the future = add a tagged service; no controller edit (R-2.2 OCP).

**Logging:** every guard failure increments a counter (debug-level log line with the guard name + sanitised request key set — never the values), but the HTTP response body stays empty. This balances ops observability against giving scanners a fingerprint.

**Existing primitives re-used (no parallel implementations — R-9.3):**
- `Session::checkSessionChallenge` (OXID) — CSRF.
- `oe_payments_idempotency` table (payment-base, currently used by webhook idempotency) — rate-limit window keyed by `validate:{pluginModuleId}:{sessionId}:{minuteBucket}`. Schema unchanged; new key prefix only.
- `Registry::getConfig()->getShopUrl()` — same-origin reference.
- Webhook guard pattern under `src/Stripe/Controller/Webhook/` — the SRP single-guard class shape is exactly what we adopt for the new `Validation/Guard/` dir (one class per concern, ≤ 30 LOC, single public method returning a nullable failure VO).

**Per-PSP rate-limit override mechanism (new):**
- `src/Validation/RateLimit/RateLimitConfigInterface.php` — single method `getLimitForPlugin(string $pluginModuleId): int`.
- `src/Validation/RateLimit/ConfigurableRateLimitConfig.php` — default impl. Receives `(int $globalDefault, iterable<RateLimitOverrideInterface> $overrides)`. Iterates overrides; first match wins; otherwise returns global.
- `src/Validation/RateLimit/RateLimitOverrideInterface.php` — `getPluginModuleId(): string`, `getLimitPerMinute(): int`. PSPs opt-in by registering a tagged service `oe.payment_base.rate_limit_override`.
- **Stripe does NOT register an override in this sprint** — falls back to the global default. The knob is there for ops + future PSPs to tune without code churn.

**What we explicitly do NOT do:**
- ❌ No "viewport secret token" embedded in templates. It would be leaked the first time someone views page source; rate-limit + same-origin + CSRF subsume the threat.
- ❌ No IP allowlist. Behind reverse proxies + CDNs the source IP is unreliable.
- ❌ No CORS headers. The endpoint is intentionally same-origin only.
- ❌ No `validateField` GET variant. POST-only.
- ❌ No bypass mode "for development". Use a real shop session for development.

### 4.8 Field-name contract (between OXID columns and logical names)

`UserDataValidator::validateForUser(\OxidEsales\Eshop\Application\Model\User $user)` maps OXID columns → logical names:

| Logical name | Source | Required? |
|---|---|---|
| `firstName` | `oxuser__oxfname` | yes |
| `lastName` | `oxuser__oxlname` | yes |
| `additionalInfo` | `oxuser__oxaddinfo` | optional |
| `street` | `oxuser__oxstreet` | yes |
| `houseNumber` | `oxuser__oxstreetnr` | yes |
| `postalCode` | `oxuser__oxzip` | yes |
| `city` | `oxuser__oxcity` | yes |
| `company` | `oxuser__oxcompany` | optional |
| `vatId` | `oxuser__oxustid` | optional |
| `phone` | `oxuser__oxfon` | optional |
| `cellPhone` | `oxuser__oxprivfon` (if configured) | optional |
| `personalPhone` | `oxuser__oxmobfon` (verify column on impl) | optional |
| `fax` | `oxuser__oxfax` | optional |

Delivery-address counterparts (`oxaddress__*`) map to the **same** logical names and are validated in a second pass when `$user->getSelectedAddress()` returns a non-null address. Errors carry an `addressKind: 'billing'|'delivery'` discriminator for surfacing.

---

## 5. The rules file (verbatim per the user spec)

`src/Resources/validation-rules.php`:

```php
<?php

declare(strict_types=1);

return [
    'fields' => [
        [
            'field' => 'firstName',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'lastName',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'additionalInfo',
            'rules' => [
                'allow' => "LETTERS NUMBERS SPACES ' - . , / #",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'street',
            'rules' => [
                'allow' => "LETTERS NUMBERS SPACES ' - . , /",
                'block' => ': ; < > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'houseNumber',
            'rules' => [
                'allow' => 'NUMBERS LETTERS - /',
            ],
        ],
        [
            'field' => 'postalCode',
            'rules' => [
                'allow' => 'LETTERS NUMBERS SPACES -',
            ],
        ],
        [
            'field' => 'city',
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } [ ] ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'company',
            'rules' => [
                'allow' => "LETTERS NUMBERS SPACES ' - . & ,",
                'block' => '< > { } [ ] | \\ ~ ! @ $ % ^ * = +',
            ],
        ],
        [
            'field' => 'vatId',
            'rules' => [
                'allow' => 'LETTERS NUMBERS SPACES -',
            ],
        ],
        [
            'field' => 'phone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'cellPhone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'personalPhone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
        [
            'field' => 'fax',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',
            ],
        ],
    ],
];
```

Notes:
- The double-quoted `"\"` literal blocks above carry a single backslash and a single double-quote each — exactly as the user's spec listed them as blocked.
- The space character is reserved in this grammar as the *token separator*. To allow the space character itself we use the class token `SPACES`. No field declares a literal space token; all fields that allow spaces use `SPACES`.

---

## 6. TDD plan (per-phase RED → GREEN → REFACTOR)

The sprint splits into **6** reviewable commits (A1, A2, B, C, D, E), each its own RED → GREEN cycle. Per lesson **A.2** from `118-lessons-learned.md`, the per-commit boundary is enforced by the dispatch split (one phase = one dispatch = one commit). Per-phase tests are listed in the order they should be written.

### Phase A1 — payment-base `ValidationBase` library + universal blocklist

**Repo:** `payment-base`.
**Lemma:** additive-only (R-3, B.3 lesson). New files only; nothing existing is reordered. paypal + OPC suites byte-identical before/after.

**RED tests** (in `payment-base/tests/Unit/Validation/`):
1. `CharacterClassTest::universalRejectFindsTab` — `hasUniversalReject("a\tb")` returns `"\t"`.
2. `CharacterClassTest::universalRejectFindsLineBreaks` — for `"\n"` and `"\r"`.
3. `CharacterClassTest::universalRejectFindsNullByte` — for `"\0"`.
4. `CharacterClassTest::universalRejectFindsZeroWidthSpace` — for `"\u{200B}"`.
5. `CharacterClassTest::universalRejectPassesPlainAscii` — returns `null` for `"O'Connor"`.
6. `RuleSetTest::parsesAllowAndBlockTokens` — `"UNICODE_LETTERS SPACES ' - ."` → tokens `[CLASS:UNICODE_LETTERS, CLASS:SPACES, LIT:', LIT:-, LIT:.]`.
7. `RuleSetTest::doubleSpaceIsTreatedAsTokenSeparatorOnly` — two consecutive spaces never reach the parser as a literal.
8. `FilesystemValidationRuleLoaderTest::loadsRulesForKnownPlugin` — fixture rules file under a tmp dir; returns the parsed `RuleSet[]` map. (`ModulePathResolverInterface` mocked.)
9. `FilesystemValidationRuleLoaderTest::throwsForMissingFile` — `InvalidArgumentException`.
10. `ValidationBaseTest::validatesAllowOnlyField` — `houseNumber` (allow `NUMBERS LETTERS - /`): `"12a"` valid, `"12!"` invalid with `disallowed_character`, `offendingChar="!"`.
11. `ValidationBaseTest::validatesAllowAndBlock` — `firstName`: `"O'Connor"` valid; `"O:Connor"` invalid with `blocked_character`; `"Anne-Marie"` valid.
12. `ValidationBaseTest::universalBlocklistBeatsAllow` — `additionalInfo` (broad allow) + tab byte → `control_character`.
13. `ValidationBaseTest::unknownFieldNameReturnsValidByDefault` — undeclared `foo` → `valid=true` (documented contract; misuse must not silently reject).
14. `ValidationBaseTest::emptyValueIsValid` — `''` and `null` both `valid=true`.

**GREEN (payment-base production, NO endpoint, NO guards yet):**
- `src/Validation/CharacterClass.php` — `hasUniversalReject`, `matches($char, $classToken)`.
- `src/Validation/RuleSet.php` — `fromArray(array $rules): self`.
- `src/Validation/FilesystemValidationRuleLoader.php` — resolves `<plugin>/Resources/validation-rules.php` via `ModulePathResolverInterface`.
- `src/Validation/ValidationBase.php` (final) + `ValidationBaseInterface.php`.
- `src/Validation/FieldValidationResult.php` — VO with constants `CODE_DISALLOWED_CHARACTER`, `CODE_BLOCKED_CHARACTER`, `CODE_CONTROL_CHARACTER`.
- Register interfaces in `payment-base/services.yaml`.

**REFACTOR:** extract `validateAgainstRuleSet` if `validateField` exceeds 25 lines.

**Gate:** payment-base `composer phpcs && composer phpstan && composer phpmd && composer phpunit` green. **paypal + one-page-checkout** unit-suite counts identical to pre-commit baseline. **No commit yet** (per user instruction, working-tree only).

### Phase A2 — payment-base central validation endpoint + security guard chain

**Repo:** `payment-base`.
**Lemma:** additive-only — new controller registered in `metadata.php` `controllers` map; new guards registered as tagged services; no existing class touched. The endpoint is the single frontend-facing URL for character-level user-data validation; PSPs ship per-plugin rules files but no per-plugin endpoint (R-9.3 reuse).

**RED tests** (in `payment-base/tests/Unit/Controller/` and `tests/Unit/Validation/Guard/`):

Guard-unit tests (one test class per guard, each test ≤ 10 lines):
1. `PostOnlyGuardTest::rejectsGet` / `acceptsPost` — returns `GuardFailure(405)` for GET; `null` for POST.
2. `PayloadSizeGuardTest::rejectsOver4KiB` / `rejectsOver32Fields` / `acceptsValidPayload`.
3. `ActiveSessionGuardTest::rejectsWhenNoSession` / `acceptsWithSession` — `Registry::getSession()` seam mocked.
4. `SameOriginGuardTest::rejectsCrossOriginByOriginHeader` / `rejectsMissingOriginAndReferer` / `acceptsSameOriginByReferer`.
5. `CsrfTokenGuardTest::rejectsMissingToken` / `rejectsWrongToken` / `acceptsValidToken`.
6. `RateLimitGuardTest::allowsUnderLimit` / `rejectsAtCapPlusOneInWindow` / `slidingWindowExpires` / `countersAreScopedByPluginModuleId` (two pluginIds → independent counters) / `respectsPerPluginOverride` (`RateLimitConfigInterface` returns 5 for `acme_pay` → 6th request rejected while default 30 still allowed for `oe_payments_stripe_wallet`).
   Plus `RateLimitConfigTest::overrideTakesPrecedenceOverGlobal` / `globalUsedWhenNoOverrideMatches` / `firstMatchingOverrideWins` (deterministic iterator order).
7. `PluginIdAllowlistGuardTest::rejectsUnknownPluginId` / `rejectsDeactivatedModule` / `acceptsActiveModule` (`ShopConfigurationDaoInterface` mocked).

Controller-integration tests (run with all guards composed):
8. `ValidationApiControllerTest::happyPathPostReturnsValidJson` — minimal valid POST → `{valid:true, errors:[]}`, HTTP 200, content-type `application/json`.
9. `ValidationApiControllerTest::happyPathPostReturnsInvalidJson` — `firstName=O:Connor` → JSON `errors[0]` shape with field/code/char/message, HTTP 200.
10. `ValidationApiControllerTest::guardOrderShortCircuits` — GET request → 405 (no further guards run; no body parsing). Assert via guard-execution log: only `PostOnlyGuard` evaluated.
11. `ValidationApiControllerTest::failureResponseBodyIsEmpty` — for each guard, the failure response carries no body (no signal to scanners about which guard tripped).
12. `ValidationApiControllerTest::pluginIdRoutesToCorrectRulesFile` — POST with `pluginModuleId=oe_payments_stripe_wallet` resolves the stripe rules; POST with a fictional `acme_pay` (active in test fixture) resolves the acme rules. Same validator instance behaviour, different rule sets.

**GREEN (payment-base production):**
- `src/Validation/Guard/ValidationGuardInterface.php` — `check(ValidationRequestContext $ctx): ?GuardFailure`.
- `src/Validation/Guard/GuardFailure.php` — VO: int `httpStatus`, string `guardName` (for logging only — never serialised into the HTTP body).
- `src/Validation/Guard/ValidationRequestContext.php` — VO carrying the parsed request (method, body bytes, parsed fields, session-id, origin, referer, plugin-module-id).
- One file per guard listed in §4.7 (each ≤ 30 LOC, single `check()` method).
- `src/Validation/RateLimit/RateLimitConfigInterface.php` + `ConfigurableRateLimitConfig.php` + `RateLimitOverrideInterface.php` — per-PSP override knob (§4.7). `RateLimitGuard` constructor accepts `RateLimitConfigInterface`; no hardcoded `30`.
- `src/Validation/RateLimitStore.php` (or re-use `oe_payments_idempotency` repo) — sliding-window counter keyed by `validate:{pluginModuleId}:{sessionId}:{minuteBucket}`.
- `src/Controller/ValidationApiController.php` — extends OXID's `FrontendController`. Single `fnc=validate`. Body: iterate the tagged guards in priority order; on first failure, emit HTTP status + empty body; if all pass, build `ValidationBase` for `pluginModuleId` and run `validateField()` per posted key, return JSON. ≤ 25 lines using helper `runGuards()` + `runValidation()`.
- `metadata.php`: add `'oepaymentvalidationapi' => ValidationApiController::class` under `controllers`. Add admin setting `iValidationApiRatePerMinute` (int, default 30) for the global limit.
- `services.yaml`: tagged iterator `oe.payment_base.validation_guard`; each guard registered with explicit `priority`. Tagged iterator `oe.payment_base.rate_limit_override` (empty in this sprint; PSPs opt in by adding the tag).

**REFACTOR:** none expected — each guard is a small SRP class; controller is a thin façade.

**Gate:** payment-base full suite green; paypal + OPC suites byte-identical (still no consumer code touched). **No commit.**

### Phase B — Stripe per-plugin rules file + `UserDataValidator` façade

**Repo:** `stripe`.

**RED tests** (in `tests/Unit/Stripe/Service/`):
1. `UserDataValidatorTest::validatesAllOxidUserColumnsByLogicalNames` — given a User stub with `oxfname="O'Connor"`, `oxlname="Anne-Marie"`, `oxstreet="Main St."`, `oxstreetnr="12a"`, `oxzip="10115"`, `oxcity="Köln"`, `oxcompany=""`, `oxustid="DE123456789"`, `oxfon="+49 30 123-4567"`, returns `valid=true`, `errors=[]`.
2. `UserDataValidatorTest::reportsDisallowedColonInFirstName` — `oxfname="O:Connor"` → one error with `field=firstName`, `code=blocked_character`, `offendingChar=":"`.
3. `UserDataValidatorTest::reportsTabInStreetAsControlCharacter` — `oxstreet="Main\tStreet"` → `code=control_character`.
4. `UserDataValidatorTest::validatesDeliveryAddressWhenSelected` — User has selected delivery address with `oxcity="München!"` → error with `addressKind=delivery`, `field=city`.
5. `UserDataValidatorTest::skipsValidationForEmptyOptionalField` — `oxcompany=""` returns no error (required-field non-empty enforcement is not our concern).
6. `UserDataValidatorTest::rulesFileLoadedForCorrectPluginId` — constructor with `'oe_payments_stripe_wallet'` resolves the file at `src/Resources/validation-rules.php`. Use a real `FilesystemValidationRuleLoader` with the real file (not a mock) so this is an integration-of-units check.

**GREEN (Stripe production):**
- `src/Resources/validation-rules.php` — §5 verbatim.
- `src/Stripe/Service/UserDataValidator.php` — final class. Constructor `(ValidationBaseInterface $base)`. Method `validateForUser(\OxidEsales\Eshop\Application\Model\User $user): array` returning `FieldValidationResult[]` (with `addressKind` mixed in via a tiny wrapper struct).
- `services.yaml`: register `UserDataValidator` with a `validation_base` argument that itself is instantiated with `pluginModuleId: 'oe_payments_stripe_wallet'` (use the factory pattern via service definition, no `new` in business code).
- Use `StripeDefinitions::MODULE_ID` (or `Module::MODULE_ID`) for the plugin id — no string literal.

**Gate:** `./bin/pre-commit-check.sh --full` green. Assertion count rises by at least the Phase B test count.

### Phase C — wiring on the standard-checkout path

**RED tests:**
1. `StripeOrderControllerValidationTest::checkoutDispatchBlockedOnInvalidUserData` — testable subclass overriding only the seams (user provider, validator, dispatcher). Given a user with `oxfname="O:Connor"`, calling `createCheckoutSession()` returns the `payment` step name (or its OXID equivalent) and the `EventDispatcherInterface` mock asserts `dispatch()` was **never** called with `OrderCreatedEvent`.
2. `StripeOrderControllerValidationTest::flashErrorsCarryFieldAndCode` — after the blocked dispatch, `Registry::getSession()->getVariable('Errors')` (or the controller's `errors` view-data accumulator) contains an entry with the field name and the violation code.
3. `StripeOrderControllerValidationTest::validUserPassesThroughToExistingFlow` — happy path: validation passes → existing `OrderCreatedEvent` dispatch fires (the pre-existing tests must still go green; this is a regression guard).
4. `StripeOrderControllerValidationTest::validationRunsAfterCsrfAndConfigCheck` — using ordering assertions: `validateSessionChallenge` precedes validation precedes dispatch.

**GREEN:**
- Add a `private function validateUserData(\OxidEsales\Eshop\Application\Model\User $user): array` to `StripeOrderController`. Call it from `createCheckoutSession()` right after `validateCheckoutPreconditions()`.
- Inject `UserDataValidatorInterface` via the testable-seam property pattern documented at `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php:636` (NO new `ContainerFactory::getInstance()` — R-4).
- Surface errors via a new helper `addUserDataValidationErrors(array $failures): void` that writes to OXID's flash-error structure. Translation lookup uses `StripeDefinitions::MODULE_ID . '.validation.' . $field . '.' . $code`.

**REFACTOR:** if `createCheckoutSession()` grows past 25 lines, extract the new pre-flight into a `runPreFlightChecks()` helper that returns either a redirect-target string or `null` (continue).

**Gate:** `./bin/pre-commit-check.sh --full` green. **No new PHPMD suppressions** (R-6.2). Controller `WeightedMethodCount` baseline entry not grown.

### Phase D — OPC Stimulus controller calling the payment-base endpoint + Playwright

**Repo:** `stripe` only (the endpoint already exists in payment-base from A2).

**RED tests:**
1. `StripeCheckoutFooterTest::widgetExposesValidationUrlAndPluginId` — `getCheckoutData()` returns `validationUrl` pointing at `cl=oepaymentvalidationapi&fnc=validate` and `pluginModuleId` set to `Module::MODULE_ID`. Asserts the URL is built from `Registry::getConfig()->getShopUrl()` (no hardcoded host).
2. `StripeCheckoutFooterTest::widgetIncludesStokenForCsrf` — the widget output also exposes the current `Session::getSessionChallengeToken()` (or equivalent) so the JS can submit it. Same-data-attribute style as the existing csrfToken key.
3. **Playwright spec** `tests/e2e/playwright/playwright/tests/opc/stripe-user-data-validation.spec.ts`:
   - Load OPC with the Stripe footer widget visible (logged-in user fixture).
   - Type `O:Connor` into the firstName field; click OPC submit.
   - Assert: inline error rendered next to firstName; OPC URL did NOT change (form did not submit).
   - Fix firstName to `O'Connor`; submit again → succeeds.
   - **Negative-security spec**: from `page.evaluate`, attempt a `fetch` to the validation URL **without** the session token / wrong `Origin` → assert 4xx and empty body. This locks in the security gates from a real browser context.

**GREEN:**
- Edit `src/Stripe/Component/Widget/StripeCheckoutFooter.php`:
  - Add `validationUrl` to `getCheckoutData()` built as `Registry::getConfig()->getShopUrl() . 'index.php?cl=oepaymentvalidationapi&fnc=validate'`.
  - Add `pluginModuleId` = `Module::MODULE_ID`.
  - Add `csrfToken` already exists; reuse it (do not duplicate the session-challenge lookup).
- Edit `views/twig/widget/checkout/stripe-footer.html.twig`:
  - Add `data-controller="… stripe-user-data-validator"` on the existing root.
  - Add `data-stripe-user-data-validator-url-value`, `data-stripe-user-data-validator-plugin-id-value`, `data-stripe-user-data-validator-csrf-value` data-attributes.
  - **Do NOT emit the rules**; the JS knows nothing about the rule grammar.
- `assets/js/controllers/stripe-user-data-validator-controller.js` — Stimulus controller, ~80 LOC:
  - Hooks the OPC submit button (configured via target data-attribute).
  - On submit-attempt: `event.preventDefault()`, build `FormData` from the OPC user-data fields (mapping CSS selectors → logical names that match §4.8), append `pluginModuleId` and `stoken`, then `fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })`.
  - On HTTP 200 + `valid:true` → release the submit (programmatic `.requestSubmit()`).
  - On HTTP 200 + `valid:false` → render inline errors next to each field (template snippet using existing OPC error-classes).
  - On HTTP 4xx/5xx (any guard failure or transient backend error) → **fail open with a logged warning** (`console.warn`) and release the submit. Backend will still be re-validated by the standard-checkout path's `StripeOrderController` if the user routes through there; for OPC fail-open is the explicit decision documented in the header of the JS file.
- Build with `npm run build`; commit the rebuilt `stripe-frontend.min.js` + map.

**REFACTOR:** none — the JS controller is a thin adapter.

**Gate:** `./bin/pre-commit-check.sh --full` green; `npm run build` clean; Playwright specs (positive + negative-security) pass locally. **Cache cleared + php restarted** per R-6.3. **No commit.**

### Phase E — translations & user-friendly messages

**RED tests:**
1. `UserDataValidationMessageTest::messageReplacesPlaceholders` — given a `FieldValidationResult` with `code=blocked_character`, `offendingChar=":"`, the rendered translation reads e.g. `"First name may not contain the character ':'."`.
2. `UserDataValidationMessageTest::fallsBackToGenericMessageOnMissingTranslation` — unknown code → a generic translated fallback (no PHP notice, no raw key in output).

**GREEN:**
- Add the message keys to `views/translations/de/lang.php` and `views/translations/en/lang.php` (or whichever locale files the module ships — verify during impl). Keys:
  - `STRIPE_VALIDATION_FIRSTNAME_BLOCKED_CHARACTER`
  - `STRIPE_VALIDATION_LASTNAME_BLOCKED_CHARACTER`
  - … one per field × per code in use (`disallowed_character`, `blocked_character`, `control_character`).
- Plus a generic fallback `STRIPE_VALIDATION_GENERIC`.
- Tiny renderer `UserDataValidationMessageFormatter::format(FieldValidationResult $result): string` — looks up the language key, `sprintf`s the offending char + field label.

**REFACTOR:** if Phase C / D were emitting messages inline, replace those callsites with the formatter.

**Gate:** `./bin/pre-commit-check.sh --full` green; manual quick-pass: bad input in storefront shows a translated error, not a raw key.

---

## 7. Test counts target (regression guard)

Per R-1 / R-6: record before/after Tests & Assertions in the completion report.

| Phase | New unit tests (target) | New assertions (target) |
|---|---|---|
| A1 (payment-base library) | ~14 | ~30 |
| A2 (payment-base endpoint + 7 guards + per-PSP override) | ~28 (7×~3 guard tests + 5 controller tests + 3 RateLimitConfig tests) | ~78 |
| B (Stripe rules + façade) | ~6 | ~24 |
| C (controller wiring) | ~4 | ~12 |
| D (OPC widget + Playwright) | ~2 unit + 2 Playwright (positive + negative-security) | ~10 |
| E (messages) | ~2 | ~4 |
| **Total payment-base** | **~39** | **~100** |
| **Total Stripe** | **~14 unit + 2 Playwright** | **~50** |

Assertion count must rise; no test is deleted unless replaced by a stronger one.

## 8. Risks & rollback

- **Risk: paypal/OPC regression from payment-base touch** — mitigated by additive-only rule (lesson B.3): new files only, no signature/return-type changes, no existing class touched. Pre-flight: run paypal + OPC unit suites BEFORE phase A1. Post-flight: rerun after A1 AND after A2; counts must match exactly.
- **Risk: false positives on legitimate addresses** — names like `O'Connor`, `Anne-Marie`, `Köln`, German house numbers like `12a`, German postal codes with hyphen — all explicitly tested as valid in §6. The **single rules file** per plugin is the one-touch remediation point.
- **Risk: OPC submit blocked despite valid data** (network failure or transient guard-trip on the AJAX endpoint) — the Stimulus controller **fails open** with a `console.warn` on HTTP 4xx/5xx: it releases the OPC submit so a flaky network doesn't brick checkout. The backend `StripeOrderController::createCheckoutSession` validation (Phase C) is the synchronous source of truth for the standard-checkout path; for OPC the fail-open is the explicit, documented residual risk (an attacker who *wants* validation to be skipped can already type valid-looking values, so the threat profile is not "skip validation" but "amplify writes" — which the rate-limit guard already addresses).
- **Risk: endpoint becomes a DoS amplifier despite guards** — addressed by the layered chain (§4.7): a scripted attacker without a same-origin browser cookie + CSRF token cannot get past guards #3-#5; with a valid session they hit the rate-limit guard at 30 req/min. Tracking is keyed by session id, NOT IP, so botnets sharing one harvested session still hit a single limit. Worst-case amplification = legitimate session × 30/min × per-character validator cost (≈ 1 ms/field), which is bounded.
- **Risk: response body leaks the failing guard's identity** — addressed by §4.7's "empty body on failure" rule. The HTTP status carries enough info for the legitimate JS client to behave (4xx = retry would be pointless / fail open); scanners get no fingerprint.
- **Risk: rate-limit storage chosen poorly** — the existing `oe_payments_idempotency` table is the conservative default (proven primitive, persists across PHP-FPM workers). If A2 finds it adds non-trivial latency, fall back to in-process PSR-16 cache with a comment that this loosens cross-worker isolation. Decide during impl, document the choice in the A2 completion report.
- **Risk: PHPStan max on the universal-reject regex** — `preg_match` with `/u` and surrogate-class ranges sometimes trips older PHPStan versions. Wrap in a typed helper if so.
- **Risk: agent collapses phase commits** — per lesson A.2, dispatch each phase (A1, A2, B, C, D, E) as **separate** agent jobs. The phase boundary is a hard dispatch boundary.
- **Risk: silent integration skips re-emerge** (lesson B.7) — Playwright specs MUST run with a real container. If creds/container are absent, **fail** (don't skip).
- **Rollback:** per phase; each commit is independently revertable. **A1 is sufficient on its own** (library lands; no endpoint, no consumer). **A2 builds on A1** but is independently revertable (delete the controller + guards directory + `metadata.php` entry; library survives). B…E build further; each its own revert unit.

## 9. Definition of Done

- [ ] G1–G8 (including **G5.5**) satisfied; completion report attached at `done/sprint-119-completion.md`.
- [ ] Phase-by-phase changes staged on `b-7.4.x-user-data-filter-STRP-129`. **Commits are deferred to user review** (per current-session instruction: agents must NOT commit). Each phase produces a clean working-tree diff the user can stage and commit separately.
- [ ] `./bin/pre-commit-check.sh --full` green at the end of each phase (i.e. *before* a hypothetical commit would land).
- [ ] paypal + one-page-checkout unit suites unchanged (assertion counts identical before/after both A1 and A2).
- [ ] Endpoint security verified end-to-end:
  - Playwright negative-security spec proves the endpoint rejects requests without same-origin / CSRF / session.
  - Manual smoke: `curl -X GET <validationUrl>` → 405 empty body; `curl -X POST <validationUrl>` (no cookies, no token) → 4xx empty body.
- [ ] Memory updates:
  - **New project memory** `project_strp_129_validation_base.md` — record that payment-base now ships `ValidationBase` + central frontend endpoint + per-plugin rules file convention; capture the field-name → OXID-column mapping (§4.8) and the guard chain (§4.7) so PayPal's adoption sprint can copy the pattern with zero new endpoints.
  - **New feedback memory** `feedback_central_validation_endpoint_security.md` — record the threat-model decisions (no CORS, no viewport secret, session-keyed rate limit, empty body on guard failure) so future sprints don't re-litigate them.
  - **Update** `project_payment_component_oxid_module.md` to mention the new rules-file convention for cross-module consumers.
- [ ] Driver report `user-data-and-address-validation.md` annotated at §8 with `[Done — Sprint 119]` markers on R1; R2, R3, R4, R5, R6 explicitly left for follow-up sprints (out of scope here).

## 10. Quick gate checklist (paste into completion report)

- [ ] R-1 TDD: RED before GREEN per phase; no method-under-test re-implemented in a double (the `UserDataValidator` and `ValidationApiController` use real instances over real `ValidationBase`).
- [ ] R-2 SOLID: one rules-loader, one validator library, one endpoint, seven SRP guards — no god-class growth; PHPMD baseline not grown.
- [ ] R-3 LI: `ValidationBase` final; substitutability proven by tests against the interface; each guard substitutable behind `ValidationGuardInterface`.
- [ ] R-4 DI: constructor-injected `ValidationRuleLoaderInterface`, `ValidationBaseInterface`, `UserDataValidatorInterface`, tagged-iterator of `ValidationGuardInterface`; no `ContainerFactory::getInstance` in business code.
- [ ] R-5 Clean Code: ≤25-line methods; no `else`; explicit `use` imports; no magic strings for plugin id (`Module::MODULE_ID`) or violation codes (`FieldValidationResult::CODE_*`).
- [ ] R-6 DevOps-first: `pre-commit-check.sh --full` green; no new suppressions; cache cleared + php restarted after `services.yaml` and `metadata.php` edits.
- [ ] R-7 Event-driven: validation is a synchronous pre-dispatch read (no new events needed); standard-checkout and OPC paths converge on the same `UserDataValidator` chain (R-7.4) via either direct call (standard) or the central endpoint (OPC).
- [ ] R-8 Contract-aware: validation runs BEFORE `OrderCreatedEvent` / contract creation — the contract never enters DRAFT on invalid input.
- [ ] R-9 No overengineering: no JS rule-engine mirror; no admin UI for rules; no DB storage; no PayPal adoption in this sprint; no per-PSP frontend endpoint (one central endpoint serves all PSPs).
- [ ] R-10 Persistence: validator is a pure read; rate-limit counter increment is the **only** new write and lives inside a single repository call from within an event-reached service (or, if PSR-16 fallback chosen, no DB write at all); no `oxNew`+`save` introduced; controller short-circuit on failure does not touch the DB.
