# Security Audit Status Review — STRP-99

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Reviewed by:** Claude Code
**Source audit:** `20260219/reports/01-security-audit-strp99-no-mcp.md`

---

## Executive Summary

The comprehensive security audit completed on **2026-02-19** identified **28 findings** across 4 severity levels. As of today (2026-02-23), **remediation has not started**. Only finding H1 (DumpExtension) has a TDD sprint plan (Sprint 62, 2026-02-20), but no code has been written.

The 5 CRITICAL findings (C1-C5) require immediate attention before any production deployment.

---

## Findings Status Matrix

### CRITICAL (5) — None Fixed

| ID | Title | CVSS | File(s) | Status |
|----|-------|------|---------|--------|
| C1 | API Key Prefixes Exposed in JSON Response & Logs | 7.5 | `StripeOrderController.php:134-157` | NOT STARTED |
| C2 | Weak ID Generation via `uniqid()` | 6.8 | `AbstractModel.php:18`, `DoctrineTransactionRepository.php:151`, `WebhookLog.php:25` | NOT STARTED |
| C3 | TOCTOU Race in Webhook Idempotency | 7.0 | `WebhookIdempotencyChecker.php:22-42` | NOT STARTED |
| C4 | Refund Amount Validated Against Basket Total (not captured) | 6.5 | `AbstractPaymentRefundService.php:151-186` | NOT STARTED |
| C5 | No Amount Validation for NaN/Infinity/Negative | 6.0 | `BasketSnapshot.php:102-126`, `AbstractPaymentCaptureService.php` | NOT STARTED |

### HIGH (10) — H1 Planned, Rest Not Started

| ID | Title | CVSS | Status |
|----|-------|------|--------|
| H1 | DumpExtension Available in Production | 5.5 | PLANNED (Sprint 62 TDD plan written, no code) |
| H2 | Capture Mode Override from Request Parameter | 5.0 | NOT STARTED |
| H3 | Contract Tokens from URL Not Validated | 5.5 | NOT STARTED |
| H4 | Currency Not Validated Against ISO 4217 | 4.5 | NOT STARTED |
| H5 | State Machine Bypass via `ContractCondition::fromArray()` | 5.0 | NOT STARTED |
| H6 | PII Stored in Basket Snapshot Without Field Whitelist | 4.0 | NOT STARTED |
| H7 | Sensitive Data in Logs (Full Webhook Payloads) | 4.5 | NOT STARTED |
| H8 | No CSRF Token on Payment Endpoints | 4.0 | NOT STARTED |
| H9 | Webhook Signature Verification Not Enforced | 5.0 | NOT STARTED |
| H10 | Session Stores Stripe Client Secret | 4.0 | NOT STARTED |

### MEDIUM (9) — None Started

| ID | Title | CVSS | Status |
|----|-------|------|--------|
| M1 | XSS in Admin Error Messages | 3.5 | NOT STARTED |
| M2 | Dev Mode Check Relies on Environment Variable | — | NOT STARTED |
| M3 | File Permissions Too Permissive (0755) | 3.0 | NOT STARTED |
| M4 | Weak DateTime Parsing in PaymentContract | — | NOT STARTED |
| M5 | No State Validation on Amount Modifications | 4.0 | NOT STARTED |
| M6 | No HTTPS Enforcement on Webhook Endpoint | 3.5 | NOT STARTED |
| M7 | No Rate Limiting on Payment Endpoints | 3.0 | NOT STARTED |
| M8 | Webhook Payload Size Not Limited | 2.5 | NOT STARTED |
| M9 | Delivery Address Hash Not Cryptographically Bound | — | NOT STARTED |

### LOW (4) — None Started

| ID | Title | CVSS | Status |
|----|-------|------|--------|
| L1 | API Keys Stored Unencrypted in Database | 2.0 | NOT STARTED |
| L2 | No Content Security Policy Headers on AJAX | — | NOT STARTED |
| L3 | Error Messages May Leak Internal Paths | — | NOT STARTED |
| L4 | No Audit Trail for Configuration Changes | — | NOT STARTED |

---

## Compliance Impact

| Standard | Status | Blocking Findings |
|----------|--------|-------------------|
| **PCI DSS v4.0** | FAIL | C1 (3.5), C2 (3.6), H2 (6.5.4), H8 (6.5.9), C3 (10.2) |
| **GDPR/DSGVO** | FAIL | H6 (Art. 5.1c), H7 (Art. 5.1c), H6 (Art. 25) |
| **BSI TR-03116-4** | FAIL | C2 (RNG), M6 (TLS), M9 (crypto) |
| **OWASP Top 10 2021** | FAIL | H1 (A05), H3 (A01), C5 (A03) |

---

## Scope Clarification: STRP-99 vs STRP-88

Two separate security tracks exist:

| Track | Branch | Scope | Status |
|-------|--------|-------|--------|
| **STRP-88** (Sprints 58-61) | `b-7.4.x-mcp-STRP-88` | MCP-related findings F1-F25 | COMPLETE (376 tests, 658 assertions) |
| **STRP-99** (this audit) | `b-7.4.x-security-STRP-99` | Core module findings C1-L4 | NOT STARTED (28 findings, 0 fixed) |

The STRP-88 work (F1-F25) addressed MCP security concerns and is fully tested. The STRP-99 audit covers the **core Stripe module and payment-component** without MCP code. These are independent tracks with no overlap.

---

## Previous Work Log

| Date | Activity |
|------|----------|
| 2026-02-19 | Comprehensive security audit completed (28 findings documented) |
| 2026-02-20 | Sprint 62 plan written for H1 (DumpExtension), no code implemented |
| 2026-02-23 | This status review — confirming 0/28 findings remediated |

---

## Risk Assessment

**Production readiness: BLOCKED** by 5 CRITICAL + 5 HIGH findings that require pre-deployment fixes:
- C1 (key exposure), C2 (weak IDs), C3 (race condition), C4 (refund bug), C5 (amount validation)
- H1 (debug in prod), H2 (capture override), H3 (token bypass), H8 (CSRF), H9 (signature)

**Estimated remediation effort:**
- CRITICAL (C1-C5): ~3-4 sprints with TDD
- HIGH pre-deployment (H1-H3, H8-H9): ~3-4 sprints with TDD
- Remaining HIGH + MEDIUM + LOW: ~4-5 sprints

---

## Next Steps

Detailed sprint plans have been created (one finding per sprint) at:
- `20260223/sprints/sprint-63a-c1-api-key-exposure.md`
- `20260223/sprints/sprint-63b-h2-capture-mode-override.md`
- `20260223/sprints/sprint-63c-c5-amount-validation.md`
- `20260223/sprints/sprint-63d-h1-dump-extension.md`

Recommended execution order:
1. Sprint 63a: C1 — Remove API key exposure (simplest)
2. Sprint 63b: H2 — Remove capture mode override (simple deletion)
3. Sprint 63c: C5 — Amount validation guards (payment-component)
4. Sprint 63d: H1 — Environment-aware DumpExtension (most complex)
5. Sprint 64: C4 + M5 — Refund validation + state guards
6. Sprint 65: C2 — Replace `uniqid()` with CSPRNG
7. Sprint 66: C3 + H8 — Race condition + CSRF
8. Sprint 67: H3 + H9 — Token validation + signature enforcement

---

## References

- Full audit: `20260219/reports/01-security-audit-strp99-no-mcp.md`
- H1 sprint plan: `20260220/todo/sprint-62-h1-dump-extension.md`
- Sprint 63a-d plans: `20260223/sprints/sprint-63{a,b,c,d}-*.md`
- Status tracker: `20260223/status.md`
