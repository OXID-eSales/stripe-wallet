# Sprint 64g — Atomic Idempotency / TOCTOU Race (C3)

**Date:** 2026-02-24
**Status:** DONE
**Finding:** C3 (TOCTOU Race in Webhook Idempotency)

## Summary

Replaced the TOCTOU-vulnerable two-step idempotency pattern (`existsByEventId()` → `save()`) with atomic `claimEvent()` that uses INSERT with unique key constraint + `UniqueConstraintViolationException` catch. Only one process can claim a given event ID.

## Changes

### Modified — payment-component (3)
- `src/Repository/WebhookLogRepositoryInterface.php` — Added `claimEvent(string $eventId, string $provider, string $eventType): bool` method signature
- `src/Repository/DoctrineWebhookLogRepository.php` — Implemented `claimEvent()` with `INSERT` + `UniqueConstraintViolationException` catch (returns `false` on duplicate)
- `src/Webhook/AbstractWebhookProcessor.php` — Replaced `isAlreadyProcessed()` + `logWebhookReceived()` two-step with single `claimEvent()` call

### Modified — payment-component tests (1)
- `tests/Unit/Webhook/AbstractWebhookProcessorTest.php` — Updated all 8 tests to mock `claimEvent()` instead of `existsByEventId()` + `save()`

### Created — payment-component tests (1)
- `tests/Unit/Webhook/AtomicIdempotencyTest.php` — 5 new tests specifically for atomic idempotency

## Test Results

```
payment-component: 13 tests, 71 assertions, 0 failures
stripe processor:  10 tests, 38 assertions, 0 failures
```

## Before/After

### Before (TOCTOU vulnerable)
```php
// Step 2: Check idempotency
if ($this->isAlreadyProcessed($event->id)) {        // SELECT COUNT(*)
    return WebhookResult::skipped('Already processed');
}
// [RACE WINDOW: another process inserts same event here]
// Step 3: Log webhook received
$this->logWebhookReceived($event, $request);          // INSERT (may duplicate!)
```

### After (atomic)
```php
// Step 2+3: Atomic claim
if (!$this->logRepository->claimEvent($event->id, $this->getProviderName(), $event->type)) {
    return WebhookResult::skipped('Already processed: ' . $event->id);
}
// Guaranteed: only one process reaches here for a given event ID
```

## Backwards Compatibility

- `isAlreadyProcessed()` and `logWebhookReceived()` kept as protected methods for subclass compatibility
- `existsByEventId()` kept on interface (used by `WebhookIdempotencyChecker`)
- `save()` kept on interface (used by external callers)
