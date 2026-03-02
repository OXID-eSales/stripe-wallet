# Sprint 64c — Webhook IP Allowlist Guard (H9)

**Date:** 2026-02-24
**Status:** DONE
**Finding:** H9 (Webhook IP not validated)

## Summary

Created IP allowlist guard with CIDR notation support. Empty list = disabled (fail-open for compatibility).

## Files Created (2)

- `src/Stripe/Controller/Webhook/WebhookIpAllowlistGuard.php` — CIDR matching, loopback dev mode
- `tests/Unit/Stripe/Controller/Webhook/WebhookIpAllowlistGuardTest.php` — 8 tests

## Test Results

```
Tests: 8, Assertions: 13, Failures: 0
```

## Features

- CIDR notation support (`54.187.174.0/24`)
- Exact IP matching (`54.187.174.169`)
- Loopback allowance for development (`127.0.0.1`, `::1`)
- Empty allowlist = disabled (all IPs allowed)
- Invalid IP rejection (graceful handling)
