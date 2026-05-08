# 05 — opalreturns tests leak Stripe / PayPal references (architecture violation)

**Date:** 2026-05-06
**Author:** Daniil Tkachev
**Severity:** High — direct rule violation; production code is clean,
the leak is in `tests/` and in the arch-guard's blind spot.
**Scope:** `extensions/opalreturns` (tests only).

## The rule that was broken

opalreturns is a **provider-agnostic** RMA module. Its job ends when
it dispatches a payment-component request event onto the broker:

```
ReturnResolutionService
  → PaymentComponentResolutionHandler
  → ReturnRefundRequestedEvent
  → PaymentComponentRefundBrokerListener
  → broker.dispatch( RefundRequestedEvent | CancelAuthorizationRequestedEvent )    ← opalreturns ends here
       └→ PSP translator (Stripe / PayPal / future) — owned by the PSP module
            └→ PSP request handler — owned by the PSP module
```

opalreturns must therefore deal **only** in the abstract types defined
by `oxid-esales/payment-component`:

- `OxidEsales\PaymentComponent\EventSystem\Event\Request\RefundRequestedEvent`
- `OxidEsales\PaymentComponent\EventSystem\Event\Request\CancelAuthorizationRequestedEvent`
- `OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface`
- `OxidEsales\PaymentComponent\Contract\PaymentContractInterface`
- `OxidEsales\PaymentComponent\Contract\ContractState`
- ...and the broker / translator interfaces.

It must **not** name a concrete provider — neither in production code
nor in tests, neither by class import nor by string literal — because
that would couple the RMA workflow to a specific PSP and would make
the module untestable in a shop that has only PayPal (or only some
future provider) installed.

`extensions/opalreturns/bin/pre-commit-check.sh` already encodes this
rule, but only against `src/`. The guard's blind spot — `tests/` is
**not** checked — is what let the leak in.

## What is leaking today

`src/` and `services.yaml` are clean (verified with the same
expression). All violations are under `tests/`:

### A. Direct PSP class imports

- `tests/Integration/RefundFlowIntegrationTest.php` (Sprint H, pre-existing)
  imports `StripeEventTranslator`, `StripeRefundRequestEvent`,
  `PayPalEventTranslator`, `PayPalRefundRequestEvent`.
- `tests/Integration/AdminResolveDispatchesStripeRefundEventTest.php`
  (Sprint 94) imports `StripeRefundRequestEvent`.
- `tests/Integration/AdminResolveDispatchesStripeCancelAuthorizationEventTest.php`
  (today, task 6) imports `StripeRefundRequestEvent` and
  `StripeCancelAuthorizationRequestEvent`.
- `tests/Integration/Support/PaymentComponentChainFixture.php`
  (Sprint 94 + extended today) imports `StripeEventTranslator`,
  `StripeRefundRequestEvent`, `StripeCancelAuthorizationRequestEvent`;
  also a fully-qualified inline reference at line 151.

### B. String / identifier coupling

- `tests/Unit/Listener/PaymentComponentRefundBrokerListenerTest.php`
  lines 65, 211, 222, 233, 261, 275 — `'stripe'` as the contract
  provider name; one assertion checks the context carries the literal
  `'stripe'`.
- `tests/Integration/Support/PaymentComponentChainFixture.php` line 87
  — `$opts['provider'] ?? 'stripe'`; the fixture defaults to a PSP.
- `tests/Integration/Support/PaymentComponentChainFixture.php` —
  method named `buildStripeChain(...)`.
- `tests/Integration/RefundFlowIntegrationTest.php` lines 73, 126,
  344 — `'stripe'` and `'paypal'` literals.
- `tests/Integration/AdminResolveDispatchesStripeRefundEventTest.php`
  — class name contains "Stripe"; constant
  `CONTRACT_ID = 'contract_stripe_1'`.
- `tests/Integration/AdminResolveDispatchesStripeCancelAuthorizationEventTest.php`
  — class name contains "Stripe"; constant
  `CONTRACT_ID = 'contract_stripe_auth_1'`.

### C. Helper structures named after a PSP

`tests/Integration/Support/PaymentComponentChainHandles.php` carries
`stripeSpy` and `stripeCancelSpy` properties. The properties are good
ideas (capture what the broker emitted) but their **names** lock the
helper to one PSP.

## Why this is harmful even though `src/` is clean

1. **Wrong semantics.** A test like
   `testResolveOnAuthorizedOnlyOrderDispatchesStripeCancelAuthorizationEvent`
   pretends to assert about opalreturns, but actually asserts about
   the Stripe translator's mapping. If someone removes the Stripe
   translator (or the Stripe module), opalreturns's own test suite
   goes red — even though opalreturns is doing exactly what it
   should: dispatching the abstract event.
2. **Hidden cross-module dependency.** The opalreturns test suite
   silently requires the Stripe (and historically PayPal) modules to
   be installed and on the autoload path. A clean checkout of
   opalreturns alone does not run.
3. **DRY shadow.** The "PSP-translator + PSP-request-event + PSP-spy"
   pattern is repeated three times across the test suite, locking
   us in deeper each time we add a new request type
   (today's cancel-auth doubled the surface vs Sprint H).
4. **Liskov violation in the test seam.** The abstract event
   contract from payment-component is what the production code
   actually depends on. Tests that bypass the abstraction and reach
   for the concrete derived type are no longer substitutable for
   a different PSP — they assert on something the production code
   neither knows nor cares about.
5. **The arch-guard is the source of truth that's wrong.** A rule
   that's only enforced in `src/` invites the leak, then makes
   future-us blame the dev who wrote the test instead of the guard
   itself. Fix the guard so the rule is enforced everywhere it is
   meant to apply.

## What the right architecture looks like (target shape)

### Production-side responsibility split (unchanged)

opalreturns owns:
- The decision *which* abstract request event to dispatch
  (`RefundRequestedEvent` vs `CancelAuthorizationRequestedEvent`).
- The context payload it builds for that event.
- The orchestration: which order's contract, when, with what amount.

The PSP module owns:
- Translation from abstract to concrete (`StripeEventTranslator`,
  `PayPalEventTranslator`, …).
- Concrete handlers that talk to the PSP API.
- The PSP-specific tests for that translation and those handlers.

### Test-side responsibility split (target)

opalreturns tests assert on the broker boundary only:

```php
$broker->expects(self::once())->method('dispatch')
    ->with(self::isInstanceOf(CancelAuthorizationRequestedEvent::class));
```

For an integration-level test that walks
`AdminReturnMainController::opalreturnsResolve()` end-to-end on the
opalreturns side, the broker is replaced by an in-memory spy that
captures the **abstract** event. No translator, no PSP event class,
no PSP module imported anywhere.

Each PSP module then has its own integration test that subscribes
to the abstract event on the broker and verifies its handler does
the right thing. Those tests live next to the handler — i.e. in
`extensions/stripe/tests/...` — and are absolutely allowed to import
Stripe classes; that is the module they belong to.

## Recommended remediation (one-liner)

> Move every assertion in opalreturns tests to the broker-abstract
> boundary, delete every `OxidEsales\Payments\Stripe` /
> `OxidEsales\Payments\PayPal` import from opalreturns/tests, and
> extend the arch-guard to cover `tests/` as well as `src/`.

The detailed TDD plan to land that remediation without losing
coverage is in
[`../sprints/sprint-95-purge-stripe-references-from-opalreturns-tests.md`](../sprints/sprint-95-purge-stripe-references-from-opalreturns-tests.md).

## Files involved

```
M  source/extensions/opalreturns/tests/Unit/Listener/PaymentComponentRefundBrokerListenerTest.php
M  source/extensions/opalreturns/tests/Integration/RefundFlowIntegrationTest.php
M  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesStripeRefundEventTest.php
M  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesStripeCancelAuthorizationEventTest.php
M  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainFixture.php
M  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainHandles.php
M  source/extensions/opalreturns/bin/pre-commit-check.sh   (arch-guard scope)
```

No `src/` change. No production-code change.
