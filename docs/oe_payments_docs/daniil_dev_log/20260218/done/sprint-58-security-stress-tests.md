# Sprint 58: Security Stress Tests — PCI DSS / GDPR / BSI Compliance

**Status:** COMPLETE
**Date:** 2026-02-18
**Completed:** 2026-02-19
**Branch:** `b-7.4.x-mcp-STRP-88`

## Objective

Create a dedicated `Security` test suite that validates compliance with PCI DSS v4.0, EU GDPR/DSGVO, BSI TR-03116, and PSD2 SCA requirements. Tests serve dual purpose:
1. **Prove** architectural security strengths (state machine, HMAC, constant-time comparison)
2. **Convict** known gaps as documented findings (F1–F11) for audit evidence

## Security Findings Discovered During Audit

| # | Finding | Severity | Standard |
|---|---------|----------|----------|
| F1 | API keys stored unencrypted in OXID module config DB | CRITICAL | PCI DSS 3.5 |
| F2 | Webhook payload logged in full — contains PII (email, billing) | HIGH | GDPR Art.25 |
| F3 | No webhook source IP whitelist (signature-only) | MEDIUM | BSI TR-03116 |
| F4 | TOCTOU race in idempotency (check-then-insert, no SELECT FOR UPDATE) | HIGH | PCI DSS 10.2 |
| F5 | Concurrent webhooks can double-fulfill contract (no DB-level lock) | HIGH | PCI DSS 10.2 |
| F6 | Contract metadata accepts arbitrary data (no schema, no size limit) | MEDIUM | GDPR Art.25 |
| F7 | No CSRF token on executeStripePayment() / createCheckoutSession() | MEDIUM | PCI DSS 6.5.9 |
| F8 | ACP email not format-validated (accepts XSS/SQLi payloads) | MEDIUM | GDPR Art.25 |
| F9 | ContractCondition::fromArray() sets status directly (bypasses state guards) | MEDIUM | State machine integrity |
| F10 | ContractTokenService falls back to hardcoded secret when no API key | HIGH | BSI TR-03116 |
| F11 | Session stores stripe_client_secret (Stripe PI client_secret) | MEDIUM | PCI DSS 3.4 |

## Test Suite Structure

```
tests/Security/
  Helper/SecurityTestHelper.php
  PciDss/                          # PCI DSS compliance
  Webhook/                         # Webhook signature security
  ContractStateMachine/            # State machine integrity
  Auth/                            # Authentication & authorization
  DataProtection/                  # GDPR/DSGVO data protection
  InputValidation/                 # Input validation & injection
  Idempotency/                     # Race conditions & idempotency
  Session/                         # Session & CSRF security
  Crypto/                          # Cryptographic compliance (BSI)
  SecretManagement/                # Secret storage & exposure
```

## Implementation Plan

### Phase 1: Foundation
- [x] SecurityTestHelper.php — factory methods, timing helpers
- [x] Update phpunit.xml with Security testsuite

### Phase 2: Contract State Machine (pure domain, no mocks)
- [x] IllegalStateTransitionTest.php — full NxM matrix
- [x] TerminalStateImmutabilityTest.php — terminal states reject all
- [x] ConditionSecurityTest.php — condition lifecycle guards
- [x] ConditionFromArrayBypassTest.php — F9 documentation

### Phase 3: Crypto & Webhook Signature
- [x] SignatureVerificationSecurityTest.php
- [x] ReplayAttackTest.php
- [x] TimestampToleranceTest.php
- [x] PayloadTamperingTest.php
- [x] WebhookHmacComplianceTest.php
- [x] ContractTokenCryptoTest.php
- [x] HashAlgorithmStrengthTest.php

### Phase 4: Authentication
- [x] McpAuthGuardSecurityTest.php
- [x] BearerTokenTimingAttackTest.php
- [x] RateLimitBypassTest.php
- [x] ContractTokenSecurityTest.php

### Phase 5: Data Protection & Input Validation
- [x] WebhookPiiLeakageTest.php — F2
- [x] ContractMetadataTest.php — F6
- [x] BasketSnapshotImmutabilityTest.php
- [x] SessionSensitiveDataTest.php — F11
- [x] AcpEmailValidationTest.php — F8
- [x] MetadataInjectionTest.php — F6
- [x] ContractStateFromValueTest.php

### Phase 6: Idempotency & Race Conditions
- [x] WebhookIdempotencyRaceTest.php — F4
- [x] DuplicateFulfillmentTest.php — F5
- [x] CaptureIdempotencyTest.php

### Phase 7: Session & Secret Management
- [x] CsrfProtectionAuditTest.php — F7
- [x] SessionVariableExposureTest.php — F11
- [x] ApiKeyExposureTest.php — F1
- [x] ConfigurationSecurityTest.php — F10

## Verification

```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Security
```

## Final Results

- Security tests: **256 tests, 464 assertions** — ALL PASSING
- Existing Unit tests: **1115 tests, 2767 assertions** — no regressions
- PHPCS (PSR-12): **0 errors, 0 warnings**
- PHPStan (level max): **0 errors**

## Test Baseline Impact

- Before: 1115 tests, 2767 assertions
- After: 1371 tests (1115 + 256 security), 3231 assertions (2767 + 464)

## Follow-up

Deep dive audit on 2026-02-19 identified **14 additional findings (F12–F25)** beyond the original F1–F11. See `20260219/reports/01-security-audit-deep-dive.md` for details and remediation priorities.
