# Sprint 114.6 Completion Report — Remove redundant `LazyStripeAdapter`

**Date:** 2026-05-27
**Branch:** `b-7.4.x-code-review-STRP-145`
**Commit:** `b23f3de`
**Findings addressed:** O3 (No Overengineering), L2 (Liskov — proxy not substitutable for `StripeAdapter`)

---

## Grep Proof of Zero Consumers (R-9.2)

```
grep -rn "LazyStripeAdapter" src/ tests/ services.yaml metadata.php
(excluding .phpunit.cache stale coverage)
```

Hits before deletion:
| Location | Type |
|---|---|
| `src/Stripe/Adapter/LazyStripeAdapter.php` | Class file itself |
| `services.yaml:224` | Stale comment |
| `services.yaml:228` | DI definition (no active consumer injected it) |
| `tests/PhpMd/phpmd.baseline.xml:3` | Baseline entry |

**No `src/` service had `LazyStripeAdapter` as a constructor argument.** The only consumers (`StripeCaptureService` / `StripeRefundService`) were deleted in Sprint 114.5.

Post-deletion grep: **empty** (zero references).

---

## Files Changed

| File | Change |
|---|---|
| `src/Stripe/Adapter/LazyStripeAdapter.php` | **Deleted** (183 lines) |
| `services.yaml` | Removed definition + stale Sprint 26 comment (lines 222–231, 11 lines) |
| `tests/PhpMd/phpmd.baseline.xml` | Removed `TooManyPublicMethods` baseline entry (1 line) |

**Total:** 3 files changed, 195 deletions. No new files, no new suppressions.

No `LazyStripeAdapterTest` existed (grep confirmed: none).

---

## PHPMD Baseline

Before: 4 entries (LazyStripeAdapter × 1, StripeAdapter × 2, StripeOrderController × 1)
After: 3 entries (StripeAdapter × 2, StripeOrderController × 1)

Baseline shrank by 1 entry — compliant with R-6.2 and R-9 (LOC + baseline trend down).

---

## Pre-Commit Gate Results (R-6.1)

**Before deletion (baseline):**
- Unit: 859 tests, 2188 assertions — all green

**After deletion (`--full`):**
- PHPCS: 0 errors
- PHPUnit (Unit + Integration): **991 tests, 2525 assertions** — green
- PHPStan (level max): 0 errors
- PHPMD: green (3 baselined entries, none new)

Cache cleared (`oe:cache:clear` confirmed "Cleared cache files") and PHP container restarted after `services.yaml` change.

---

## Engineering Requirements Checklist (R-1…R-10)

- [x] **R-1 TDD:** This is a pure deletion. The safety net is the full test suite running unchanged (859 → 859 unit tests; 991 integration-inclusive). R-1.4 characterization: the proxy's only consumers were already deleted in 114.5 — no behavior to characterize. Net assertion count: unchanged.
- [x] **R-2 SOLID:** PHPMD baseline shrank (4 → 3 entries). No god-objects introduced.
- [x] **R-3 LI (Liskov):** L2 finding resolved — `LazyStripeAdapter` implemented only `PaymentAdapterInterface`, not `StripeAdapterInterface`, so it was never substitutable for `StripeAdapter`. Deleted. No `instanceof` downcasts introduced.
- [x] **R-4 DI:** No DI changes — nothing to rewire (no live consumers remained). services.yaml definition removed cleanly.
- [x] **R-5 Clean Code:** N/A (deletion only). No new code introduced.
- [x] **R-6 DevOps-first:** `pre-commit-check.sh --full` green. Cache cleared + PHP restarted. No new suppressions.
- [x] **R-7 Event-driven:** N/A — proxy had no event-system role.
- [x] **R-8 Contract-aware:** N/A — proxy had no contract-state role.
- [x] **R-9 No overengineering:** grep proof attached above. Proxy forwarded without adding value (factory is already lazy). Dead code deleted.
- [x] **R-10 Persistence:** N/A — deleted class performed no writes.

---

## Why This Was Safe

The `StripeAdapterFactory::getStripeAdapter()` has always been lazy by design — it builds the adapter on first call. The `LazyStripeAdapter` introduced an additional per-instance cache (`private ?PaymentAdapterInterface $adapter = null`) that was actually a **correctness liability**: it could return a stale client across multiple operations in the same request lifetime. Removing it restores fresh-client-per-call semantics that every other service already relied on.

---

## Goals Verification

| Goal | Status |
|---|---|
| G1 `LazyStripeAdapter` deleted | Done |
| G2 Live consumers rewired to factory directly | N/A — no live consumers after 114.5 |
| G3 No behavior change to capture/refund execution | Done — services deleted in 114.5 |
| G4 `services.yaml` references removed | Done |
| G5 PHPMD baseline entry removed | Done (4 → 3 entries) |
| G6 `pre-commit-check.sh --full` green | Done |
