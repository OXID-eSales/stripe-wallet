# Sprint 104 — Stripe panel: in-request deduplication of Stripe API calls

**Module:** `extensions/stripe`
**Mode:** single atomic commit. Three small edits to two files, plus
one new test file pinning the call-count invariant. No new
abstractions, no new data store, no migration.
**Trigger:** [report `02-stripe-payment-tab-latency.md`](../reports/02-stripe-payment-tab-latency.md) — Layer A.

## 1. Why

The admin Stripe tab fans out ≈10 sequential `api.stripe.com`
round-trips per panel render. With an 80–200 ms steady-state RTT
from a German shop to Stripe EU, that is the 1.5–3 s wall-clock
delay an operator feels each time they open or revisit an order.

The latency report inventories the cause: two render-path call-sites
defeat the provider's existing in-request cache with `refresh=true`,
and the `Order` class extension does not share that cache at all —
its three accessors each fetch PI+Charge fresh. Folding every read
onto the already-existing expanded-PaymentIntent fetch collapses
the ≈10 round-trips to **one**. That is the ~80 % wall-clock win
described in §4.1 of the report.

This sprint is the lowest-risk, highest-payoff layer. It ships
before Sprint 105 (cross-request blob cache) and Sprint 106 (async
transaction-history UI), each of which assumes the in-request fan-out
is already gone.

## 2. Goals

- **G1.** Zero render-path call-sites pass `refresh=true` to
  `OrderRefundViewDataProvider::getLastCharge()` or
  `::getPaymentIntent()`. Mutation paths
  (`CaptureService`, `RefundService`, `CancelAuthorizationService`)
  that *deliberately* re-fetch after a state change keep their
  explicit `refresh=true` and are unchanged.
- **G2.** `Order::getStripeCharge()` is memoised on the model
  instance — three consecutive accessor calls on the same `Order`
  produce exactly one PI+Charge fetch pair.
- **G3.** The panel's view-data builder, the provider, and the
  `Order`-extension accessors all read from **one** expanded
  PaymentIntent fetched once per request. `getPaymentIntent()`,
  `getLastCharge()`, and the model's `getStripeCharge()` become
  thin getters over the same cached object.
- **G4.** New unit test asserts that one panel-render flow
  (calling every provider method + every Order-extension accessor
  that the panel uses) produces **exactly one** call to
  `StripeOrderApiService::getPaymentIntentWithRefunds()` and no
  additional calls to `getPaymentIntent()` / `getLastCharge()`.
- **G5.** `./bin/pre-commit-check.sh --full` green; test totals ≥
  pre-sprint + the new call-count cases (4, per §6).

## 3. Scope inventory

| File | Concern | Line range (verified) |
|---|---|---|
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | `isOrderRefundable()` calls `getLastCharge($order, true)` — drop the `true` | 137 |
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | `getRemainingRefundableRaw()` calls `getLastCharge($order, true)` — drop the `true` | 162 |
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | `getPaymentIntent()` becomes a getter over the expanded PI cache (see §4.2) | 57–71 |
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | `getLastCharge()` becomes a getter over `apiOrder->latest_charge` | 76–94 |
| `src/Stripe/Model/Order.php` | `getStripeCharge()` adds instance-field memoisation | 207–227 |

### Net-new files

| File | Purpose |
|---|---|
| `tests/Unit/Stripe/Admin/StripePanelApiCallCountTest.php` | Pins the call-count invariant — mocks `StripeOrderApiService`, exercises the panel render path end-to-end, asserts one expanded PI retrieval and no other API calls. |

### Explicitly *not* touched

- `CaptureService`, `RefundService`, `CancelAuthorizationService` —
  these mutation paths legitimately need fresh PSP state and keep
  their `refresh=true`.
- The webhook handlers, the contract state machine, OXPAID
  reconciliation, the StripeAdapter.
- `services.yaml` — no new services, no new DI wiring.
- The DB. No migration.

## 4. Design

### 4.1 The cache that already exists

`OrderRefundViewDataProvider` carries `$this->apiOrder` (the PI) and
`$this->apiCharge` (the Charge) as private fields. The provider is
instantiated per-request by the OXID admin controller, so the cache
is request-scoped. The cache *works* — `getCaptureableRaw`,
`isOrderCapturable`, and `getPaymentIntent` (after the first call)
all hit it. The bug is that two render-path methods pass
`refresh=true` and a third actor (the `Order` extension) doesn't
share the cache at all.

### 4.2 Promote the expanded PI to the canonical source

`StripeOrderApiService::getPaymentIntentWithRefunds()` already
fetches PI with `expand[]=latest_charge.refunds` in a single
request. That one object contains everything the panel reads:
amounts, status, the Charge, the refunds list. Make it the
canonical fetch:

- `getPaymentIntent($order)` and `getLastCharge($order)` both return
  views over the same cached expanded object.
- The first read populates `$this->apiOrder` (the expanded PI) and
  derives `$this->apiCharge` from `apiOrder->latest_charge` without
  a second HTTP call.
- The `refresh` parameter stays on both methods — mutation paths
  still use it — but the render path passes the default `false`.

### 4.3 Memoise on the model instance

`Order` is an OXID class extension; its instance lives for the
whole request (the admin controller fetches it once via
`oxNew(Order::class)`). Add two instance fields on the extension:

```php
private ?\Stripe\Charge $cachedCharge = null;
private bool $chargeCacheLoaded = false;

protected function getStripeCharge(): ?\Stripe\Charge
{
    if ($this->chargeCacheLoaded) {
        return $this->cachedCharge;
    }
    $this->chargeCacheLoaded = true;
    // ... existing locator + fetch logic, assign to $this->cachedCharge ...
    return $this->cachedCharge;
}
```

The `loaded` boolean is necessary because `null` is a legitimate
cached value (non-Stripe orders, missing transaction ID). Without
the flag, every miss would re-fetch.

The `protected` visibility (in place since Sprint 103) keeps the
testable-subclass seam intact.

### 4.4 Share one cache between provider and Order extension?

Not in this sprint. The two actors are wired to two different
caches today — the provider via injected `StripeOrderApiService`,
the `Order` extension via `ContainerFactory` locator. Sharing
across them needs either an in-request key-value cache service or
a redesign of how `Order` gets its data. Sprint 105's contract
snapshot makes the question moot: both actors will read from the
DB blob, so the in-request-cache question disappears.

For Sprint 104, the provider has its cache and the `Order`
extension has its cache, and that is sufficient — the worst case is
**two** Stripe HTTP calls per render (provider's expanded PI +
Order extension's PI+Charge), not the current ≈10. Even that is
solved as a side effect once the panel view-data builder is fully
migrated to read the model's amounts through the provider
(Sprint 105 follow-up).

## 5. The five pillars

### 5.1 SOLID

- **SRP.** No new class. The change is "use the cache that already
  exists." Each modified method still has one responsibility.
- **OCP.** Methods keep their signatures (the `refresh` parameter
  is preserved); only their default-path behaviour changes.
  Mutation-path callers stay untouched.
- **LSP.** No class hierarchy changes. `Order` is a class extension;
  its public interface is unchanged.
- **ISP.** No interface changes.
- **DIP.** No DI changes.

### 5.2 TDD — call-count tests first

Walking order:

1. Write `StripePanelApiCallCountTest.php` (§6 case list). Run.
   Expect red — the provider currently issues multiple PI / Charge
   retrievals per render.
2. Drop `refresh=true` in the two provider methods. Run; resolve
   the easy half of the test expectations.
3. Memoise `Order::getStripeCharge()`. Run; resolve the rest.
4. Refactor `getPaymentIntent()` / `getLastCharge()` to read from
   the expanded PI cache. Re-run all touched tests.

Expected pre-fix red message for
`testPanelRenderIssuesOneExpandedPiCall`:
`Failed asserting that 5 matches expected 1.` (Exact number
varies by fixture path — the assertion is on equality with 1, not
on the present incorrect value.)

### 5.3 DRY — grep gate

After the sprint:

```bash
grep -nE ', *true\)' source/extensions/stripe/src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php
```

returns zero lines. Any future re-introduction of `refresh=true` on
a render-path read fails CI through the call-count test in §6.

### 5.4 Liskov

`Order::getStripeCharge()` remains `protected` and its return type
stays `?\Stripe\Charge`. Substitutability with testable subclasses
(which override `getStripeCharge()` to return a fixture) is
preserved — those subclasses bypass the new memoisation entirely
by overriding the method, which is the documented seam.

### 5.5 Clean Code / DI

- Cache fields on `Order` are `private`. Methods stay ≤ 15 lines.
- No new comments explaining what — only the one line on the
  `chargeCacheLoaded` boolean explaining *why* the flag exists
  (null is a valid cached value).
- No `Registry::get(...)` introduced. The existing `ContainerFactory`
  locator in `Order` is unchanged; sprint 104 only adds a cache
  around it.

## 6. Test matrix — call counts the existing suite misses

`StripePanelApiCallCountTest.php` uses a stub `StripeOrderApiService`
that increments a counter per method.

| # | Test method | Acts on | Assertion |
|---|---|---|---|
| 1 | `testPanelRenderIssuesOneExpandedPiCall` | Build view-data via `StripePanelViewDataBuilder::build($order)` | `getPaymentIntentWithRefunds` count == 1; `getPaymentIntent` count == 0; `getLastCharge` count == 0 |
| 2 | `testIsOrderRefundableReusesCachedCharge` | Call `getPaymentIntent`, then `isOrderRefundable` | After both, total Stripe call count remains == 1 |
| 3 | `testOrderExtensionMemoisesChargePerInstance` | Call `getStripeCapturedAmount`, `getStripeRefundedAmount`, `hasStripeRefunds` on the same `Order` instance | PI retrieve count == 1, Charge retrieve count == 1 (the extension's pair) |
| 4 | `testMutationPathStillRefreshes` | Provider's `getPaymentIntent($order, true)` after a memoised read | PI retrieve count == 2 (cache + forced refresh) |

The four cases together pin both the dedup invariant and the
intentional escape hatch for mutation paths.

## 7. Acceptance gates

- [ ] `./bin/pre-commit-check.sh --full` green. Test total ≥
      pre-sprint baseline + 4.
- [ ] PHPStan max: 0 new errors.
- [ ] PHPCS: 0 errors on touched files.
- [ ] PHPMD: 0 new findings.
- [ ] Grep gate from §5.3 returns zero lines.
- [ ] Manual smoke on the reporter's setup: open Stripe tab, time
      the paint segment in DevTools Network panel — should be
      ≤ 400 ms steady-state on a warm route (down from ≈1.9 s).
- [ ] No diff to `CaptureService`, `RefundService`,
      `CancelAuthorizationService`, `services.yaml`,
      `metadata.php`, any twig template.

## 8. Out of scope / explicit deferrals

- **Cross-request caching.** Sprint 105 (Layer B) — contract-row
  snapshot blob with idempotency token.
- **Async transaction-history UI / spinner.** Sprint 106 (Layer C).
- **Sharing the provider and Order-extension caches.** Made moot
  by Sprint 105's snapshot blob; not worth a separate seam in 104.
- **Playwright timing spec.** Anchors (`data-stripe-panel`,
  `data-flash="success"`) added in Sprint 106 alongside the
  async loader; spec itself ships with 106 as the acceptance
  harness for all three layers.

## 9. Risk register

- **Risk:** a mutation path elsewhere relies on the side effect
  that `isOrderRefundable($order)` returns *freshly-fetched* data
  (because of the `refresh=true`). **Mitigation:** grep the
  codebase for callers of `isOrderRefundable` and
  `getRemainingRefundableRaw` — both are read-only renderers
  (panel + admin templates). No mutation path depends on them.
- **Risk:** the memoised charge on `Order` outlives the request
  in some long-running CLI context. **Mitigation:** OXID admin
  uses per-request lifecycles; the only long-running path is
  `bin/oe-console` commands, which do not instantiate the admin
  panel and do not call the affected accessors.
- **Risk:** the expanded PI payload is larger than the plain PI;
  forcing every reader through it adds bandwidth even when only
  the status is needed. **Mitigation:** the entire saving is on
  round-trip count, not payload size. One ~5 KB response is
  cheaper than ten ~2 KB responses on EU latency.

## 10. Done definition

- [ ] §7 acceptance, every box.
- [ ] Sprint markdown moves from `sprints/` to `done/` with a
      `done/sprint-104-completion-report.md` recording the
      pre/post call counts (extracted from the new test) and a
      DevTools Network screenshot showing 1 Stripe request on
      panel render.
- [ ] `status.md` updated.
- [ ] Sprint 105 follow-up confirmed unblocked.
