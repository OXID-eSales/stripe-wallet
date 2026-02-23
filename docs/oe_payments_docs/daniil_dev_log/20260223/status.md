# STRP-99 Security Remediation — Status Tracker

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Audit source:** `20260219/reports/01-security-audit-strp99-no-mcp.md`

---

## Active Sprint Queue

| Sprint | Title | Findings | Status |
|--------|-------|----------|--------|
| 64a | Guard Infrastructure + Payload Size Guard (TDD) | M8 | PLANNED |
| 64b | Webhook Rate Limiter (TDD) | M7 | PLANNED |
| 64c | Webhook IP Allowlist Guard (TDD) | H9 | PLANNED |
| 64d | WebhookController Guard Integration (TDD) | M7+M8+H9 | PLANNED |
| 64e | CSRF on Admin Endpoints (TDD) | H8 | PLANNED |
| 64f | CSRF on Frontend AJAX + JS (TDD) | H8 | PLANNED |
| 64g | Atomic Idempotency / TOCTOU Race (TDD) | C3 | PLANNED |
| 64h | Automated Pentest Verification (DevOps) | All | PLANNED |

**Plan:** `sprints/sprint-64-pentest-stress-plan.md` (~45 new tests, 17 new files, 9 modified)

---

## All Findings Tracker

| ID | Severity | CVSS | Title | Sprint | Status |
|----|----------|------|-------|--------|--------|
| C1 | CRITICAL | 7.5 | API Key Prefixes Exposed | 63a | DONE (pre-existing) |
| C2 | CRITICAL | 6.8 | Weak ID Generation (`uniqid()`) | — | DONE (pre-existing) |
| C3 | CRITICAL | 7.0 | TOCTOU Race in Webhook Idempotency | 66 | PLANNED |
| C4 | CRITICAL | 6.5 | Refund Amount vs Captured Amount | — | DONE (pre-existing) |
| C5 | CRITICAL | 6.0 | NaN/Infinity/Negative Amounts | 63c | DONE (pre-existing) |
| H1 | HIGH | 5.5 | DumpExtension in Production | 63d | DONE |
| H2 | HIGH | 5.0 | Capture Mode Override from URL | 63b | DONE (pre-existing) |
| H3 | HIGH | 5.5 | Contract Tokens Not Validated | 67 | PLANNED |
| H4 | HIGH | 4.5 | Currency Not ISO 4217 | — | DONE (pre-existing) |
| H5 | HIGH | 5.0 | State Machine Bypass via fromArray() | 68 | PLANNED |
| H6 | HIGH | 4.0 | PII in Basket Snapshot | 69 | PLANNED |
| H7 | HIGH | 4.5 | PII in Webhook Logs | 69 | PLANNED |
| H8 | HIGH | 4.0 | No CSRF on Payment Endpoints | 66 | PLANNED |
| H9 | HIGH | 5.0 | Webhook Signature Not Enforced | 67 | PLANNED |
| H10 | HIGH | 4.0 | Client Secret in Session | 68 | PLANNED |
| M1 | MEDIUM | 3.5 | XSS in Admin Error Messages | 70 | PLANNED |
| M2 | MEDIUM | — | Dev Mode via Env Variable | 70 | PLANNED |
| M3 | MEDIUM | 3.0 | File Permissions (0755) | 70 | PLANNED |
| M4 | MEDIUM | — | Weak DateTime Parsing | 70 | PLANNED |
| M5 | MEDIUM | 4.0 | No State Guard on Amounts | — | DONE (tests fixed) |
| M6 | MEDIUM | 3.5 | No HTTPS on Webhook | 70 | PLANNED |
| M7 | MEDIUM | 3.0 | No Rate Limiting | 70 | PLANNED |
| M8 | MEDIUM | 2.5 | Webhook Payload Size Unlimited | 70 | PLANNED |
| M9 | MEDIUM | — | Address Hash Not HMAC | 70 | PLANNED |
| L1 | LOW | 2.0 | API Keys Unencrypted in DB | 71 | PLANNED |
| L2 | LOW | — | No CSP on AJAX Responses | 71 | PLANNED |
| L3 | LOW | — | Error Messages Leak Paths | 71 | PLANNED |
| L4 | LOW | — | No Config Change Audit Trail | 71 | PLANNED |

**Summary:** 10/28 DONE (7 pre-existing + 2 new + M5 full), 18 PLANNED

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
| 64a–f | Pentest + hardening plan | 2026-02-23 | — | `sprints/sprint-64-pentest-stress-plan.md` |

---

## Test Baseline

| Metric | Value | Updated |
|--------|-------|---------|
| Stripe tests | 822 | 2026-02-23 |
| Stripe assertions | 2300 | 2026-02-23 |
| Stripe failures | 0 | 2026-02-23 |
| payment-component integration tests | 76 | 2026-02-23 |
| payment-component failures | 0 | 2026-02-23 |
| PHPCS errors | 0 | 2026-02-23 |
| PHPStan errors (max) | 0 | 2026-02-23 |
| PHPMD new violations | 0 | 2026-02-23 |

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
| PCI DSS v4.0 | PARTIAL | C3, H8 |
| GDPR/DSGVO | FAIL | H6, H7 |
| BSI TR-03116-4 | PARTIAL | M6, M9 |
| OWASP Top 10 | PARTIAL | ~~H1~~, H3, M1 |

---

## Directory Structure

```
20260223/
├── status.md              ← this file
├── reports/
│   ├── 01-security-audit-status-review.md
│   ├── 02-sprint-63abc-already-fixed.md
│   ├── 03-sprint-63d-h1-dump-extension-completed.md
│   └── 04-sprint-63e-m5-test-fixes.md
├── sprints/
│   └── sprint-64-pentest-stress-plan.md
└── done/
    ├── sprint-63a-c1-api-key-exposure.md
    ├── sprint-63b-h2-capture-mode-override.md
    ├── sprint-63c-c5-amount-validation.md
    └── sprint-63d-h1-dump-extension.md
```
