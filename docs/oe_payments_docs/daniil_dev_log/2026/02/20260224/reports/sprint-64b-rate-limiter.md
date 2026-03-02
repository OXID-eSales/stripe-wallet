# Sprint 64b — Webhook Rate Limiter (M7)

**Date:** 2026-02-24
**Status:** DONE
**Finding:** M7 (No Rate Limiting on Webhook)

## Summary

Created per-IP rate limiting guard using existing `RateLimiterInterface` from payment-component.

## Files Created (2)

- `src/Stripe/Controller/Webhook/WebhookRateLimitGuard.php` — Rejects IPs exceeding rate limit
- `tests/Unit/Stripe/Controller/Webhook/WebhookRateLimitGuardTest.php` — 4 tests

## Test Results

```
Tests: 4, Assertions: 9, Failures: 0
```

## Design

- DIP: depends on `RateLimiterInterface`, not `ApcuRateLimiter`
- Concrete `ApcuRateLimiter` uses atomic `apcu_inc()` with TTL — no DB, no file I/O
- Configured via services.yaml: 100 req/60s per IP
