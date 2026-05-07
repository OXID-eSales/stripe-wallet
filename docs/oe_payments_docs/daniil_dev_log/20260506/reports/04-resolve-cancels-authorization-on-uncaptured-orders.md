# 04 — Resolve fans out to refund OR cancel-authorization based on contract state

**Date:** 2026-05-06
**Author:** Daniil Tkachev
**Scope:** `extensions/opalreturns` (no Stripe / PayPal imports added —
the module stays PSP-agnostic)

## Background

Report 01 (§"Cancel-authorization is even more orphaned") flagged the
gap: when an admin resolved a credit return on an order whose Stripe
payment was only **authorized** (manual-capture mode, no capture yet),
opalreturns dispatched `RefundRequestedEvent` regardless. Stripe
rightly rejects refunding an uncaptured `payment_intent`; the right
PSP call is `cancel`, not `refund`.

The user re-prioritised this:

> the refund works but only if an order is captured. if an order is
> only authorized, it must cancel authorization.

## What already existed (no changes needed)

- `OxidEsales\PaymentComponent\EventSystem\Event\Request\CancelAuthorizationRequestedEvent`
  — provider-neutral request event already shipped by
  `payment-component`.
- `OxidEsales\Payments\Stripe\EventSystem\Translator\StripeEventTranslator`
  — already maps `CancelAuthorizationRequestedEvent` →
  `StripeCancelAuthorizationRequestEvent`.
- `OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCancelAuthorizationRequestHandler`
  — already calls
  `CancelAuthorizationServiceInterface::cancelAuthorization()`.

The chain was end-to-end ready on the Stripe / payment-component side.
The missing wire was on the opalreturns side: nothing dispatched
`CancelAuthorizationRequestedEvent` anywhere in the return flow.

## Change

`extensions/opalreturns/src/Listener/PaymentComponentRefundBrokerListener.php`

The single broker listener now branches on the resolved contract's
state and amount:

```
$captured = $contract->getCapturedAmount();
if ($captured !== null && $captured > 0.0) {
    // refund — same as before
    return new RefundRequestedEvent($context, $ctx->refundAmount, 'return_credit');
}

if ($contract->getState()->isAuthorized()) {
    // new path: cancel the open authorization at the PSP
    return new CancelAuthorizationRequestedEvent($context);
}

return null; // unsupported state — log + skip cleanly
```

### Discriminator rationale

- **Captured-amount-positive wins over state.** Even if a contract is
  still in `authorized` while an out-of-band capture write-back is
  pending, a positive `OXCAPTUREDAMOUNT` means money has actually
  changed hands. The right action is refund, not void. A unit test
  (`testRefundStillFiresWhenStateIsAuthorizedButCapturedAmountIsPositive`)
  pins this defensively.
- **`null` captured + state `authorized`** → cancel auth.
- **Anything else** (`pending`, `draft`, terminal states like
  `cancelled` / `expired` / `failed`) → log a warning and skip,
  rather than dispatch a no-op event. The admin's sync transition
  to `Resolved` in `ReturnResolutionService` still runs, so the
  return UI doesn't hang.

### Why no follow-up event is needed for the cancel path

The refund path closes itself via `PaymentRefundedEvent` →
`PaymentRefundedReturnResolver` → idempotent `transition(Resolved)`.
Cancel-auth has no equivalent inbound event in payment-component
today; the PSP handler sets `cancelSuccess` flags on the event
context but doesn't publish a new domain event. That is sufficient
because **`ReturnResolutionService::resolve()` already calls
`transitionService->transition($returnId, ReturnStatus::Resolved)`
synchronously** right after the handler runs (line 100). The async
broker fan-out is fire-and-forget for state-machine purposes.

A dedicated `PaymentAuthorizationCancelledEvent` would be a separate,
larger sprint for `payment-component` and is not blocking this fix.

## Tests

### Unit — `tests/Unit/Listener/PaymentComponentRefundBrokerListenerTest.php`

3 new tests added on top of the existing 7:

| # | Behaviour | Expected |
|---|---|---|
| `testDispatchesCancelAuthorizationWhenContractIsAuthorizedWithoutCapture` | contract state `authorized`, `getCapturedAmount() === null` | broker receives `CancelAuthorizationRequestedEvent`, NOT `RefundRequestedEvent`; context carries provider / orderId / contractId / contract |
| `testRefundStillFiresWhenStateIsAuthorizedButCapturedAmountIsPositive` | contract state `authorized` but `getCapturedAmount() === 50.0` | broker receives `RefundRequestedEvent` (captured-amount-wins discriminator) |
| `testNoDispatchAndWarnsWhenContractIsNeitherCapturedNorAuthorized` | contract state `pending`, no captured amount | broker `dispatch()` is **never** called; logger `warning()` is called |

The legacy `contract()` helper now defaults to a captured contract via
the new `capturedContract()` helper, plus `authorizedContract()` and
`pendingContract()` helpers. Existing tests (refund branch) remain
green unchanged.

### Integration — `tests/Integration/AdminResolveDispatchesStripeCancelAuthorizationEventTest.php`

End-to-end controller-rooted test, sister to
`AdminResolveDispatchesStripeRefundEventTest`:

| # | Behaviour | Expected |
|---|---|---|
| `testResolveOnAuthorizedOnlyOrderDispatchesStripeCancelAuthorizationEvent` | `paymentState=authorized` chain; admin posts blank refund amount | `StripeCancelAuthorizationRequestEvent` arrives at PSP boundary; refund spy never fires; sync `Resolved` transition fires exactly once |
| `testResolveOnCapturedOrderStillRoutesToRefundEvenWhenAmountInputIsBlank` | default `captured` chain; admin posts blank amount | `StripeRefundRequestEvent` fires; cancel spy never fires |

The `PaymentComponentChainFixture` trait was extended to support a
`paymentState` option (`captured` / `authorized` / `pending`) and a
`stripeCancelSpy` handle on `PaymentComponentChainHandles`. Both
options are additive — existing callers continue to receive a
captured contract by default, and the older
`RefundFlowIntegrationTest` was kept untouched apart from one
defensive contract-helper update so its mocks now stub
`getCapturedAmount` / `getState` as well.

### Test count delta

| Suite       | Before this change | After  | Δ |
|-------------|-------------------:|-------:|---:|
| Unit        | 253                | 256    | +3 |
| Integration | 7                  | 9      | +2 |
| **Total**   | **260**            | **265** | **+5** |

Full opalreturns pre-commit (`bin/pre-commit-check.sh --no-smoke`):

```
PHPCS:               0 errors
PHPStan (level max): 0 errors
Architecture guards: passed (no Stripe / PayPal imports in src/)
PHPUnit:             265 tests, 669 assertions
                     4 deprecations (baseline), 2 risky (baseline)
Status: COMMITABLE
```

## Files touched

```
M  source/extensions/opalreturns/src/Listener/PaymentComponentRefundBrokerListener.php
M  source/extensions/opalreturns/tests/Unit/Listener/PaymentComponentRefundBrokerListenerTest.php
M  source/extensions/opalreturns/tests/Integration/RefundFlowIntegrationTest.php
M  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainFixture.php
M  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainHandles.php
A  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesStripeCancelAuthorizationEventTest.php
```

## Live verification (pending)

Cleared OXID cache + restarted PHP-FPM. To verify on
`daniil.oxiddev.de`:

1. Switch Stripe capture mode to **manual**
   (`Module Configuration → sStripeCaptureMode = manual`).
2. Place an order via Stripe Checkout — the `payment_intent` ends in
   `requires_capture` (authorized, not captured).
3. Ship the order from admin (so the return becomes eligible).
4. Customer starts a return; admin walks it through Approve →
   Received → Inspected → Resolve.
5. Stripe Dashboard should show the `payment_intent` transitioning
   to `canceled` (not "refunded"), with no `charge` ever created.
