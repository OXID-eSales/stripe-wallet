# STRP-99 Security Remediation — Status Tracker

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Audit source:** `20260219/reports/01-security-audit-strp99-no-mcp.md`

---

## Active Sprint Queue

No active sprints. All Sprint 63 work complete.

**Next up:** Investigate and fix 5 pre-existing test failures (M5 state guard + refund message).

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
| M5 | MEDIUM | 4.0 | No State Guard on Amounts | — | DONE (pre-existing, but tests broken) |
| M6 | MEDIUM | 3.5 | No HTTPS on Webhook | 70 | PLANNED |
| M7 | MEDIUM | 3.0 | No Rate Limiting | 70 | PLANNED |
| M8 | MEDIUM | 2.5 | Webhook Payload Size Unlimited | 70 | PLANNED |
| M9 | MEDIUM | — | Address Hash Not HMAC | 70 | PLANNED |
| L1 | LOW | 2.0 | API Keys Unencrypted in DB | 71 | PLANNED |
| L2 | LOW | — | No CSP on AJAX Responses | 71 | PLANNED |
| L3 | LOW | — | Error Messages Leak Paths | 71 | PLANNED |
| L4 | LOW | — | No Config Change Audit Trail | 71 | PLANNED |

**Summary:** 9/28 DONE (7 pre-existing + 1 new + M5 partial), 19 PLANNED

---

## Sprint History

| Sprint | Finding(s) | Date Started | Date Completed | Report |
|--------|-----------|--------------|----------------|--------|
| 62 | H1 (plan only) | 2026-02-20 | — | Plan: `20260220/todo/sprint-62-h1-dump-extension.md` |
| 63a | C1 | 2026-02-23 | 2026-02-23 | ALREADY FIXED — `reports/02-sprint-63abc-already-fixed.md` |
| 63b | H2 | 2026-02-23 | 2026-02-23 | ALREADY FIXED — `reports/02-sprint-63abc-already-fixed.md` |
| 63c | C5 | 2026-02-23 | 2026-02-23 | ALREADY FIXED — `reports/02-sprint-63abc-already-fixed.md` |
| 63d | H1 | 2026-02-23 | 2026-02-23 | `reports/03-sprint-63d-h1-dump-extension-completed.md` |

---

## Test Baseline

| Metric | Value | Updated |
|--------|-------|---------|
| Total tests | 822 | 2026-02-23 |
| Total assertions | 2288 | 2026-02-23 |
| PHPCS errors | 0 | 2026-02-23 |
| PHPStan errors (max) | 0 | 2026-02-23 |
| PHPMD new violations | 0 | 2026-02-23 |
| Pre-existing failures | 5 | 2026-02-23 |
| Sprint 63d tests added | 12 (pre-existing test files, 1 deleted conflicting) | 2026-02-23 |

### Pre-existing Test Failures (not caused by Sprint 63)

1. `WebhookContractFulfillmentHandlerTest::handlerTransitionsAuthorizedContractOnCapture` — M5 state guard
2. `WebhookContractFulfillmentHandlerTest::handlerReturnsFalseForPendingContractOnCapture` — M5 state guard
3. `DelayedCaptureIntegrationTest::chargeCapturedTransitionsAuthorizedContractToReadyToCommit` — M5 state guard
4. `DelayedCaptureIntegrationTest::chargeCapturedRecordsAmountForPendingContract` — M5 state guard
5. `StripeRefundServiceTest::testRefundRejectsInfinityAmount` — message mismatch

Root cause: `PaymentContract::setCapturedAmount()` now has state guards (M5 fix applied in payment-component) but 4 handler tests + 1 refund test not yet updated to match.

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
│   └── 03-sprint-63d-h1-dump-extension-completed.md
├── sprints/
│   └── (empty — all sprints completed)
└── done/
    ├── sprint-63a-c1-api-key-exposure.md
    ├── sprint-63b-h2-capture-mode-override.md
    ├── sprint-63c-c5-amount-validation.md
    └── sprint-63d-h1-dump-extension.md
```
