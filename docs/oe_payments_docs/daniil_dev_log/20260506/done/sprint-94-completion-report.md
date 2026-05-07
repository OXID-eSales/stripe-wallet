# Sprint 94 — Completion Report

**Started / Landed:** 2026-05-06
**Plan:** [`sprint-94-admin-approve-refund-event-tdd.md`](sprint-94-admin-approve-refund-event-tdd.md)
**Module:** `extensions/opalreturns`

## Outcome

All 8 done-checklist items met. Full `bin/pre-commit-check.sh` is
green (`Status: COMMITABLE`):

```
PHPCS:                 0 errors
PHPStan (level max):   0 errors
PHPMD:                 skipped — no ruleset in opalreturns (pre-existing)
Architecture guards:   passed (opalreturns imports no PSP class)
PHPUnit:               253 tests, 639 assertions
PHPUnit deprecations:  4 (baseline — pre-existing @dataProvider doc-comments)
PHPUnit risky:         2 (baseline — pre-existing optional-loadability tests)
```

## Test count delta

| Suite       | Before sprint | After sprint | Δ |
|-------------|--------------:|-------------:|----:|
| Unit        | 233           | 246          | +13 |
| Integration | 4             | 7            | +3  |
| **Total**   | **237**       | **253**      | **+16** |

## What landed

### Production refactor (the only one allowed)

`src/Controller/Admin/AdminReturnMainController.php`

- Extracted the `Registry::getRequest()->getRequestEscapedParameter('opalreturns_refund_amount')`
  call into a new protected `readRefundAmountInput(): string` seam.
- `opalreturnsResolve()` now reads the refund amount via that seam.
- The `Registry::*` static call still lives inside the seam method —
  it is the only valid place for it under the project's "OXID core
  static is the only valid seam to override" rule.

### New test files

1. **`tests/Unit/Controller/Admin/AdminReturnMainControllerTest.php`** — 11 tests, 38 assertions.
   - T1: Approve transitions to Approved AND does not invoke the
     resolution service (the negative-control assertion the user
     specifically asked for, kept as one cohesive AAA).
   - T2 / T3: empty / `-1` edit object id → no-op for Approve.
   - T4: Reject transitions to Rejected.
   - T5: data-provider over the four "Mark *" buttons → each
     transitions to its matching state. Uses
     `#[DataProvider]` attribute (PHPUnit 12 ready), keeping the
     deprecation count at the pre-sprint baseline.
   - T6: Resolve calls the resolution service with `29.9` (parsed
     from `'29,90'`) and does NOT call the transition service
     directly.
   - T7: Resolve with empty input → resolution service called with
     `null`.
   - T8: Resolve with missing edit object id → no resolution call.

   Seam: `TestableAdminReturnMainController` — final subclass that
   skips `parent::__construct`, exposes `getEditObjectId()`,
   `opalreturnsGet*Service()`, and `readRefundAmountInput()` as
   constructor-injected fields. Liskov-clean (returns the same
   service interfaces as production).

2. **`tests/Unit/Workflow/ApproveDoesNotEmitRefundEventTest.php`** — 2 tests, 7 assertions.
   - N1a: Wires real `Psr14EventDispatcherAdapter` →
     `Symfony\\EventDispatcher`, registers
     `PaymentComponentRefundBrokerListener` on
     `ReturnRefundRequestedEvent`, calls
     `StatusTransitionService::transition('ret-1', Approved)`.
     Asserts: exactly one event reaches the bus, it is
     `ReturnApprovedEvent`, the broker is **never** dispatched
     to. The single tripwire that re-opens report 01 if anyone ever
     re-wires Approve into the refund pipeline.
   - N1b: `ReturnApprovedEvent` carries no `refundAmount` in its
     context — refund payload is reserved for `ReturnRefundRequestedEvent`.

3. **`tests/Integration/AdminResolveDispatchesStripeRefundEventTest.php`** — 3 tests, 18 assertions.
   - I1: Drives the full chain from
     `AdminReturnMainController::opalreturnsResolve()` (via
     `TestableAdminReturnMainControllerForChain`) through a real
     `ReturnResolutionService` → real
     `PaymentComponentResolutionHandler` → real
     `Psr14EventDispatcherAdapter` → real
     `PaymentComponentRefundBrokerListener` → real
     `EventBroker` + `StripeEventTranslator`. Asserts a
     `StripeRefundRequestEvent` lands at the PSP boundary with
     `amount=29.9`, `orderId='order-1'`,
     `contractId='contract_stripe_1'`, `reason='return_credit'`.
     Also asserts the return is transitioned to `Resolved` exactly
     **twice** (once synchronously by the resolution service, once
     by `PaymentRefundedReturnResolver` after the spy dispatches
     `PaymentRefundedEvent`) — locking down both the sync and async
     halves of the end-state.
   - I2: Same wiring with `ContractRepository::findByOrderId() →
     null` → Stripe spy is never invoked, only the sync transition
     fires.
   - I3: `provider='unknown_provider'` → translator does not match,
     Stripe spy is never invoked.

### Reusable fixture (DRY)

`tests/Integration/Support/PaymentComponentChainFixture.php` (trait)
+ `tests/Integration/Support/PaymentComponentChainHandles.php`
(value object).

The trait builds the entire in-memory PC chain (Stripe-only — PayPal
already covered by the older `RefundFlowIntegrationTest`) and
returns a `PaymentComponentChainHandles` value object. File 3
consumes it; the older `RefundFlowIntegrationTest` was intentionally
left untouched (out-of-scope follow-up).

## SOLID / Liskov / Clean Code adherence

- **SRP.** Each test class has one reason to change: controller
  wiring, the Approve→no-refund invariant, or the controller→Stripe
  end-to-end path.
- **DIP.** All collaborators are injected. The two testable
  subclasses honour the same `*Interface` types production uses.
- **ISP.** No new interfaces. Tests rely on the existing narrow
  contracts (`StatusTransitionServiceInterface`,
  `ReturnResolutionServiceInterface`,
  `PaymentDataProviderInterface`, etc.).
- **Liskov.** Both testable subclasses can stand in for the real
  controller everywhere the parent type is expected. They restrict
  behaviour (no admin frame init, no Registry access) but never
  weaken any precondition.
- **DRY.** The PC chain fixture replaces ~80 lines of setup that
  would otherwise be duplicated between the older Sprint-H test and
  this sprint.
- **No magic numbers / strings.** `RETURN_ID`, `ORDER_ID`,
  `CONTRACT_ID` are class constants on File 3. Refund amount /
  reason / provider are local variables with named meaning.
- **No statics.** No static collaborators on the production code
  path under test (the only `Registry::*` call now lives behind the
  `readRefundAmountInput()` seam, which the tests override).

## Out of scope / follow-ups (unchanged from plan §6)

1. Refactor the Sprint-H `RefundFlowIntegrationTest` to consume the
   new `PaymentComponentChainFixture` trait. Behaviour-preserving
   cleanup; deferred.
2. Auto-refund on `ReturnRequestedEvent` — the bigger architectural
   question raised in report 01. Needs a product decision.
3. `ReturnCancelAuthorizationRequestedEvent` + broker translator on
   payment-component side, so manual-capture returns can be
   cancel-authorised. Independent of #2.
4. Frontend / Playwright admin coverage of the Returns Details page
   (deferred — same admin-auth-on-shared-cookie-domain quirk
   described in report 01).

## Files touched

```
M  source/extensions/opalreturns/composer.json                                  # (TCPDF dep — separate fix in this dev-log day)
M  source/extensions/opalreturns/src/Controller/Admin/AdminReturnMainController.php
A  source/extensions/opalreturns/tests/Unit/Controller/Admin/AdminReturnMainControllerTest.php
A  source/extensions/opalreturns/tests/Unit/Workflow/ApproveDoesNotEmitRefundEventTest.php
A  source/extensions/opalreturns/tests/Integration/AdminResolveDispatchesStripeRefundEventTest.php
A  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainFixture.php
A  source/extensions/opalreturns/tests/Integration/Support/PaymentComponentChainHandles.php
```
