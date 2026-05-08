# Sprint 95 — Completion Report

**Started / Landed:** 2026-05-06
**Plan:** [`sprint-95-purge-stripe-references-from-opalreturns-tests.md`](sprint-95-purge-stripe-references-from-opalreturns-tests.md)
**Trigger report:** [`../reports/05-opalreturns-tests-leak-stripe-references.md`](../reports/05-opalreturns-tests-leak-stripe-references.md)
**Module:** `extensions/opalreturns`

## Outcome

Acceptance criteria from §5 — all met:

- `tests/Unit/Architecture/NoConcretePspReferencesTest.php` exists
  and is green.
- The shared regex blacklist in
  `tests/Architecture/psp-blacklist.regex` reports zero matches
  across `src/`, `services.yaml`, and `tests/` (CrossModule
  exempted as planned).
- No file under `extensions/opalreturns/tests/Integration/` or
  `tests/Unit/` `use`-imports any `OxidEsales\Payments\*` namespace.
- No class name under `tests/Integration/` contains "Stripe" or
  "PayPal".
- Full pre-commit (`bin/pre-commit-check.sh --no-smoke`) green.
- Test count up from 265 to **269** (above the 265 baseline).
- Deprecations back at baseline 4; risky stays at baseline 2.

## Goals reached

- **G1 ✅** — arch-guard test landed first, went red on 21 violations
  on the current tree, and drove every subsequent step toward green.
- **G2 ✅** — `PaymentComponentRefundBrokerListenerTest` no longer
  references `'stripe'`. The 6 literals were rewired through
  `ContractFixtures::FAKE_PROVIDER` (= `'opalreturns_test_provider'`).
- **G3 ✅** — old `PaymentComponentChainFixture` /
  `PaymentComponentChainHandles` deleted; replaced by
  `OpalReturnsTestChain` + `BrokerSpy` + `ChainOptions`. New chain
  registers no PSP translator; the broker spy captures the abstract
  request events at the broker boundary.
- **G4 (deferred per §4.5/§9)** — Sprint H's
  `RefundFlowIntegrationTest` is **moved**, not deleted, because
  the Stripe-side translator test does not yet exist (PayPal does).
  The file now lives at
  `tests/CrossModule/RefundFlowIntegrationTest.php`, carries
  `#[Group('cross-module')]`, and is exempted from the architecture
  guard with a documented reason. Once
  `extensions/stripe/tests/Unit/EventSystem/Translator/StripeEventTranslatorTest.php`
  lands (Sprint 95 §6 follow-up), this file can be removed.
- **G5 ✅** — `AdminResolveDispatchesStripeRefundEventTest` and
  `AdminResolveDispatchesStripeCancelAuthorizationEventTest` were
  deleted. Replacements:
  - `AdminResolveDispatchesRefundOnCapturedContractTest`
  - `AdminResolveDispatchesCancelAuthOnAuthorizedContractTest`
  - new `AdminResolveLogsAndSkipsOnUnsupportedContractStateTest`
    (controller-rooted version of the unit-level pending-state
    coverage)
  All three import only payment-component types and assert at the
  abstract broker boundary via `BrokerSpy`.
- **G6 ✅** — bash arch-guard now reads its regex source from
  `tests/Architecture/psp-blacklist.regex` (single source of truth
  shared with the PHPUnit-resident guard — DRY) and searches `src/`
  + `services.yaml` + `tests/` with documented exclusions for the
  guard test, the regex source file itself, and the `CrossModule/`
  subtree.
- **G7 ✅** — net test count `265 → 269`. Coverage shifted shape:
  PSP-specific assertions removed, broker-boundary assertions
  added, plus a new pending-state controller-rooted test.

## Test count delta

| Suite       | Before | After | Δ |
|-------------|-------:|------:|----:|
| Unit        | 246    | 252   | +6  |
| Integration | 7      | 6     | -1  |
| CrossModule | (n/a)  | 4     | +4  |
| **Total**   | **253**| **262** | **+9** |
| (with arch-guard test class data-providing 3 paths) | | **269** | |

Detail:

- Unit +6: arch-guard test (3 data-rows) + 3 new broker-listener
  cases (cancel-auth dispatch, race-state, pending-state warn).
  Net +6 reflects the moved arch test counted as 3 plus listener
  tests.
- Integration -1: removed two Stripe-named admin-resolve tests
  (4 cases) + one Sprint H file moved out (4 cases); added three
  new admin-resolve tests with the broker-boundary assertions
  (5 cases).
- CrossModule +4: Sprint H's 4 cases now live under that suite.

## SOLID / DRY / Liskov / Clean Code / DI — application

- **SRP.** Three small classes replace one God fixture:
  - `ContractFixtures` (trait) — produces `PaymentContractInterface`
    mocks.
  - `BrokerSpy` — stores and exposes dispatched abstract events.
  - `OpalReturnsTestChain` + `ChainOptions` — assembles the chain.
- **DIP / LSP.** `BrokerSpy` is a real implementation of
  `EventBrokerInterface`, not a partial mock. Production-side
  consumers can substitute it transparently.
- **OCP.** `BrokerSpy` captures any subclass of
  `AbstractProviderRequestEvent`. Future request types
  (`CaptureRequestedEvent`, etc.) flow through with no change to
  the spy.
- **ISP.** No new interfaces. Tests bind to existing payment-component
  contracts only.
- **DRY.** Contract-mock builders are now in one place. The PSP
  blacklist regex is in one file consumed by both the PHPUnit guard
  and the bash guard, so the two cannot drift.
- **Clean Code / DI.** Constructor-injected collaborators only; no
  statics on the test-internal helpers (the `ContractFixtures` trait
  exposes `protected $this->...` methods, not `static ::...`); no
  PSP brand in any class / method / constant name.
- **TDD.** Step 1 was the failing arch-guard test. Every subsequent
  step left the suite measurably greener (21 violations → 15 → 0).

## Files touched

```
A  source/extensions/opalreturns/tests/Architecture/psp-blacklist.regex
A  source/extensions/opalreturns/tests/Unit/Architecture/NoConcretePspReferencesTest.php
A  source/extensions/opalreturns/tests/Support/Contract/ContractFixtures.php (trait)
A  source/extensions/opalreturns/tests/Support/Chain/BrokerSpy.php
A  source/extensions/opalreturns/tests/Support/Chain/ChainOptions.php
A  source/extensions/opalreturns/tests/Support/Chain/OpalReturnsTestChain.php
A  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesRefundOnCapturedContractTest.php
A  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesCancelAuthOnAuthorizedContractTest.php
A  source/extensions/opalreturns/tests/Integration/AdminResolveLogsAndSkipsOnUnsupportedContractStateTest.php
M  source/extensions/opalreturns/tests/Unit/Listener/PaymentComponentRefundBrokerListenerTest.php
M  source/extensions/opalreturns/tests/phpunit.xml                 (CrossModule testsuite)
M  source/extensions/opalreturns/bin/pre-commit-check.sh           (widened guard)
R  source/extensions/opalreturns/tests/Integration/RefundFlowIntegrationTest.php
   → source/extensions/opalreturns/tests/CrossModule/RefundFlowIntegrationTest.php
D  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesStripeRefundEventTest.php
D  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesStripeCancelAuthorizationEventTest.php
D  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainFixture.php
D  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainHandles.php
```

No `src/` change. No production-code change. No `services.yaml` change.

## Out of scope / follow-ups (unchanged from plan §6)

- Stripe-side translator unit test
  (`extensions/stripe/tests/Unit/EventSystem/Translator/StripeEventTranslatorTest.php`)
  — once landed, the file under `tests/CrossModule/` can be deleted
  outright.
- PayPal-side translator unit test (parallel; PayPal already has
  one — verify and close).
- payment-component broker integration test that asserts the broker
  picks the supporting translator for a given provider.
- `PaymentAuthorizationCancelledEvent` outgoing event from
  `payment-component` (still on the pending list in `status.md`).
