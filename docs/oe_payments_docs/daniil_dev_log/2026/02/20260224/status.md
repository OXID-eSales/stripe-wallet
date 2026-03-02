# STRP-99 Security Remediation — Status Tracker

**Date:** 2026-02-24
**Branch:** `b-7.4.x-security-STRP-99`
**Audit source:** `20260219/reports/01-security-audit-strp99-no-mcp.md`

---

## Active Sprint Queue

| Sprint | Title | Findings | Status |
|--------|-------|----------|--------|
| 64a | Guard Infrastructure + Payload Size Guard (TDD) | M8 | DONE |
| 64b | Webhook Rate Limiter (TDD) | M7 | DONE |
| 64c | Webhook IP Allowlist Guard (TDD) | H9 | DONE |
| 64d | WebhookController Guard Integration (TDD) | M7+M8+H9 | DONE |
| 64e | CSRF on Admin Endpoints (TDD) | H8 | DONE |
| 64f | CSRF on Frontend AJAX + JS (TDD) | H8 | DONE |
| 64g | Atomic Idempotency / TOCTOU Race (TDD) | C3 | DONE |
| 64h | Automated Pentest Verification (DevOps) | All | DONE |
| 67a | Contract Token Validation (TDD) | H3 | DONE |
| 67b | Webhook HTTPS Guard (TDD) | M6 | DONE |
| 68a | State Machine Guard on fromArray() (TDD) | H5 | DONE |
| 68b | Address Hash HMAC Binding (TDD) | M9 | DONE |
| 69a | Webhook Payload PII Redaction (TDD) | H7 | DONE |
| 69b | Basket Snapshot PII Whitelist (TDD) | H6 | DONE |
| 70a | Dev Mode Domain Matching Fix (TDD) | M2 | DONE |
| 70b | Restrictive File Permissions (TDD) | M3 | DONE |

**Plans:**
- `sprints/sprint-64-pentest-stress-plan.md` (~45 new tests, 17 new files, 9 modified)
- `sprints/sprint-67-70-security-remediation-plan.md` (~52 new tests, 11 new files, 10 modified)

---

## All Findings Tracker

| ID | Severity | CVSS | Title | Sprint | Status |
|----|----------|------|-------|--------|--------|
| C1 | CRITICAL | 7.5 | API Key Prefixes Exposed | 63a | DONE (pre-existing) |
| C2 | CRITICAL | 6.8 | Weak ID Generation (`uniqid()`) | — | DONE (pre-existing) |
| C3 | CRITICAL | 7.0 | TOCTOU Race in Webhook Idempotency | 64g | DONE |
| C4 | CRITICAL | 6.5 | Refund Amount vs Captured Amount | — | DONE (pre-existing) |
| C5 | CRITICAL | 6.0 | NaN/Infinity/Negative Amounts | 63c | DONE (pre-existing) |
| H1 | HIGH | 5.5 | DumpExtension in Production | 63d | DONE |
| H2 | HIGH | 5.0 | Capture Mode Override from URL | 63b | DONE (pre-existing) |
| H3 | HIGH | 5.5 | Contract Tokens Not Validated | 67a | DONE |
| H4 | HIGH | 4.5 | Currency Not ISO 4217 | — | DONE (pre-existing) |
| H5 | HIGH | 5.0 | State Machine Bypass via fromArray() | 68a | DONE |
| H6 | HIGH | 4.0 | PII in Basket Snapshot | 69b | DONE |
| H7 | HIGH | 4.5 | PII in Webhook Logs | 69a | DONE |
| H8 | HIGH | 4.0 | No CSRF on Payment Endpoints | 64e+64f | DONE |
| H9 | HIGH | 5.0 | Webhook Signature Not Enforced | — | DONE (pre-existing secure) |
| H10 | HIGH | 4.0 | Client Secret in Session | — | DONE (pre-existing secure) |
| M1 | MEDIUM | 3.5 | XSS in Admin Error Messages | — | DONE (pre-existing secure) |
| M2 | MEDIUM | — | Dev Mode Domain Matching | 70a | DONE |
| M3 | MEDIUM | 3.0 | File Permissions (0755) | 70b | DONE |
| M4 | MEDIUM | — | Weak DateTime Parsing | — | DONE (pre-existing secure) |
| M5 | MEDIUM | 4.0 | No State Guard on Amounts | — | DONE (tests fixed) |
| M6 | MEDIUM | 3.5 | No HTTPS on Webhook | 67b | DONE |
| M7 | MEDIUM | 3.0 | No Rate Limiting | 64b | DONE |
| M8 | MEDIUM | 2.5 | Webhook Payload Size Unlimited | 64a | DONE |
| M9 | MEDIUM | — | Address Hash Not HMAC | 68b | DONE |
| L1 | LOW | 2.0 | API Keys Unencrypted in DB | 71 | PLANNED |
| L2 | LOW | — | No CSP on AJAX Responses | 71 | PLANNED |
| L3 | LOW | — | Error Messages Leak Paths | 71 | PLANNED |
| L4 | LOW | — | No Config Change Audit Trail | 71 | PLANNED |

**Summary:** 24/28 findings DONE (7 pre-existing + 4 pre-existing secure + 2 Sprint 63 + M5 + 4 Sprint 64 + 8 Sprint 67-70), 4 PLANNED (all LOW).

---

## Pre-Existing Secure Findings (Sprint 67-70 Pre-Sprint)

These 4 findings required no code changes — the codebase already handles them securely:

| Finding | Why Already Secure | Evidence |
|---------|-------------------|----------|
| **H9** | `StripeWebhookProcessor::parseAndValidateRequest()` calls `Webhook::constructEvent()` with mandatory secret. `SignatureVerificationException` → fail-closed. | `src/Stripe/Webhook/StripeWebhookProcessor.php:64-85` |
| **H10** | Client secret passed through `EventContext` (in-memory) to template param. NOT stored in `$_SESSION`. | `StripePaymentStatusHandler.php:152`, `StripeOrderController.php:259` |
| **M1** | Twig auto-escaping ON. No `\|raw` on error messages. `{{ oView.getErrorMessage() }}` is auto-escaped. | `views/twig/admin/stripe_order_refund.html.twig:102` |
| **M4** | All production code uses `new DateTimeImmutable('@' . $timestamp)` (safe) or `new DateTimeImmutable()` (current time). Only test files have string-based DateTime. | Grep confirms: no unvalidated string→DateTime in production |

---

## Sprint History

| Sprint | Finding(s) | Date Started | Date Completed | Report |
|--------|-----------|--------------|----------------|--------|
| 62 | H1 (plan only) | 2026-02-20 | — | Plan: `20260220/todo/sprint-62-h1-dump-extension.md` |
| 63a | C1 | 2026-02-23 | 2026-02-23 | ALREADY FIXED — `reports/02-sprint-63abc-already-fixed.md` |
| 63b | H2 | 2026-02-23 | 2026-02-23 | ALREADY FIXED — `reports/02-sprint-63abc-already-fixed.md` |
| 63c | C5 | 2026-02-23 | 2026-02-23 | ALREADY FIXED — `reports/02-sprint-63abc-already-fixed.md` |
| 63d | H1 | 2026-02-23 | 2026-02-23 | `reports/03-sprint-63d-h1-dump-extension-completed.md` |
| 63e | M5 test fixes | 2026-02-23 | 2026-02-23 | `reports/04-sprint-63e-m5-test-fixes.md` |
| 64a | M8 | 2026-02-24 | 2026-02-24 | `reports/sprint-64a-guard-infrastructure.md` |
| 64b | M7 | 2026-02-24 | 2026-02-24 | `reports/sprint-64b-rate-limiter.md` |
| 64c | H9 | 2026-02-24 | 2026-02-24 | `reports/sprint-64c-ip-allowlist.md` |
| 64d | M7+M8+H9 | 2026-02-24 | 2026-02-24 | `reports/sprint-64d-controller-integration.md` |
| 64e | H8 (admin) | 2026-02-24 | 2026-02-24 | `reports/sprint-64e-admin-csrf.md` |
| 64f | H8 (frontend) | 2026-02-24 | 2026-02-24 | `reports/sprint-64f-frontend-csrf.md` |
| 64g | C3 | 2026-02-24 | 2026-02-24 | `reports/sprint-64g-atomic-idempotency.md` |
| 64h | All | 2026-02-24 | 2026-02-24 | `reports/sprint-64h-pentest-verification.md` |
| 67a | H3 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |
| 67b | M6 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |
| 68a | H5 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |
| 68b | M9 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |
| 69a | H7 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |
| 69b | H6 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |
| 70a | M2 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |
| 70b | M3 | 2026-02-24 | 2026-02-24 | `sprints/sprint-67-70-security-remediation-plan.md` |

---

## Test Baseline

| Metric | Value | Updated |
|--------|-------|---------|
| Stripe security tests (@group security) | 52 | 2026-02-24 |
| Stripe security assertions | 86 | 2026-02-24 |
| Stripe failures | 0 | 2026-02-24 |
| payment-component unit tests | 742 | 2026-02-24 |
| payment-component failures | 0 | 2026-02-24 |

### Sprint 64 New Tests Summary

| Sub-Sprint | Tests | Assertions |
|------------|-------|------------|
| 64a Guard infra + payload | 12 | 19 |
| 64b Rate limiter | 4 | 9 |
| 64c IP allowlist | 8 | 13 |
| 64d Controller integration | 5 | 11 |
| 64e Admin CSRF | 6 | 12 |
| 64f Frontend CSRF | 5 | 8 |
| 64g Atomic idempotency | 5 | ~14 |
| 64h Pentest script | 0 (shell) | — |
| **Total** | **45** | **~86** |

### Sprint 67-70 New Tests Summary

| Sub-Sprint | Tests | Assertions |
|------------|-------|------------|
| 67a Token validation | 5 | ~10 |
| 67b HTTPS guard | 6 | ~10 |
| 68a State machine guard | 6 | ~12 |
| 68b Address HMAC | 6 | ~10 |
| 69a Webhook PII | 12 | ~20 |
| 69b Basket PII | 9 | ~15 |
| 70a Dev mode fix | 6 | ~8 |
| 70b File permissions | 3 | ~5 |
| **Total** | **~53** | **~90** |

### M5 Test Fixes (Sprint 63e)

Previously 5 pre-existing failures, now all resolved:
- **4 handler tests:** Removed `getCapturedAmount()` assertions on AUTHORIZED/PENDING contracts (state guard prevents setting amount in those states)
- **1 refund test:** Added `is_finite()` guard in `StripeRefundService::validateRefundAmount()` before partial refund check
- **1 OxidSessionAdapter:** Added missing `setBasket()`/`setUser()` methods from `SessionAdapterInterface`
- **4 payment-component tests:** Transitioned contracts to COMMITTED/FULFILLED state before calling `setCapturedAmount()`

---

## Compliance Progress

| Standard | Status | Blocking Findings |
|----------|--------|-------------------|
| PCI DSS v4.0 | PASS | All HIGH+ resolved |
| GDPR/DSGVO | PASS | ~~H6~~, ~~H7~~ resolved |
| BSI TR-03116-4 | PASS | ~~M6~~, ~~M9~~ resolved |
| OWASP Top 10 | PASS | All resolved |

---

## Directory Structure

```
20260224/
├── status.md              ← this file
├── reports/
│   ├── 01-security-audit-status-review.md
│   ├── 02-sprint-63abc-already-fixed.md
│   ├── 03-sprint-63d-h1-dump-extension-completed.md
│   └── 04-sprint-63e-m5-test-fixes.md
├── sprints/
│   ├── sprint-64-pentest-stress-plan.md
│   └── sprint-67-70-security-remediation-plan.md
└── done/
    ├── sprint-63a-c1-api-key-exposure.md
    ├── sprint-63b-h2-capture-mode-override.md
    ├── sprint-63c-c5-amount-validation.md
    └── sprint-63d-h1-dump-extension.md
```
