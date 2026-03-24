# Sprint 76: Security Fixes S1–S4 from Cross-Module Audit

**Date:** 2026-03-24
**Ticket:** STRP-104 (security hardening)
**Branch:** `b-7.4.x-empty-shipping-STRP-104`

---

## Findings to Fix

| # | Severity | Issue | File |
|---|----------|-------|------|
| S1 | Medium | ContractTokenService hardcoded fallback secret | `ContractTokenService.php:28,43-45` |
| S2 | Medium | `isConfigured()` has webhook secret check commented out | `ModuleConfigurationService.php:211` |
| S3 | Low | Webhook guard chain fails open on DI error | `WebhookController.php:58-63` |
| S4 | Info | Dead code template with stoken leaks, XDEBUG, `\|raw` | `base_stripe_payment_controller_config.html.twig` |

---

## TDD Steps

### Step 1: Failing Tests for S1 — ContractTokenService Must Reject Missing Secret

**File:** `tests/Unit/Stripe/Service/ContractTokenServiceTest.php` (modify existing)

The existing test `testUsesDefaultSecretWhenNoneConfigured` **asserts the wrong behavior** — it asserts that token generation succeeds with a hardcoded fallback. After the fix, it must assert that an exception is thrown instead.

**Tests to add/modify:**

```
testThrowsExceptionWhenNoSecretConfigured
  Arrange: configService returns '' for both getSecretKey() and getWebhookSecret()
  Act: new ContractTokenService($configService)
  Assert: throws \RuntimeException with message containing 'secret'

testGenerateTokenThrowsWhenSecretNotConfigured
  (same as above but exercised through generateToken)

testUsesWebhookSecretAsFallback — KEEP (already exists, still valid)
```

**Modify existing:** `testUsesDefaultSecretWhenNoneConfigured` → rename to `testThrowsExceptionWhenNoSecretConfigured` and invert the assertion.

**Principle:** Fail-closed — security mechanisms must not operate with predictable secrets.

### Step 2: Fix S1 — Remove Hardcoded Fallback, Throw on Missing Secret

**File:** `src/Stripe/Service/ContractTokenService.php`

**Change:**

```php
// BEFORE (line 43-46):
if (empty($apiSecret)) {
    // Last resort: use a default (less secure but prevents fatal errors)
    $apiSecret = self::TOKEN_SECRET;
}

// AFTER:
if (empty($apiSecret)) {
    throw new \RuntimeException(
        'Stripe contract token service requires a configured API secret key or webhook secret. '
        . 'Configure sStripeTestToken/sStripeLiveToken or sStripeWebhookEndpointSecret in module settings.'
    );
}
```

Also remove the unused constant:
```php
// DELETE:
private const TOKEN_SECRET = 'oe_stripe_contract_token_secret';
```

**Principle:** OCP — the class's security contract (requires a real secret) is now enforced at construction time, not left as an implicit assumption. DIP — depends on configuration abstraction, fails explicitly when the precondition isn't met.

**Verify:** Run modified tests — all must pass.

### Step 3: Failing Test for S2 — `isConfigured()` Must Check Webhook Secret

**File:** `tests/Unit/Stripe/Service/ModuleConfigurationServiceTest.php` (new or existing)

Since `ModuleConfigurationService` depends on OXID's `ModuleConfigurationDaoInterface` and `ContextInterface`, we'll test via a testable subclass or verify the method logic directly.

**Tests to add:**

```
testIsConfiguredReturnsFalseWhenWebhookSecretEmpty
  Arrange: token = 'sk_test_123', webhookSecret = ''
  Act: isConfigured()
  Assert: false

testIsConfiguredReturnsTrueWhenAllKeysPresent
  Arrange: token = 'sk_test_123', webhookSecret = 'whsec_xxx'
  Act: isConfigured()
  Assert: true

testIsConfiguredReturnsFalseWhenTokenEmpty
  Arrange: token = '', webhookSecret = 'whsec_xxx'
  Act: isConfigured()
  Assert: false
```

### Step 4: Fix S2 — Uncomment Webhook Secret Check in `isConfigured()`

**File:** `src/Stripe/Service/ModuleConfigurationService.php:211`

**Change:**

```php
// BEFORE:
return !empty($this->getToken())/* && !empty($this->getSecretKey())/* && !empty($this->getWebhookSecret())*/;

// AFTER:
return !empty($this->getToken()) && !empty($this->getWebhookSecret());
```

**Note:** `getSecretKey()` remains excluded — `getToken()` and `getSecretKey()` return the same value (both map to `sStripeTestToken`/`sStripeLiveToken`), so checking both is redundant. The critical missing check is `getWebhookSecret()`.

**Principle:** Fail-closed — `isConfigured()` must not return true when webhooks can't be verified.

**Verify:** Run tests — all must pass.

### Step 5: Failing Test for S3 — Webhook Controller Must Log Warning on Guard Failure

**File:** `tests/Unit/Stripe/Controller/Webhook/WebhookControllerGuardIntegrationTest.php` (modify existing)

**Test to add:**

```
testLogsWarningWhenGuardChainFailsToLoad
  Arrange: DI container throws on guard service resolution
  Act: controller init()
  Assert: logger->warning() called with 'guard chain unavailable'
         AND controller.guard is null (still processes, but logged)
```

This test documents the current behavior (fail-open) but ensures it's at least logged at warning level, not silently swallowed.

### Step 6: Fix S3 — Log Warning When Guard Chain Unavailable

**File:** `src/Stripe/Controller/Webhook/WebhookController.php:58-63`

**Change:**

```php
// BEFORE:
try {
    $guard = $container->get(WebhookRequestGuardInterface::class);
    $this->guard = $guard instanceof WebhookRequestGuardInterface ? $guard : null;
} catch (\Exception $e) {
    // Guard not available — proceed without (fail-open for compatibility)
}

// AFTER:
try {
    $guard = $container->get(WebhookRequestGuardInterface::class);
    $this->guard = $guard instanceof WebhookRequestGuardInterface ? $guard : null;
} catch (\Exception $e) {
    Registry::getLogger()->warning('Webhook guard chain unavailable — processing without rate limiting/IP checks', [
        'error' => $e->getMessage(),
    ]);
}
```

**Principle:** Defense in depth — signature verification still protects, but missing guards are explicitly logged so operators can detect misconfiguration.

### Step 7: Fix S4 — Delete Dead Code Template

**File:** `views/twig/frontend/base_stripe_payment_controller_config.html.twig`

**Action:** Delete the entire file. It is 100% inside a Twig comment (`{# ... #}`), was ported from PayPal, and is never included by any active template. Contains security anti-patterns (stoken in URLs, XDEBUG, `|raw`) that could be accidentally activated.

**Verify:** `grep -r "base_stripe_payment_controller_config" views/` returns no active includes.

### Step 8: Run Full Pre-commit Check

```bash
docker compose exec -w /var/www/extensions/stripe -T php ./bin/pre-commit-check.sh --full
```

---

## Implementation Details

### S1 Fix — Impact on Callers

`ContractTokenService` is resolved via DI container (`services.yaml`). If it throws at construction:
- DI container catches the exception
- `StripeOrderController::checkoutSuccess()` calls `$helper->validateContractToken()` which uses this service
- If service unavailable → token validation fails → user sees "Payment verification failed" → redirected to payment page
- This is **correct behavior** — if the secret is missing, we must not accept any contract tokens

No callers need modification. The exception at construction time prevents the service from being used with a weak secret.

### S2 Fix — Impact on PaymentController

`PaymentController::validatePayment()` calls `$this->stripeConfig->isConfigured()`. If it now returns `false` (missing webhook secret), the Stripe-specific validation block is skipped, and the standard OXID validation runs. This is correct — Stripe shouldn't accept payments if webhooks can't be verified.

### S4 Fix — Verification

```bash
grep -r "base_stripe_payment_controller_config" source/extensions/stripe/views/ --include="*.twig"
```

Must return zero active (non-commented) includes.

---

## Principles Applied

- **TDD:** Failing tests for S1, S2, S3 written before implementation
- **Fail-Closed (SOLID/OCP):** Security mechanisms must reject when preconditions aren't met, not fall back to insecure defaults
- **DIP:** ContractTokenService depends on `ModuleConfigurationServiceInterface` abstraction; enforces its contract explicitly
- **LSP:** The fixed `isConfigured()` returns a more honest result — callers that depend on it being true when webhooks work will now behave correctly
- **SRP:** Each fix touches exactly one responsibility — secret validation, configuration completeness, guard logging, dead code removal
- **DRY:** No new abstractions needed — existing interfaces and patterns are sufficient
- **Clean Code:** Dead code removed; security-relevant logic made explicit; no silent fallbacks
