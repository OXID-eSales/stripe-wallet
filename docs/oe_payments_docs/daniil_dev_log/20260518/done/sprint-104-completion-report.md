# Sprint 104 Completion Report — In-request deduplication of Stripe API calls

**Date completed:** 2026-05-18
**Branch:** `b-7.4.x-partial-refund-STRP-131` (alongside Sprint 103, both uncommitted at time of report)
**Engineer:** daniil.tkachev@oxid-esales.com

## Summary

Sprint 104 collapsed the panel render's Stripe API fan-out from
~10 sequential round-trips to **1** by:

1. Dropping the two render-path `refresh=true` flags that were
   defeating the provider's in-request cache.
2. Promoting the *expanded* PaymentIntent (already used by
   `getStripeTransactionHistory()`) to the single canonical fetch.
   The Charge is now derived from `$paymentIntent->latest_charge`
   in-memory, not via a second API call.
3. Memoising `\Stripe\Charge` on the `Order` model extension so
   its three accessors (`getStripeCapturedAmount`,
   `getStripeRefundedAmount`, `hasStripeRefunds`) share one fetch
   pair per request.

Two protected seams (`fetchExpandedPaymentIntent()` on the
provider, `fetchStripeCharge()` on the Order extension) make the
call-count invariant testable without mocking the `final`
`StripeOrderApiService`.

## Files changed (all under `source/extensions/stripe/`)

| File | Change |
|---|---|
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | (+50/−12) `getPaymentIntent()` populates the cache via new `fetchExpandedPaymentIntent()` seam; `getLastCharge()` derives Charge from `$pi->latest_charge` (no second API call); `getStripeTransactionHistory()` reads from the shared cache; both `refresh=true` flags dropped from `isOrderRefundable()` and `getRemainingRefundableRaw()`. Sprint 103's resolver delegation preserved unchanged. |
| `src/Stripe/Model/Order.php` | (+27/−0) Two new private instance fields (`$cachedCharge`, `$chargeCacheLoaded`); `getStripeCharge()` split into memoising wrapper + protected `fetchStripeCharge()` seam. Visibility (`protected`) preserved from Sprint 103. |
| `tests/Unit/Stripe/Admin/StripePanelApiCallCountTest.php` | New — 4 tests pinning the call-count invariant. |

## Test results

| Suite | Tests | Assertions | Result |
|---|---|---|---|
| Unit (Stripe, isolated) | 854 | — | PASS |
| Full (`./bin/pre-commit-check.sh --full`) | **1002** | **2441** | **PASS — `ALL CHECKS PASSED / Status: COMMITABLE`** |

New tests added: **4** (call-count cases).
Combined Sprint 103 + 104 isolated run: **23/23** pass (regression check).

## Call-count invariant — the load-bearing assertion

| # | Test method | Expected counts | Result |
|---|---|---|---|
| 1 | `testPanelRenderIssuesOneExpandedPiCall` | expanded PI = 1, plain PI = 0, plain Charge = 0 | PASS |
| 2 | `testIsOrderRefundableReusesCachedCharge` | After provider read + `isOrderRefundable`, total count == 1 | PASS |
| 3 | `testOrderExtensionMemoisesChargePerInstance` | After 3 Order-extension accessor calls, `fetchStripeCharge` count == 1 | PASS |
| 4 | `testMutationPathStillRefreshes` | Explicit `refresh=true` increments count (escape hatch preserved for `CaptureService` / `RefundService`) | PASS |

Pre-fix red phase output:
- Cases 1, 2, 4: `Failed asserting that 0 is identical to 1` / `Failed asserting that 0 is identical to 2`.
- Case 3: `Failed asserting that 3 is identical to 1` — no memoisation existed.

## Static analysis

| Tool | Result |
|---|---|
| PHPCS | 0 errors |
| PHPStan max | 0 errors |
| PHPMD | 0 new findings; baseline unchanged |

## DRY grep gate

```
$ grep -nE ', *true\)' src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php
(zero matches)
```

Any future re-introduction of `refresh=true` on a render-path read
will fail Test 1 (`testPanelRenderIssuesOneExpandedPiCall`) in CI.

## TDD cycle

1. **Red** — wrote the 4 call-count cases against a stub
   `StripeOrderApiService` that increments a counter per method.
   Cases 1, 2, 4 reported `0 != 1` / `0 != 2` because the seam
   didn't exist yet; Case 3 reported `3 != 1`.
2. **Drop `refresh=true`** at provider lines 137 and 162 — partial
   green.
3. **Memoise `Order::getStripeCharge()`** with the
   `$chargeCacheLoaded` flag pattern (null is a valid cached value).
4. **Promote expanded PI as canonical** — `getPaymentIntent()`
   now calls `fetchExpandedPaymentIntent()`; `getLastCharge()`
   derives from `$pi->latest_charge`. `getStripeTransactionHistory()`
   reads from the shared cache via `getPaymentIntent()`.
5. **All four call-count cases green; Sprint 103 tests still
   green** (combined 23/23).
6. **Pre-commit `--full` green** — 1002 tests, 0 errors, 0 failures.

## Deviations from plan

**Two additions over the sprint's literal spec, both improvements:**

1. **`fetchExpandedPaymentIntent()` protected seam on the
   provider.** Sprint doc didn't call this out by name; agent
   added it so the call-count test can override the single fetch
   entry point without mocking the `final` `StripeOrderApiService`.
   External API unchanged.
2. **`fetchStripeCharge()` protected seam on `Order`.** Sprint
   doc said "memoise `getStripeCharge()`"; agent split it into
   `getStripeCharge()` (wrapper, owns the cache check) +
   `fetchStripeCharge()` (the actual fetch). Lets testable
   subclasses override the fetch without bypassing the
   memoisation. Sprint 103's `protected` visibility on
   `getStripeCharge()` is preserved.

## Performance expectation (not measured in this sprint)

Per the [latency report's §2.2 arithmetic](../reports/02-stripe-payment-tab-latency.md):
- Before: ≈10 sequential round-trips × ~150 ms RTT ≈ 1.65 s
- After: 1 round-trip × ~150 ms ≈ 0.15 s

Predicted wall-clock improvement ≈ **80 %**. Measurement deferred
to Sprint 106 (Playwright timing harness from latency report
§1.1) — **shelved**; or to manual DevTools Network panel
verification in the operator-facing smoke pass.

## Follow-ups / open items

- **Sprint 105 (cross-request blob cache):** **frozen** (see
  [sprint-105 banner](../sprints/sprint-105-layer-b-contract-snapshot-blob-cache.md)).
  Layer A delivered the ≈80 % win on its own; warm-render
  zero-call optimisation not justified by current operator
  feedback.
- **Sprint 106 (async fragment + spinner):** **frozen** —
  Sprint 107 (busy overlay) covers the in-flight-action UX gap
  that 106 was over-engineered to address.
- **Note on pre-commit baseline:** the 30 pre-existing
  `DoctrineIdempotencyRepositoryTest` errors I flagged earlier
  today turned out to be a transient environment issue — they
  were absent on the final verification run. Full gate is now
  cleanly `COMMITABLE`.
