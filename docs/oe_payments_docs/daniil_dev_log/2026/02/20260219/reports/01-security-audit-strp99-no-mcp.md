# Security Audit Report: Branch `b-7.4.x-security-STRP-99` (No MCP)

**Date:** 2026-02-19
**Auditor:** Claude Code (automated static analysis + manual source review)
**Branch:** `b-7.4.x-security-STRP-99`
**Scope:** Stripe payment module (`source/extensions/stripe/`) + payment-component (`source/extensions/payment-component/`)
**Standards:** PCI DSS v4.0, GDPR/DSGVO, BSI TR-03116-4, OWASP Top 10 (2021), PSD2/SCA

---

## Executive Summary

This audit covers the Stripe payment module and its provider-agnostic payment-component on a branch **without MCP (Model Context Protocol)** code. The audit identified **28 findings** across 4 severity levels:

| Severity | Count | Description |
|----------|-------|-------------|
| CRITICAL | 5 | Immediate exploitation risk, data exposure |
| HIGH | 10 | Significant security weakness, compliance violation |
| MEDIUM | 9 | Defense-in-depth gap, hardening opportunity |
| LOW | 4 | Best-practice deviation, minor risk |

---

## Findings

### CRITICAL Findings

#### C1: API Key Prefixes Exposed in JSON Response and Logs

**File:** `src/Stripe/Controller/StripeOrderController.php:134-157`
**Standard:** PCI DSS 3.5.1 (Restrict access to cryptographic keys), BSI TR-03116
**CVSS:** 7.5

The `createCheckoutSession()` endpoint returns a `_debug` object containing API key prefixes in the JSON response body sent to the client:

```php
// Line 135-136: Secret key prefix in server log
$publishableKey = $config->getPublishableKey();
$secretKeyPrefix = substr($config->getToken(), 0, 12) . '...';

// Lines 147-158: Exposed to browser
echo json_encode([
    'id' => $context->get('checkoutSessionId'),
    'url' => $context->get('checkoutUrl'),
    '_debug' => [
        'pk_prefix' => substr($publishableKey, 0, 20),  // 20 chars of publishable key
        'sk_prefix' => $secretKeyPrefix,                  // 12 chars of SECRET key
        'testMode' => $config->isTestMode(),
        'keysValid' => $validator->validateKeyPair(),
    ],
]);
```

Additionally, lines 138-145 log the same data via `Registry::getLogger()->info()`.

**Impact:** Secret key prefix (`sk_test_` or `sk_live_` + 4 chars) leaked to every browser making a checkout request. Combined with test mode flag, narrows brute-force space. Log storage may be accessible to operators without PCI DSS clearance.

**Recommendation:** Remove `_debug` block entirely. Remove secret key logging. If debug info is needed, gate behind an admin-only debug flag that is OFF by default and never available in production/live mode.

---

#### C2: Weak ID Generation Using `uniqid()`

**File:** `payment-component/src/Model/AbstractModel.php:18`
**Also:** `payment-component/src/Repository/DoctrineTransactionRepository.php:151`, `payment-component/src/Webhook/WebhookLog.php:25`
**Standard:** BSI TR-03116-4 (Random number generation), PCI DSS 3.6.1
**CVSS:** 6.8

```php
protected function generateId(string $prefix = 'id'): string
{
    return uniqid($prefix . '_', true);
}
```

`uniqid()` is based on `gettimeofday()` (microsecond timestamp). It is:
- **Not cryptographically secure** (PRNG, not CSPRNG)
- **Predictable** if server time is known (within ~1M guesses)
- **Collisionable** under concurrent load

Used for: contract IDs, transaction IDs, webhook log IDs, refund IDs.

**Impact:** An attacker who knows approximate request time can predict contract/transaction IDs. Under high concurrency, ID collisions can cause data loss or duplicate processing.

**Recommendation:** Replace with `bin2hex(random_bytes(16))` or UUID v4 via `Ramsey\Uuid`. Example:
```php
protected function generateId(string $prefix = 'id'): string
{
    return $prefix . '_' . bin2hex(random_bytes(16));
}
```

---

#### C3: TOCTOU Race in Webhook Idempotency

**File:** `payment-component/src/Webhook/WebhookIdempotencyChecker.php:22-42`
**Standard:** PCI DSS 10.2 (Audit trail integrity), OWASP A04:2021
**CVSS:** 7.0

```php
public function isProcessed(string $eventId): bool
{
    return $this->repository->existsByEventId($eventId);  // CHECK
}

public function markAsProcessed(string $eventId, ...): void
{
    $this->repository->save(...);  // USE — gap between check and use
}
```

No `SELECT FOR UPDATE`, no database-level unique constraint enforcement at the application layer. Two concurrent webhooks with the same event ID can both pass `isProcessed()` = false and both proceed to process.

**Impact:** Double-fulfillment of contracts, duplicate order creation, double-capture of payments.

**Recommendation:** Use `INSERT ... ON DUPLICATE KEY` pattern or wrap check+insert in a single transaction with `SELECT FOR UPDATE`. Alternatively, rely on a UNIQUE constraint on `event_id` and catch the constraint violation.

---

#### C4: Refund Amount Not Validated Against Captured Amount

**File:** `payment-component/src/Service/AbstractPaymentRefundService.php:151-186`
**Standard:** PCI DSS 6.5.5 (Improper error handling)
**CVSS:** 6.5

The refund validation compares against basket `totalGross` instead of `getCapturedAmount()`:

```php
// Uses basket total, not actual captured amount
$maxRefundable = $contract->getBasketSnapshot()->getTotalGross();
```

If a partial capture was performed (e.g., captured 50 of 100), the refund limit is still 100, allowing a refund exceeding the captured amount.

**Impact:** Financial loss — refunding more than was captured. Stripe API will reject it, but the contract state may become inconsistent.

**Recommendation:** Use `$contract->getCapturedAmount() - $contract->getRefundedAmount()` as the refund ceiling.

---

#### C5: No Amount Validation for NaN/Infinity/Negative Values

**File:** `payment-component/src/Contract/BasketSnapshot.php:102-126`
**Also:** `payment-component/src/Service/AbstractPaymentCaptureService.php`
**Standard:** PCI DSS 6.5.1 (Input validation), BSI Web Application Security
**CVSS:** 6.0

```php
private static function extractFloat(array $data, string $key): float
{
    // Accepts NAN, INF, -INF, negative values
    return (float) $value;
}
```

No guard against:
- `NAN` / `INF` / `-INF` (IEEE 754 special values)
- Negative amounts (refund injection)
- Zero amounts (free-order bypass)

**Impact:** A crafted basket snapshot with `NAN` total would bypass all amount comparisons (`NAN != NAN` is always true). Negative amounts could create credits. Zero amounts could bypass payment entirely.

**Recommendation:** Add validation in `extractFloat()`:
```php
if (!is_finite($value) || $value < 0) {
    throw new \InvalidArgumentException("Invalid amount: $key must be a non-negative finite number");
}
```

---

### HIGH Findings

#### H1: DumpExtension Available in Production

**File:** `src/Stripe/Twig/DumpExtension.php` (full file)
**Standard:** OWASP A05:2021 (Security Misconfiguration)
**CVSS:** 5.5

```php
public function getFunctions(): array
{
    return [
        new TwigFunction('dump', [$this, 'dump'], ['is_safe' => ['html']]),
        new TwigFunction('dd', [$this, 'dumpAndDie'], ['is_safe' => ['html']]),
    ];
}

public function dumpAndDie(...$vars): string
{
    $output = $this->dump(...$vars);
    die($output);  // Kills request
}
```

- `dump()` outputs `var_export()` wrapped in `<pre>` — **marked `is_safe => ['html']`**, bypassing Twig auto-escaping
- `dd()` calls `die()`, enabling denial-of-service if triggered in templates
- No environment check — available in production

**Impact:** If any template uses `{{ dump(variable) }}`, internal data structures are exposed to the browser. `dd()` can halt request processing.

**Recommendation:** Either remove entirely, or gate behind `APP_ENV === 'development'` check in `getFunctions()`. Never use `is_safe => ['html']` on debug output.

---

#### H2: Capture Mode Override from Request Parameter

**File:** `src/Stripe/Controller/StripeOrderController.php:355-358`
**Standard:** PCI DSS 6.5.4 (Insecure direct object references)
**CVSS:** 5.0

```php
$override = Registry::getRequest()->getRequestParameter('capture_mode_override');
if (is_string($override) && in_array($override, ['automatic', 'manual'], true)) {
    return $override;
}
```

Any unauthenticated user can add `?capture_mode_override=manual` to switch from automatic to manual capture, or vice versa. This bypasses the merchant's configured capture strategy.

**Impact:** Attacker sets `capture_mode_override=manual` → payment is authorized but not captured → merchant doesn't receive funds. Or sets `automatic` when merchant wants manual review → funds captured without review.

**Recommendation:** Remove the override parameter entirely. If needed for testing, gate behind admin authentication AND test mode.

---

#### H3: Contract Tokens from URL Not Validated

**File:** `src/Stripe/Controller/StripeOrderController.php:183-191`
**Standard:** OWASP A01:2021 (Broken Access Control)
**CVSS:** 5.5

```php
$contractId = Registry::getRequest()->getRequestParameter('contract_id');
$contractToken = Registry::getRequest()->getRequestParameter('contract_token');

$context = new EventContext([
    'checkoutSessionId' => $sessionId,
    'contract_id' => $contractId,        // Unvalidated
    'contract_token' => $contractToken,  // Unvalidated
    'contractId' => $this->getContractIdFromSession(),
]);
```

`contract_id` and `contract_token` are taken directly from URL query parameters without any validation (format, HMAC, binding to session). They are passed into the event context where handlers may use them.

**Impact:** An attacker could substitute their own `contract_id` to bind a Stripe checkout session to a different contract, potentially claiming someone else's payment.

**Recommendation:** Validate `contract_token` HMAC against `contract_id` before passing to event context. Verify the contract_id matches the session-stored contract_id.

---

#### H4: Currency Not Validated Against ISO 4217

**File:** `payment-component/src/Contract/BasketSnapshot.php:115-126`
**Standard:** PCI DSS 6.5.1 (Input validation), ISO 4217
**CVSS:** 4.5

```php
private static function extractCurrency(array $data): string
{
    if (!isset($data['currency']) || !is_string($data['currency'])) {
        throw new \InvalidArgumentException('Missing currency');
    }
    return $data['currency'];  // No format validation
}
```

Accepts any string as currency: `XXXXX`, `<script>`, `'; DROP TABLE--`. While Stripe API will reject invalid currencies, the value is stored in the database and may be rendered in admin views.

**Recommendation:** Validate against `preg_match('/^[A-Z]{3}$/', $currency)` at minimum, or a whitelist of ISO 4217 codes.

---

#### H5: State Machine Bypass via `ContractCondition::fromArray()`

**File:** `payment-component/src/Contract/ContractCondition.php:215-238`
**Standard:** OWASP A04:2021 (Insecure Design)
**CVSS:** 5.0

```php
public static function fromArray(array $data): self
{
    $condition = new self($data['type']);
    if (isset($data['status'])) {
        $condition->status = $data['status'];  // Direct assignment, bypasses fulfill() guards
    }
    // ...
}
```

The `fulfill()` method enforces business rules (can't re-fulfill, can't fulfill failed). `fromArray()` bypasses all guards by setting `status` directly. While `fromArray()` is intended for deserialization, it accepts arbitrary status values including invalid ones.

**Recommendation:** Validate status values in `fromArray()`. Consider using `ContractConditionStatus::fromValue()` which rejects invalid values.

---

#### H6: PII Stored in Basket Snapshot Without Field Whitelist

**File:** `payment-component/src/Contract/BasketSnapshot.php`
**Standard:** GDPR Art. 5(1)(c) (Data minimization), GDPR Art. 25 (Data protection by design)
**CVSS:** 4.0

`BasketSnapshot::fromArray()` accepts and stores any data passed to it. The basket snapshot is persisted in `oe_payments_contract.OXBASKETDATA` as JSON. If the OXID basket contains PII (customer name, address, email), it is stored without:
- Field whitelist (only store what's needed: items, amounts, currency)
- Retention policy (no automatic deletion/anonymization)
- Encryption at rest

**Impact:** GDPR right-to-erasure requests require locating and scrubbing PII from all basket snapshots. Data minimization principle violated.

**Recommendation:** Define explicit field whitelist for BasketSnapshot. Strip PII before serialization. Implement retention/anonymization policy.

---

#### H7: Sensitive Data in Logs (Full Webhook Payloads)

**File:** `payment-component/src/Repository/DoctrineWebhookLogRepository.php:42`
**Also:** `payment-component/src/Service/RequestLogService.php`
**Standard:** GDPR Art. 5(1)(c), PCI DSS 3.4 (Render PAN unreadable)
**CVSS:** 4.5

Full webhook payloads are stored in `oe_payments_webhooklogs.PAYLOAD` without sanitization. Stripe webhook payloads for `checkout.session.completed` contain:
- `customer_details.email`
- `customer_details.name`
- `shipping.address` (full postal address)
- `payment_method_details` (last4, brand, exp_month/year)

**Impact:** Webhook log table becomes a PII repository. Any database breach exposes customer data. Violates GDPR data minimization.

**Recommendation:** Redact PII fields before storage. Store only: event ID, event type, payment intent ID, amount, currency, status. Hash or remove customer details.

---

#### H8: No CSRF Token on Payment Endpoints

**File:** `src/Stripe/Controller/StripeOrderController.php:43, 86`
**Standard:** PCI DSS 6.5.9 (Cross-site request forgery), OWASP A01:2021
**CVSS:** 4.0

`executeStripePayment()` and `createCheckoutSession()` do not validate OXID's `stoken` (session CSRF token). While OXID's base `OrderController` may check this in `execute()`, the Stripe-specific methods bypass it.

**Impact:** An attacker can craft a page that auto-submits a payment form, potentially creating checkout sessions or executing payments using the victim's session.

**Recommendation:** Validate `stoken` at the beginning of both methods using `Registry::getSession()->checkSessionChallenge()`.

---

#### H9: Webhook Signature Verification Not Enforced at Controller Level

**File:** `payment-component/src/Webhook/WebhookProcessor.php`
**Standard:** PCI DSS 6.5.10 (Broken authentication), Stripe Security Best Practices
**CVSS:** 5.0

The `WebhookProcessor` processes events but signature verification is handled by the Stripe adapter. If the adapter is misconfigured or the webhook secret is empty, events may be processed without signature verification.

**Recommendation:** Add a mandatory signature verification step in `WebhookProcessor` before any event processing. Fail closed (reject) if signature cannot be verified.

---

#### H10: Session Stores Stripe Client Secret

**File:** `src/Stripe/Controller/StripeOrderController.php:128-131, 276-280`
**Standard:** PCI DSS 3.4 (Protect stored data), BSI TR-03116
**CVSS:** 4.0

```php
// Stored in session:
$this->setSessionVariable('stripe_checkout_session_id', $sessionId);
$this->setSessionVariable('stripe_contract_id', $contractId);
// Also stored (per session variable names in clearStripeSessionVariables):
// stripe_payment_intent_id
// stripe_client_secret
```

The `stripe_client_secret` (PaymentIntent client secret) is stored in PHP session. If session storage is file-based (default OXID), this is stored on disk in plain text under `/tmp/` or the configured session directory.

**Impact:** Server-side file read vulnerability or shared hosting = client secrets exposed. Client secret allows confirming/canceling the PaymentIntent.

**Recommendation:** Avoid server-side storage of client secrets. Pass them directly to the frontend via the initial AJAX response and do not persist.

---

### MEDIUM Findings

#### M1: XSS in Admin Error Messages

**File:** `src/Stripe/views/admin/tpl/stripe_order_refund.html.twig`
**Standard:** OWASP A03:2021 (Injection), PCI DSS 6.5.7
**CVSS:** 3.5

Admin templates may render error messages without proper Twig escaping. If error messages contain user-controlled data (e.g., Stripe API error messages that echo back input), XSS is possible.

**Recommendation:** Ensure all `{{ variable }}` uses Twig auto-escaping (default). Audit for `{{ variable|raw }}` usage. Never use `|raw` on error messages.

---

#### M2: Dev Mode Check Relies on Environment Variable

**File:** `src/Stripe/Core/ViewConfig.php`
**Standard:** OWASP A05:2021 (Security Misconfiguration)

Development mode detection based on `$_ENV` or `$_SERVER` variables can be manipulated via `.htaccess` or web server misconfiguration.

**Recommendation:** Use OXID's built-in configuration mechanism or a compiled constant that cannot be changed at runtime.

---

#### M3: File Permissions Too Permissive

**File:** `payment-component/src/Service/FileLogger.php:41-47`
**Standard:** BSI IT-Grundschutz SYS.1.3, PCI DSS 7.1
**CVSS:** 3.0

```php
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);  // World-readable
}
```

Log directories created with `0755` — readable by all users on the system. Log files may contain payment data, webhook payloads, error details.

**Recommendation:** Use `0750` for directories and `0640` for files. Ensure log directory ownership matches the web server user.

---

#### M4: Weak DateTime Parsing in PaymentContract

**File:** `payment-component/src/Contract/PaymentContract.php`
**Standard:** OWASP A08:2021 (Software and Data Integrity)

DateTime values constructed from unvalidated strings. Malformed date strings can create unexpected `DateTime` objects (e.g., `new \DateTime('next year')` is valid).

**Recommendation:** Use `\DateTimeImmutable::createFromFormat()` with strict format validation. Reject relative date strings.

---

#### M5: No State Validation on Amount Modifications

**File:** `payment-component/src/Contract/PaymentContract.php:381-418`
**Standard:** PCI DSS 6.5.5 (Business logic flaws)
**CVSS:** 4.0

```php
public function setCapturedAmount(float $amount): void
{
    $this->capturedAmount = $amount;  // No state check
    $this->touch();
}

public function addRefundedAmount(float $amount): void
{
    $this->refundedAmount += $amount;  // No state check
    $this->touch();
}
```

- `setCapturedAmount()` can be called in any state, including `DRAFT` or `CANCELLED`
- `addRefundedAmount()` has no guard against calling on unfulfilled contracts
- Neither validates that amount is positive and finite

**Recommendation:** Guard `setCapturedAmount()` to only work in `COMMITTED` or `FULFILLED` states. Guard `addRefundedAmount()` to only work in `FULFILLED` state. Validate `$amount > 0 && is_finite($amount)`.

---

#### M6: No HTTPS Enforcement on Webhook Endpoint

**File:** Webhook controller (no explicit TLS check)
**Standard:** PCI DSS 4.1 (Encrypt transmission), BSI TR-03116-4
**CVSS:** 3.5

The webhook endpoint does not verify that the request arrived over HTTPS. While Stripe only sends to HTTPS URLs, a misconfigured reverse proxy could strip TLS and forward as HTTP.

**Recommendation:** Check `$_SERVER['HTTPS']` or `X-Forwarded-Proto` header. Reject non-HTTPS webhook requests.

---

#### M7: No Rate Limiting on Payment Endpoints

**File:** `src/Stripe/Controller/StripeOrderController.php`
**Standard:** OWASP A04:2021, BSI Web Application Security
**CVSS:** 3.0

`createCheckoutSession()` and `executeStripePayment()` have no rate limiting. An attacker can create unlimited Stripe Checkout Sessions, potentially:
- Generating Stripe API costs
- Exhausting Stripe rate limits for the merchant
- Creating orphaned sessions

**Recommendation:** Implement rate limiting per session/IP on payment endpoints. 5-10 requests per minute is reasonable.

---

#### M8: Webhook Payload Size Not Limited

**File:** Webhook controller
**Standard:** OWASP A05:2021, BSI Web Application Security
**CVSS:** 2.5

No `Content-Length` check or `php://input` read limit on webhook payloads. A large payload (e.g., 100MB) could exhaust memory.

**Recommendation:** Limit `php://input` reads to 64KB (Stripe webhooks are typically < 10KB). Reject oversized payloads before parsing.

---

#### M9: Delivery Address Hash Not Cryptographically Bound

**File:** `payment-component/src/Contract/DeliveryAddressHash.php`
**Standard:** BSI TR-03116-4

If the delivery address hash uses a weak algorithm (MD5/SHA1) or doesn't include a secret, an attacker could forge a matching hash for a different address.

**Recommendation:** Use HMAC-SHA-256 with a server-side secret for address hash computation.

---

### LOW Findings

#### L1: API Keys Stored Unencrypted in Database

**File:** Module configuration via OXID `oxconfig` table
**Standard:** PCI DSS 3.5.1 (Key management)
**CVSS:** 2.0

Stripe API keys (`sStripeTestToken`, `sStripeLiveToken`, `sStripeWebhookEndpointSecret`) are stored in OXID's `oxconfig` table. OXID applies its own DECODE/ENCODE mechanism, but this is not true encryption — it uses a predictable key derived from config file values.

**Impact:** Database dump exposes API keys. Shared hosting environments increase risk.

**Recommendation:** Document this as an accepted risk in the module's security documentation. For high-security deployments, recommend environment-variable-based key injection.

---

#### L2: No Content Security Policy Headers on AJAX Responses

**File:** `src/Stripe/Controller/StripeOrderController.php:88`
**Standard:** OWASP A05:2021

`createCheckoutSession()` sets `Content-Type: application/json` but no CSP or X-Content-Type-Options headers. While JSON responses are generally safe, adding `X-Content-Type-Options: nosniff` prevents MIME-type sniffing.

**Recommendation:** Add `header('X-Content-Type-Options: nosniff')` to AJAX endpoints.

---

#### L3: Error Messages May Leak Internal Paths

**File:** `src/Stripe/Controller/StripeOrderController.php:161`
**Standard:** OWASP A09:2021 (Security Logging and Monitoring)

```php
echo json_encode(['error' => $e->getMessage()]);
```

Exception messages from Stripe SDK or internal services are returned directly to the client. These may contain file paths, class names, or database details.

**Recommendation:** Return generic error messages to clients. Log detailed errors server-side only.

---

#### L4: No Audit Trail for Configuration Changes

**File:** Module configuration services
**Standard:** PCI DSS 10.2.7 (Creation/deletion of system-level objects)

Changes to Stripe API keys, webhook secrets, and capture mode are not logged. An insider could change the API key to their own account and intercept payments.

**Recommendation:** Log all configuration changes with timestamp, admin user ID, and old/new value hash (not the actual key).

---

## Compliance Summary

### PCI DSS v4.0

| Requirement | Status | Findings |
|-------------|--------|----------|
| 3.4 — Render PAN unreadable | PASS | No card data stored (Stripe tokenization) |
| 3.5 — Protect cryptographic keys | FAIL | C1 (key prefix exposure), L1 (unencrypted storage) |
| 3.6 — Key management | FAIL | C2 (weak ID generation) |
| 4.1 — Encrypt transmission | WARN | M6 (no HTTPS enforcement) |
| 6.5.1 — Injection | WARN | C5 (amount validation), H4 (currency) |
| 6.5.4 — Insecure direct object ref | FAIL | H2 (capture mode override) |
| 6.5.7 — XSS | WARN | M1 (admin templates), H1 (DumpExtension) |
| 6.5.9 — CSRF | FAIL | H8 (no stoken validation) |
| 6.5.10 — Broken auth | WARN | H9 (signature not enforced) |
| 10.2 — Audit trails | FAIL | C3 (TOCTOU), L4 (no config audit) |

### GDPR / DSGVO

| Article | Status | Findings |
|---------|--------|----------|
| Art. 5(1)(c) — Data minimization | FAIL | H6 (PII in snapshots), H7 (full payloads in logs) |
| Art. 17 — Right to erasure | WARN | No mechanism to find/delete PII across snapshots and logs |
| Art. 25 — Data protection by design | FAIL | H6, H7, H4, C1 |
| Art. 32 — Security of processing | WARN | C2 (weak IDs), C3 (race conditions) |
| Art. 33 — Breach notification | N/A | No breach detection mechanism, but out of module scope |

### BSI TR-03116-4

| Requirement | Status | Findings |
|-------------|--------|----------|
| Random number generation | FAIL | C2 (`uniqid()` instead of CSPRNG) |
| Cryptographic algorithms | WARN | M9 (delivery hash algorithm unknown) |
| TLS enforcement | WARN | M6 (no HTTPS check on webhook) |
| Key management | WARN | L1 (OXID encoding, not encryption) |
| Logging & monitoring | WARN | H7 (PII in logs), L4 (no config audit) |

---

## Remediation Priority

### Immediate (before production deployment)

1. **C1** — Remove `_debug` block and secret key logging from `StripeOrderController::createCheckoutSession()`
2. **H1** — Remove or gate `DumpExtension` behind development environment check
3. **H2** — Remove `capture_mode_override` request parameter
4. **C5** — Add `is_finite()` + non-negative validation in `BasketSnapshot::extractFloat()`
5. **H3** — Validate contract tokens against HMAC before use

### Short-term (next sprint)

6. **C2** — Replace all `uniqid()` with `bin2hex(random_bytes(16))`
7. **C3** — Implement atomic idempotency check (INSERT ON DUPLICATE KEY or SELECT FOR UPDATE)
8. **C4** — Fix refund limit to use `getCapturedAmount()` instead of basket total
9. **H8** — Add `stoken` validation to payment controller methods
10. **H7** — Implement PII redaction in webhook log storage

### Medium-term (next quarter)

11. **H4** — ISO 4217 currency validation
12. **H5** — Validate status values in `ContractCondition::fromArray()`
13. **H6** — Field whitelist for BasketSnapshot
14. **M5** — State guards on `setCapturedAmount()` / `addRefundedAmount()`
15. **M3** — Tighten file permissions to 0750/0640

### Long-term (roadmap)

16. **L1** — Environment-variable-based key injection option
17. **L4** — Configuration change audit trail
18. **M9** — HMAC-SHA-256 for delivery address hash
19. **H9** — Enforce webhook signature verification at processor level
20. **H10** — Eliminate server-side client secret storage

---

## Test Coverage Recommendations

Each finding should have a corresponding security test that:
- **Documents** the current (vulnerable) behavior
- **Becomes a regression test** when the fix is implemented
- Is annotated with the compliance standard (`@group security, pci-dss` or `@group security, gdpr`)

Suggested test suite structure:
```
tests/Security/
  PciDss/          — C1, C4, C5, L1
  Webhook/         — C3, H7, H9, M8
  StateMachine/    — H5, M5
  InputValidation/ — H2, H3, H4, M1
  DataProtection/  — H6, H7, H10
  Crypto/          — C2, M9
  Session/         — H8, H10
```

---

## Appendix: Files Reviewed

### Stripe Module (`source/extensions/stripe/src/`)
- `Stripe/Controller/StripeOrderController.php`
- `Stripe/Controller/WebhookController.php`
- `Stripe/Twig/DumpExtension.php`
- `Stripe/Core/ViewConfig.php`
- `Stripe/Service/ModuleConfigurationService.php`
- `Stripe/Service/ConfigurationValidator.php`
- `Stripe/views/admin/tpl/stripe_order_refund.html.twig`

### Payment Component (`source/extensions/payment-component/src/`)
- `Contract/PaymentContract.php`
- `Contract/BasketSnapshot.php`
- `Contract/ContractCondition.php`
- `Contract/DeliveryAddressHash.php`
- `Model/AbstractModel.php`
- `Webhook/WebhookIdempotencyChecker.php`
- `Webhook/WebhookProcessor.php`
- `Webhook/WebhookLog.php`
- `Service/AbstractPaymentCaptureService.php`
- `Service/AbstractPaymentRefundService.php`
- `Service/FileLogger.php`
- `Service/RequestLogService.php`
- `Repository/DoctrineTransactionRepository.php`
- `Repository/DoctrineWebhookLogRepository.php`

### Configuration
- `metadata.php`
- `services.yaml`
