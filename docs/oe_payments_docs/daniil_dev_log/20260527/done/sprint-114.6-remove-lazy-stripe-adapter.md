# Sprint 114.6 — Remove redundant `LazyStripeAdapter`, rewire to factory

**Module:** `extensions/stripe`
**Priority:** P1 (overengineering + Liskov gap)
**Findings:** O3 (No Overengineering), L2 (Liskov — not substitutable for `StripeAdapter`)
**Mode:** single commit, TDD-first. Deletes 1 class (~130 LOC), rewires 2 services + `services.yaml`.
**Depends on:** none (independent of 114.4/114.5).
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-9.1** (proxy forwards without adding value), **R-3.3** (substitutability), **R-4** (inject the factory directly).

## 1. Why

`LazyStripeAdapter` (`src/Stripe/Adapter/LazyStripeAdapter.php`, 16 forwarding
methods) is a proxy over `StripeAdapterFactory`. But:

- The factory is **already lazy** — `getStripeAdapter()` builds on demand, so the proxy solves a problem the factory already solves.
- It implements only `PaymentAdapterInterface` (the agnostic subset), **not** `StripeAdapterInterface`, so it is **not substitutable** for `StripeAdapter` (L2) — the "mirrors the interface" claim is false.
- Only 2 services use it (`services.yaml:789,798` — `StripeCaptureService`/`StripeRefundService`, themselves dead per 114.5 O2), and both call only agnostic methods.
- `getAdapter()` **caches** the adapter, defeating the factory's fresh-client-per-call contract every other caller relies on.
- It carries a dead `use CreatePaymentResponse;` import.

## 2. Goals

- **G1.** `LazyStripeAdapter` deleted.
- **G2.** Any remaining live consumer injects `StripeAdapterFactoryInterface` directly (the pattern every other service already uses) and calls `getStripeAdapter()->xxx()`.
- **G3.** No behavior change to capture/refund execution (fresh-client-per-call preserved — the bug the cache introduced is gone).
- **G4.** `services.yaml` references to `LazyStripeAdapter` removed.
- **G5.** PHPMD baseline entry `LazyStripeAdapter: TooManyPublicMethods` removed from `tests/PhpMd/phpmd.baseline.xml`.
- **G6.** `./bin/pre-commit-check.sh --full` green.

## 3. Interaction with 114.5

If 114.5 (O2) deletes `StripeCaptureService`/`StripeRefundService` — the only
consumers — then `LazyStripeAdapter` has **zero** consumers and this sprint is
a pure deletion. Sequence 114.5 O2 first if possible; otherwise this sprint
must first rewire those 2 services to the factory, then delete the proxy.

## 4. TDD plan

1. `grep -rn "LazyStripeAdapter" src/ tests/ services.yaml` — enumerate every reference.
2. For each live consumer, add a test asserting it calls `factory->getStripeAdapter()` and forwards the request/response unchanged (real factory with a mocked adapter, or a fake factory).
3. **RED→GREEN:** rewire the consumer to the factory; the forwarding test passes; delete the proxy and its test.
4. Confirm no test references `LazyStripeAdapter` after deletion.

## 5. Implementation steps

1. Coordinate with 114.5 O2 (preferred: delete the dead consumers first → trivial deletion here).
2. If consumers remain: replace `private readonly LazyStripeAdapter $adapter` with `private readonly StripeAdapterFactoryInterface $adapterFactory`; change call sites to `$this->adapterFactory->getStripeAdapter()->...`.
3. Delete `LazyStripeAdapter.php` + `LazyStripeAdapterTest` (if any).
4. Remove `services.yaml` definitions/aliases.
5. Trim the PHPMD baseline; `oe:cache:clear` + `restart php`.

## 6. Risks & rollback

- **Risk:** something relied on the proxy's per-instance caching for performance. Measure: the factory call is cheap; the cache was a correctness liability (stale client/idempotency). If a real hot-path need exists, cache **inside** the factory, not a proxy.
- **Rollback:** single commit revert.

## 7. Definition of Done

- G1–G6 met; `grep -rn "LazyStripeAdapter"` empty across `src/ tests/ services.yaml`.
- Completion report confirms fresh-client-per-call is preserved and PHPMD baseline shrank.
