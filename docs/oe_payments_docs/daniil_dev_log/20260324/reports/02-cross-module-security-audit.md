# Report: Cross-Module Security Audit — Stripe Module vs. Other Payment Modules

**Date:** 2026-03-24
**Scope:** Code review of our Stripe module (`source/extensions/stripe/`) against security findings from audits of Adyen, AmazonPay, easyCredit, PayPal, Unzer, and old Stripe (OXID 6.5) modules.
**Method:** Automated + manual code review of all source findings against our codebase.

---

## Executive Summary

Our Stripe module (OXID 7.4+, Smart-Contract Architecture) is **not affected by the critical/high issues** found in other modules. The module has strong security posture: proper webhook signature verification, CSRF protection on all state-changing endpoints, no SQL injection, no IDOR, no unserialize(), and no DOM-XSS.

**Two medium-severity issues found, one low-severity, and one dead-code concern:**

| # | Severity | Issue | Status |
|---|----------|-------|--------|
| S1 | **Medium** | ContractTokenService hardcoded fallback secret | Active code |
| S2 | **Medium** | `isConfigured()` doesn't check webhook secret (commented out) | Active code |
| S3 | **Low** | Webhook guard chain fails open if DI container errors | Active code |
| S4 | **Info** | Dead code template with stoken leaks, XDEBUG, `|raw` | Commented out (inactive) |

---

## Issues NOT Found in Our Module

These critical/high issues from other modules do **not** exist in our codebase:

| Issue (from other module) | Our Status | Evidence |
|---------------------------|------------|----------|
| **HMAC bypass** (Adyen: default true, injection) | NOT VULNERABLE | Uses Stripe SDK `Webhook::constructEvent()` — fail-closed |
| **SQL injection** (AmazonPay: direct concat) | NOT VULNERABLE | All queries use prepared statements or QueryBuilder |
| **IDOR** (old Stripe: StripeFinishPayment, Unzer: deletePayment) | NOT VULNERABLE | No order/user loads by ID from request without ownership check |
| **SSRF** (Unzer: Apple Pay merchantValidationUrl) | NOT VULNERABLE | No server-side HTTP calls with user-controlled URLs |
| **Unauthenticated state-change endpoint** (AmazonPay: poll) | NOT VULNERABLE | All state-changing endpoints require session/signature |
| **Session hijacking via setVariable('usr')** (old Stripe) | NOT VULNERABLE | No code sets user session variable from request/order data |
| **unserialize() without allowed_classes** (PayPal, easyCredit) | NOT VULNERABLE | No `unserialize()` calls in entire module |
| **DOM-XSS via innerHTML / jQuery .html()** (Unzer: 6 templates, old Stripe) | NOT VULNERABLE | No `innerHTML` or `.html()` in active JS/templates |
| **Open redirect from API response** (AmazonPay) | NOT VULNERABLE | All redirects hardcoded or config-based |
| **Session-ID/stoken in redirect URLs to provider** (old Stripe) | NOT VULNERABLE | Uses contract tokens instead (HMAC-signed, one-time) |
| **Missing CSRF on state-changing endpoints** (Adyen, AmazonPay, easyCredit, Unzer) | NOT VULNERABLE | All endpoints validate `checkSessionChallenge()` |
| **No webhook endpoint** (easyCredit) | NOT VULNERABLE | Full webhook implementation with guard chain |
| **Weak crypto for tokens** (PayPal: md5+mt_rand, AmazonPay: uuid fallback) | NOT VULNERABLE | Uses `hash_hmac('sha256')` + `hash_equals()` |
| **Timing attack on secret comparison** (old Stripe: `==`) | NOT VULNERABLE | Uses `hash_equals()` for all token comparisons |
| **Floating-point amount comparison** (old Stripe) | NOT VULNERABLE | Uses integer cents throughout (`(int) round($x * 100)`) |
| **getRawValue() on user data** (old Stripe, easyCredit) | NOT VULNERABLE | No `getRawValue()` usage on user/API data |
| **Stored XSS via API data in templates** (old Stripe Smarty, AmazonPay) | NOT VULNERABLE | Twig auto-escaping active; no `|raw` on dynamic data in active templates |

---

## Issues Found

### S1: ContractTokenService Hardcoded Fallback Secret — MEDIUM

**File:** `src/Stripe/Service/ContractTokenService.php:28, 43-45`

```php
private const TOKEN_SECRET = 'oe_stripe_contract_token_secret';

// ...
if (empty($apiSecret)) {
    // Last resort: use a default (less secure but prevents fatal errors)
    $apiSecret = self::TOKEN_SECRET;
}
```

**What:** If both Stripe API secret key and webhook secret are unconfigured (empty), contract token HMAC generation falls back to a **hardcoded constant** `'oe_stripe_contract_token_secret'`.

**Impact:** Contract tokens protect the `checkoutSuccess()` endpoint from unauthorized access (Sprint 67a, finding H3). With a known/guessable secret, an attacker could forge valid contract tokens.

**When it triggers:** Only when Stripe credentials are completely unconfigured — unlikely in production, but possible during initial setup or misconfiguration.

**Comparable to:** Adyen HMAC default-true (Finding 1.1), but less severe because the fallback only affects contract tokens, not webhook signatures.

**Recommendation:** Throw an exception or return empty token instead of using hardcoded fallback. The contract token is a security mechanism and must not operate with a predictable secret.

---

### S2: `isConfigured()` Doesn't Validate Webhook Secret — MEDIUM

**File:** `src/Stripe/Service/ModuleConfigurationService.php:211`

```php
public function isConfigured(): bool
{
    return !empty($this->getToken())/* && !empty($this->getSecretKey())/* && !empty($this->getWebhookSecret())*/;
}
```

**What:** The webhook secret check is **commented out** in `isConfigured()`. This method is used by `PaymentController::validatePayment()` to check if Stripe is ready. Without the webhook secret check, a shop could accept Stripe payments but have no way to receive webhook notifications.

**Impact:** If webhook secret is not configured:
- Stripe SDK's `Webhook::constructEvent()` will likely reject all webhooks (implicit fail-closed)
- But the shop won't know webhooks are broken — orders may never get confirmed
- The commented-out check suggests this was intentionally removed, possibly for compatibility

**Comparable to:** Not a security bypass (Stripe SDK still validates), but a configuration oversight that could lead to operational issues.

**Recommendation:** Uncomment the webhook secret check, or at minimum log a warning when webhooks are processed without a configured secret.

---

### S3: Webhook Guard Chain Fails Open — LOW

**File:** `src/Stripe/Controller/Webhook/WebhookController.php:58-63`

```php
try {
    $guard = $container->get(WebhookRequestGuardInterface::class);
    $this->guard = $guard instanceof WebhookRequestGuardInterface ? $guard : null;
} catch (\Exception $e) {
    // Guard not available — proceed without (fail-open for compatibility)
}
```

**What:** If the DI container fails to load the guard chain (rate limiter, IP allowlist, HTTPS guard, payload size guard), webhooks proceed **without these protections**. Signature verification still runs.

**Impact:** Low — signature verification is the primary security control and it still executes. The guards are defense-in-depth layers (rate limiting, DoS protection). This would only matter if the DI container is misconfigured.

**Recommendation:** Consider making guard chain mandatory (throw on failure) or at minimum log a warning.

---

### S4: Dead Code Template with Security Anti-Patterns — INFO

**File:** `views/twig/frontend/base_stripe_payment_controller_config.html.twig`

The entire file (lines 9-71) is wrapped in a Twig comment `{# ... #}`. It contains:
- `stoken` in URLs passed to JavaScript (would leak CSRF tokens)
- `XDEBUG_SESSION=PHPSTORM` appended in sandbox mode
- `vaultedPaymentSource|raw` without sanitization
- Multiple `|raw` on URL strings

**Impact:** Zero — the code is inactive. But it's a maintenance risk:
- Accidental uncommenting would introduce multiple vulnerabilities
- The comment says "ported from PayPal, needs to be adjusted"

**Recommendation:** Delete the dead code entirely. If it's needed as a reference, move it to documentation.

---

## Security Strengths

Our module implements several patterns that other modules lack:

| Pattern | Our Implementation | Modules That Lack It |
|---------|-------------------|---------------------|
| Webhook signature verification | Stripe SDK `Webhook::constructEvent()` | Adyen (bypassable), Unzer (domain-only), easyCredit (none) |
| Webhook guard chain | Rate limit + IP allowlist + HTTPS + payload size | All other modules |
| Webhook idempotency | Atomic `claimEvent()` with DB uniqueness | Adyen, AmazonPay, easyCredit |
| Contract tokens (HMAC-signed) | `ContractTokenService` with `hash_equals()` | Old Stripe (raw order ID), AmazonPay (raw session ID) |
| CSRF on all endpoints | `checkSessionChallenge()` everywhere | Adyen (2 gaps), AmazonPay (3 gaps), easyCredit (3 gaps), Unzer (1 gap) |
| Integer cents for amounts | `(int) round($x * 100)` | Old Stripe (float comparison) |
| No `unserialize()` | Uses JSON throughout | PayPal, easyCredit (both vulnerable) |
| Twig auto-escaping | Active, no `|raw` on dynamic data | N/A (OXID 6.5 modules use Smarty without auto-escaping) |

---

## Comparison Matrix

| Issue Category | PayPal | AmazonPay | Unzer | Adyen | easyCredit | Old Stripe | **Our Stripe** |
|----------------|--------|-----------|-------|-------|------------|------------|----------------|
| SQL Injection | View-name | **Direct** | None | orWhere | None | Events.php | **None** |
| unserialize() | **Yes** | No | No | No | **Yes (2)** | No | **No** |
| SSRF | No | No | **Apple Pay** | No | No | No | **No** |
| IDOR | No | No | **deletePayment** | No | No | **FinishPayment** | **No** |
| Webhook bypass | No | No | Domain-only | **HMAC bypass** | **No webhook** | N/A | **No** |
| DOM-XSS | No (6.5) | No | **6 templates** | No | **2 templates** | **1 template** | **No** |
| CSRF gaps | 0 | 3 | 1 | 2 | 3 | 0 | **0** |
| Session hijack | No | No | No | No | No | **Yes** | **No** |
| Open redirect | Theoretical | **API-based** | No | No | No | No | **No** |
| Token in URLs | No | No | No | No | **SID leak** | **sid+stoken** | **No** (contract tokens) |
| Weak crypto | md5 nonce | UUID fallback | md5 txn-id | No | md5 hash | `==` timing | **No** |

---

*Generated: 2026-03-24 | Method: Cross-module code review against 6 audit reports*
