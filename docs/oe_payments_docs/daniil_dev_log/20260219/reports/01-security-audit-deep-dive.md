# Security Audit Deep Dive — 2026-02-19

## Scope

Extended security audit of **payment-component** and **stripe-wallet** modules, building on the Sprint 58 security test suite (completed 2026-02-18). This report:

1. Identifies **new** security findings beyond F1–F11 (Sprint 58)
2. Cross-references with previous dev log findings (Sprints 48–57)
3. Verifies Sprint 58 completion status
4. Prioritizes remediation for upcoming sprints

---

## Sprint 58 Completion Verification

**Status: COMPLETE** — all deliverables implemented and verified.

| Deliverable | Status | Evidence |
|---|---|---|
| SecurityTestHelper.php | DONE | `tests/Security/Helper/SecurityTestHelper.php` |
| phpunit.xml updated | DONE | Security testsuite added |
| ContractStateMachine (4 tests) | DONE | 4 files, all passing |
| Webhook Security (4 tests) | DONE | 4 files, all passing |
| Crypto/BSI (3 tests) | DONE | 3 files, all passing |
| Auth (4 tests) | DONE | 4 files, all passing |
| PCI DSS (2 tests) | DONE | 2 files, all passing |
| DataProtection (4 tests) | DONE | 4 files, all passing |
| InputValidation (3 tests) | DONE | 3 files, all passing |
| Idempotency (3 tests) | DONE | 3 files, all passing |
| Session (2 tests) | DONE | 2 files, all passing |
| SecretManagement (2 tests) | DONE | 2 files, all passing |

**Final metrics:**
- Security tests: 256 tests, 464 assertions — ALL PASSING
- Existing Unit tests: 1115 tests, 2767 assertions — no regressions
- PHPCS (PSR-12): 0 errors, 0 warnings
- PHPStan (level max): 0 errors

**Action needed:** Update `20260218/todo/sprint-58-security-stress-tests.md` checkboxes and status to COMPLETE (done in this session).

---

## New Findings Beyond Sprint 58 (F12–F25)

### CRITICAL

#### F12: JWT Token Signature Not Verified
- **File:** `payment-component/src/Mcp/Auth/JwtTokenValidator.php`
- **Standard:** OWASP A07:2021, PCI DSS 6.5.10, RFC 7518
- **Description:** `JwtTokenValidator` decodes JWT payload via `base64_decode()` and validates claims (issuer, audience, expiration) but **never verifies the cryptographic signature**. An attacker can forge arbitrary JWTs with any claims.
- **Impact:** Complete authentication bypass for OAuth-based MCP access. Attacker forges JWT with `iss: "trusted-provider"` and any `sub` claim.
- **Sprint 58 coverage:** NOT COVERED — Sprint 58 tests only cover `McpAuthGuard` (static token) and `OAuthMcpAuthGuard` (delegation). JWT validation itself is untested for signature verification.
- **Remediation:** Implement JWKS-based signature verification using issuer's public key.

#### F13: MCP Error Responses Leak Internal Details
- **File:** `payment-component/src/Mcp/McpServer.php:132-137`
- **Standard:** OWASP A01:2021, PCI DSS 6.5.5
- **Description:** Tool execution errors return `exception_class`, `exception_message`, and `tool_name` in JSON-RPC responses. Reveals internal class structure to unauthenticated callers.
- **Impact:** Attackers learn application internals (namespace structure, error patterns) to craft targeted exploits.
- **Sprint 58 coverage:** NOT COVERED
- **Remediation:** Return generic error codes; log details server-side only.

#### F14: Open Redirect via ACP Order Permalink
- **File:** `stripe/src/Stripe/Mcp/Service/StripeAcpCheckoutService.php:94`
- **Standard:** PCI DSS 6.5.1, OWASP A01:2021
- **Description:** `$orderPermalink = $this->shopAdapter->getShopUrl() . '?cl=order_confirm&order=' . $orderId` — `$orderId` is concatenated without `urlencode()`. If `$orderId` contains `&redirect=https://attacker.com`, query string injection occurs.
- **Sprint 58 coverage:** NOT COVERED
- **Remediation:** Use `urlencode($orderId)` or proper URL builder.

### HIGH

#### F15: Amount Fields Lack Negative/Overflow Validation
- **File:** `payment-component/src/Contract/PaymentContract.php:381-395`
- **Standard:** PCI DSS 3.2 (transaction integrity)
- **Description:** `setCapturedAmount()` and `addRefundedAmount()` accept any float including negative, `INF`, `NAN`. Refund can exceed captured amount.
- **Impact:** Financial fraud — negative capture credits customer; refund > capture creates money.
- **Sprint 58 coverage:** PARTIAL — `CaptureIdempotencyTest` tests double-capture throws, but no negative/overflow tests.
- **Remediation:** Validate `$amount > 0`, `$amount < PHP_FLOAT_MAX`, refunded <= captured.

#### F16: Currency Not Validated Against ISO 4217
- **File:** `payment-component/src/Contract/BasketSnapshot.php:117-126`
- **Standard:** PCI DSS 6.5.1
- **Description:** `extractCurrency()` checks type is string but accepts any value — `"EUR'; DROP TABLE"` is valid.
- **Sprint 58 coverage:** NOT COVERED
- **Remediation:** Validate against ISO 4217 whitelist or Stripe's accepted currencies.

#### F17: Race Condition in Basket-to-Payment Flow
- **File:** `stripe/src/Stripe/Controller/StripeOrderController.php:46-50`
- **Standard:** OWASP A04:2021
- **Description:** Between `getBasketFromSession()` validation and checkout session creation, basket can be modified in a concurrent request (multi-tab attack). No content hash or optimistic lock.
- **Sprint 58 coverage:** NOT COVERED (Sprint 58 covers contract-level races, not basket-level)
- **Remediation:** Hash basket contents at validation time; verify hash before commitment.

#### F18: Webhook Endpoint Has No Rate Limiting
- **File:** `stripe/src/Stripe/Controller/Webhook/WebhookController.php:67-77`
- **Standard:** PCI DSS 10.2.1, OWASP A05:2021
- **Description:** Unlike `UcpCheckoutController` (has `RateLimiterInterface`), webhook endpoint accepts unlimited requests. Attacker can flood with invalid signatures causing CPU-intensive HMAC computations.
- **Sprint 58 coverage:** NOT COVERED — Sprint 58 tests webhook signature logic but not rate limiting.
- **Remediation:** Add rate limiter keyed on source IP.

#### F19: APCu Rate Limiter Fails Open
- **File:** `payment-component/src/Mcp/Http/ApcuRateLimiter.php:23-39`
- **Standard:** OWASP A05:2021
- **Description:** If APCu extension is unavailable, `isAllowed()` returns `true` for all requests. Silent degradation removes the only brute-force protection layer.
- **Sprint 58 coverage:** PARTIAL — `RateLimitBypassTest` mocks the interface but doesn't test APCu fallback behavior.
- **Remediation:** Fail secure — throw exception or deny if APCu unavailable.

#### F20: Bearer Token Empty String Bypass
- **File:** `payment-component/src/Mcp/Auth/McpAuthGuard.php:26`
- **Standard:** OWASP A07:2021
- **Description:** After `substr($header, 7)`, empty token is possible if header is exactly `"Bearer "`. If `$expectedToken` is also empty (misconfigured), `hash_equals('', '')` returns `true`.
- **Sprint 58 coverage:** PARTIAL — `McpAuthGuardSecurityTest::testRejectsEmptyToken()` tests missing header, but not `"Bearer "` with empty suffix.
- **Remediation:** Validate `trim($token) !== ''` before comparison.

### MEDIUM

#### F21: Unvalidated Payment Intent ID Format
- **File:** `stripe/src/Stripe/Controller/StripeOrderController.php:340-345`
- **Standard:** Input validation best practice
- **Description:** `getPaymentIntentIdFromRequest()` accepts any string from `$_GET`/`$_POST`. No validation against Stripe's `pi_` prefix format.
- **Sprint 58 coverage:** NOT COVERED
- **Remediation:** Validate with `preg_match('/^pi_[a-zA-Z0-9]+$/', $value)`.

#### F22: Unvalidated UCP Profile URL
- **File:** `payment-component/src/Mcp/Ucp/UcpRequestValidator.php:33-40`
- **Standard:** BSI TR-03116
- **Description:** Profile URL from `UCP-Agent` header extracted via regex but never validated as HTTPS URL. Accepts `data:`, `javascript:`, or arbitrary strings.
- **Sprint 58 coverage:** NOT COVERED
- **Remediation:** Validate URL scheme is HTTPS and domain is whitelisted.

#### F23: Exception Messages Logged Without Sanitization
- **Files:** Multiple — `AbstractPaymentCaptureService.php:77`, `AbstractWebhookProcessor.php:61`, `WebhookLogService.php:70`
- **Standard:** GDPR Art.25, OWASP A09:2021
- **Description:** `$e->getMessage()` logged directly. If exceptions contain user input or PII (email, DB connection strings), sensitive data enters log files.
- **Sprint 58 coverage:** NOT COVERED
- **Remediation:** Sanitize or redact exception messages before logging; use structured error codes.

#### F24: Missing Checkout Tool Schema Enforcement
- **File:** `payment-component/src/Mcp/Acp/Tool/CreateCheckoutTool.php:28-77`
- **Standard:** PCI DSS 6.5.1
- **Description:** JSON schema defines email format and required address fields, but validation is decorative — no runtime enforcement before passing to `AcpCheckoutServiceInterface`.
- **Sprint 58 coverage:** PARTIAL — `AcpEmailValidationTest` documents the gap (F8) but doesn't test schema enforcement.
- **Remediation:** Implement runtime validation matching the declared JSON schema.

#### F25: Hardcoded `oxidstripe` Payment ID in EarlyOrderCreationHandler
- **File:** `payment-component/src/EventSystem/Handler/EarlyOrderCreationHandler.php:107`
- **Standard:** Code correctness (previously fixed in Sprint 56b for other locations)
- **Description:** `$paymentId = 'oxidstripe'` is hardcoded instead of using `StripeDefinitions::STRIPE_WALLET_PAYMENT_ID` (`'oe_payments_stripe_wallet'`). This was the exact same bug fixed in Sprint 56b.
- **Sprint 58 coverage:** NOT COVERED (Sprint 58 focuses on security, not functional correctness)
- **Remediation:** Replace with `StripeDefinitions::STRIPE_WALLET_PAYMENT_ID`. This is a regression from Sprint 56b — the fix was applied to `AcpContextResolverHandler` but missed `EarlyOrderCreationHandler`.

---

## Cross-Reference with Previous Dev Logs

### From 20260218 Architecture Audit (7 findings)
| Arch Finding | Status | Security Implication |
|---|---|---|
| Direct Stripe API calls (CRITICAL) | OPEN | API key exposure in HTTP headers — relates to F1 |
| API key prefix in debug response (HIGH) | OPEN | Relates to F13 (information disclosure) |
| Registry access pattern (MEDIUM) | OPEN | No security impact |
| Service locator antipattern (MEDIUM) | OPEN | No security impact |
| Controller business logic (LOW) | OPEN | Increases attack surface |
| Duplicate code (LOW) | OPEN | No security impact |
| Method override issues (LOW) | OPEN | No security impact |

### From Sprint 56b (20260217) — Recurrence
- F25 (`oxidstripe` hardcoded) is the **same class of bug** fixed in Sprint 56b. The fix was applied to `AcpContextResolverHandler` but NOT to `EarlyOrderCreationHandler`. This indicates a pattern: fixes applied to one handler but not all handlers with the same issue.

---

## Consolidated Findings Matrix

### Sprint 58 Findings (F1–F11) — ALL with test coverage
| # | Finding | Severity | Tested |
|---|---------|----------|--------|
| F1 | API keys unencrypted in DB | CRITICAL | ApiKeyExposureTest |
| F2 | Webhook logs contain PII | HIGH | WebhookPiiLeakageTest |
| F3 | No webhook IP whitelist | MEDIUM | (documented, no test) |
| F4 | TOCTOU race in idempotency | HIGH | WebhookIdempotencyRaceTest |
| F5 | Double-fulfill race | HIGH | DuplicateFulfillmentTest |
| F6 | Metadata no schema/limit | MEDIUM | ContractMetadataTest, MetadataInjectionTest |
| F7 | No CSRF on payment endpoints | MEDIUM | CsrfProtectionAuditTest |
| F8 | ACP email not validated | MEDIUM | AcpEmailValidationTest |
| F9 | fromArray() bypasses guards | MEDIUM | ConditionFromArrayBypassTest |
| F10 | Hardcoded token secret fallback | HIGH | ContractTokenSecurityTest, ConfigurationSecurityTest |
| F11 | Client secret in session | MEDIUM | SessionVariableExposureTest |

### New Findings (F12–F25) — need test coverage
| # | Finding | Severity | Module | Needs Test |
|---|---------|----------|--------|------------|
| F12 | JWT signature not verified | CRITICAL | payment-component | YES |
| F13 | MCP error leaks internals | CRITICAL | payment-component | YES |
| F14 | Open redirect in permalink | CRITICAL | stripe | YES |
| F15 | Amount fields no validation | HIGH | payment-component | YES |
| F16 | Currency not validated | HIGH | payment-component | YES |
| F17 | Basket race condition | HIGH | stripe | YES |
| F18 | Webhook no rate limit | HIGH | stripe | YES |
| F19 | Rate limiter fails open | HIGH | payment-component | YES |
| F20 | Bearer empty string bypass | HIGH | payment-component | YES |
| F21 | Payment intent ID unvalidated | MEDIUM | stripe | YES |
| F22 | UCP profile URL unvalidated | MEDIUM | payment-component | YES |
| F23 | Exception messages in logs | MEDIUM | payment-component | YES |
| F24 | Schema not enforced at runtime | MEDIUM | payment-component | YES |
| F25 | `oxidstripe` regression | MEDIUM | payment-component | YES |

---

## Recommendations

### Immediate (Sprint 59)
1. **F12** — JWT signature verification is the most critical gap. Without it, OAuth MCP authentication is fundamentally broken.
2. **F14** — Open redirect fix is a one-line `urlencode()` call.
3. **F25** — `oxidstripe` regression is a one-line constant replacement — same fix as Sprint 56b.
4. **F20** — Empty bearer token bypass is a two-line validation.

### Next Sprint (Sprint 60)
5. **F13** — Remove internal details from MCP error responses.
6. **F15** — Amount validation (non-negative, finite, refund <= captured).
7. **F16** — Currency ISO 4217 whitelist.
8. **F19** — Rate limiter fail-secure.

### Backlog
9. **F17** — Basket content hash for race condition prevention.
10. **F18** — Webhook rate limiting.
11. **F21–F24** — Input validation hardening across controllers and tools.

---

## Positive Security Controls Confirmed

The audit also confirmed these architectural strengths:

1. **State machine enforcement** — No `setState()`, only named transitions. All 56+ invalid transitions tested.
2. **HMAC-SHA-256** — Webhook signatures and contract tokens use correct algorithm.
3. **Constant-time comparison** — `hash_equals()` used in auth guards and token service.
4. **Idempotency at domain level** — Contract `fulfill()` rejects double-fulfill with `DomainException`.
5. **Terminal state immutability** — 32 transition rejections tested for 4 terminal states.
6. **BasketSnapshot immutability** — Private constructor, no setters, returns copies.
7. **Agent ID derivation** — SHA-256 hash, no token leakage in agent identifier.
8. **PSR-12 + PHPStan level max** — Full static analysis compliance across all security tests.
