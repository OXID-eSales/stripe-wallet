# Sprint 67b — M6: Webhook HTTPS Enforcement Guard

**Date:** 2026-02-24
**Status:** DONE
**Finding:** M6 — No HTTPS Enforcement on Webhook Endpoint (CVSS 3.5, MEDIUM)
**Package:** stripe

## Problem

The webhook controller had no check for transport layer security. If the shop is misconfigured or a reverse proxy terminates TLS incorrectly, webhook payloads (containing payment intent IDs, amounts, customer references) travel in plaintext. A network attacker (MITM) can read and replay captured payloads.

## Fix

Created `WebhookHttpsGuard` implementing the existing `WebhookRequestGuardInterface` (Chain of Responsibility). Inserted at position 0 in the guard chain — cheapest check (reads 1-2 server variables) runs first.

### HTTPS Detection Logic

1. Check `$_SERVER['HTTPS'] === 'on'` (direct HTTPS)
2. Check `$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'` (behind reverse proxy / load balancer)
3. If neither → reject with HTTP 400 ("HTTPS required")
4. Exception: `allowInsecureLoopback=true` permits HTTP on `127.0.0.1`/`::1` for development

### Guard Chain (Updated)

```
0. WebhookHttpsGuard          ← NEW (O(1) — server var check)
1. WebhookPayloadSizeGuard    (O(1) — strlen)
2. WebhookRateLimitGuard      (O(1) — APCu)
3. WebhookIpAllowlistGuard    (O(n) — CIDR loop)
```

## Files Created (2)

### Production (1)
- `src/Stripe/Controller/Webhook/WebhookHttpsGuard.php`
  - Implements `WebhookRequestGuardInterface`
  - `getServerVar()` is `protected` for testable subclass override
  - Returns `WebhookGuardResult('insecure_connection', 400, ...)` on failure

### Tests (1)
- `tests/Unit/Stripe/Controller/Webhook/WebhookHttpsGuardTest.php`
  - Testable subclass `TestableWebhookHttpsGuard` overrides `getServerVar()`

## Files Modified (1)

- `services.yaml`
  - Added `WebhookHttpsGuard` service definition with `$allowInsecureLoopback: true`
  - Inserted at position 0 in guard chain array

## Test Results

```
Tests: 6, Assertions: 10, Failures: 0
```

| # | Test | Server Vars | RemoteIp | Loopback | Expected |
|---|------|-------------|----------|----------|----------|
| 1 | `guardAllowsHttpsRequest` | `HTTPS=on` | external | off | pass (null) |
| 2 | `guardRejectsHttpRequest` | empty | external | off | 400 |
| 3 | `guardAcceptsXForwardedProtoHttps` | `X-Forwarded-Proto: https` | external | off | pass |
| 4 | `guardRejectsXForwardedProtoHttp` | `X-Forwarded-Proto: http` | external | off | 400 |
| 5 | `guardAllowsLocalhostWhenEnabled` | empty | 127.0.0.1 | on | pass |
| 6 | `guardRejectsLocalhostWhenDisabled` | empty | 127.0.0.1 | off | 400 |

## Design Decisions

- **HTTP 400 (not 403):** 403 implies identified-but-unauthorized. 400 communicates malformed request (wrong transport). Stripe retries on non-2xx, persistent 400 signals a config issue.
- **Not `final` class:** Testable subclass pattern requires extensibility for `getServerVar()` override. Other guards in the chain follow the same pattern.

## SOLID Compliance

- **S**: One job — check TLS
- **O**: Added to existing chain without modifying controller or other guards
- **L**: Implements `WebhookRequestGuardInterface` — chain treats it uniformly
- **I**: Single-method interface
- **D**: Injected via services.yaml, controller depends on chain interface
