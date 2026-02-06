# Sprint 42: Completion Report — Idempotency Implementation

**Date:** 2026-02-06
**Status:** COMPLETED
**Approach:** TDD, Decorator Pattern, SOLID, Defense-in-Depth

---

## What Was Built

Idempotency protection layer for capture and refund API operations. Prevents duplicate charges/refunds caused by double-clicks, network retries, or webhook/admin race conditions.

**Protected operations (3 total):**
- `capturePayment()` — key: `capture:{providerPaymentId}`
- `refundPayment()` — key: `refund:{providerPaymentId}`
- `createRefundByCharge()` — key: `refund_charge:{chargeId}`

---

## Architecture

### Decorator Pattern at Factory Level

```
Path A (Contract-based):  StripeCaptureService → LazyStripeAdapter → factory.getStripeAdapter() → IdempotentStripeAdapter → StripeAdapter
Path B (Admin panel):     CaptureService → factory.getStripeAdapter() → IdempotentStripeAdapter → StripeAdapter
```

Both adapter creation paths go through `getStripeAdapter()`, so decorating at the factory level ensures all paths get idempotency protection.

### DI Alias Chain

```yaml
StripeAdapterFactoryInterface
  → alias: PaymentAdapterFactoryInterface
    → class: IdempotentStripeAdapterFactory
      → wraps: StripeAdapterFactory (private, concrete)
```

### Idempotency Flow

```
1. findByKey(key)
2. If found + status=completed + not expired → return cached result
3. If found + status=processing → throw RuntimeException
4. If not found → create record (processing), call inner, update (completed+result)
5. If found + expired/failed → reuse record (reset to processing), call inner
6. On exception → update record (failed), rethrow
```

---

## Files Created

| File | Description |
|------|-------------|
| `payment-component/src/Contract/IdempotencyRecord.php` | Entity model extending AbstractModel, maps to `oe_payments_idempotency` |
| `payment-component/src/Repository/IdempotencyRepositoryInterface.php` | Interface: `save()`, `findByKey()`, `deleteExpired()` |
| `payment-component/src/Repository/DoctrineIdempotencyRepository.php` | Doctrine DBAL implementation with upsert + hydration |
| `stripe/src/Stripe/Adapter/IdempotentStripeAdapter.php` | Decorator wrapping StripeAdapterInterface with idempotency |
| `stripe/src/Stripe/Service/Factory/IdempotentStripeAdapterFactory.php` | Factory decorator producing idempotent adapters |

## Files Modified

| File | Change |
|------|--------|
| `stripe/services.yaml` | DI wiring: IdempotentStripeAdapterFactory wraps StripeAdapterFactory; IdempotencyRepositoryInterface registered |

## Test Files Created

| File | Tests | Description |
|------|-------|-------------|
| `payment-component/tests/Unit/Contract/IdempotencyRecordTest.php` | 8 | Entity construction, setters, isExpired, toArray/fromArray |
| `payment-component/tests/Unit/Repository/DoctrineIdempotencyRepositoryTest.php` | 7 | Mocked Connection: save insert/update, findByKey, deleteExpired |
| `stripe/tests/Unit/Stripe/Adapter/IdempotentStripeAdapterTest.php` | 16 | Capture/refund/charge idempotency, delegation, deserialization |
| `stripe/tests/Unit/Stripe/Service/Factory/IdempotentStripeAdapterFactoryTest.php` | 7 | Factory delegation, getStripeAdapter returns IdempotentAdapter |
| `stripe/tests/Integration/Repository/DoctrineIdempotencyRepositoryTest.php` | 9 | Real DB: save/find round-trip, update, deleteExpired, unique constraint, isExpired |
| `stripe/tests/Integration/Stripe/Adapter/IdempotentStripeAdapterTest.php` | 10 | Real DB + mocked inner: full idempotency flow, cached results, processing block, failure recording, expired retry |

---

## Bug Found and Fixed by Integration Tests

**Unique key constraint violation on expired/failed record retry.**

When an expired or failed idempotency record existed in the database, the adapter created a new record with a different `OXID` but the same `OXKEY`. The repository's `save()` method checks existence by `OXID` (not `OXKEY`), so it attempted an `INSERT` which hit the unique constraint on `OXKEY`.

**Root cause:** `createRecord()` always generates a new ID, ignoring existing records with the same key.

**Fix:** Added `reuseOrCreateRecord()` method — when an existing record is found (expired or failed), reuse it by resetting status to `processing` and clearing the result, rather than creating a new record. This triggers an `UPDATE` instead of an `INSERT`.

```php
private function reuseOrCreateRecord(
    ?IdempotencyRecord $existing,
    string $key,
    string $orderId,
    string $operation
): IdempotencyRecord {
    if ($existing !== null) {
        $existing->setStatus(self::STATUS_PROCESSING);
        $existing->setResult(null);
        return $existing;
    }
    return $this->createRecord($key, $orderId, $operation);
}
```

This bug would have caused `UniqueConstraintViolationException` in production when:
- A capture/refund API call failed (status=failed) and was retried
- An idempotency record expired (after 24h) and the same operation was attempted again

**Lesson:** Unit tests with mocked repositories cannot catch constraint violations — integration tests with a real database are essential.

---

## Cleanup: Removed Deprecated WebhookProcessingService DI

Removed the deprecated `WebhookProcessingService` service definition from `services.yaml`:
- Was marked `deprecated` since Sprint 5
- Replaced by `StripeWebhookProcessor`
- Not yet released, so no BC concern

---

## Validation Results

```
PHPCS (PSR-12):          PASSED
PHPUnit (Unit+Integration): 852 tests, 2519 assertions, PASSED
PHPStan (level max):     0 errors, PASSED
PHPMD:                   PASSED
Status: COMMITABLE
```

## Test Count Change

| Before | After | Delta |
|--------|-------|-------|
| 810 tests, 2361 assertions | 852 tests, 2519 assertions | +42 tests, +158 assertions |

---

## Decisions Applied (from Sprint 42 Discussion)

| Decision | Implementation |
|----------|---------------|
| Q1: Build custom layer | IdempotencyRecord + DoctrineIdempotencyRepository |
| Q2: Decorator pattern | IdempotentStripeAdapter wraps StripeAdapterInterface |
| Q3: Capture + Refund only | 3 methods protected, all others delegated |
| Q4: Contract-based keys | `capture:{providerPaymentId}`, `refund:{providerPaymentId}`, `refund_charge:{chargeId}` |
| Q5: 24 hours TTL | `DEFAULT_TTL_SECONDS = 86400`, configurable via constructor |
| Q6: Use existing table | `oe_payments_idempotency` schema used as-is |

## Principles Applied

| Principle | Application |
|-----------|-------------|
| SRP | Each class has one responsibility: record (entity), repository (persistence), adapter (idempotency), factory (wiring) |
| OCP | Decorator — new behavior without modifying StripeAdapter |
| LSP | IdempotentStripeAdapter implements StripeAdapterInterface identically |
| ISP | IdempotencyRepositoryInterface has exactly 3 methods (no bloat) |
| DIP | Adapter depends on IdempotencyRepositoryInterface abstraction |
| DRY | All 3 protected methods follow the same idempotency pattern |
| Defense-in-Depth | Contract state machine prevents at domain level; idempotency prevents at API call level |
