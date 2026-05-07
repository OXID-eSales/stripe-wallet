# Sprint 94 — TDD lock-down of the admin "Approve" / "Resolve" → Stripe-refund event chain

**Repo:** `extensions/opalreturns` (with cross-references to
`extensions/payment-component`, `extensions/stripe`)
**Mode:** TDD-first. Tests precede implementation; no production code
edits unless a red test demands it.
**Trigger report:**
[`../reports/01-return-start-does-not-trigger-stripe-refund-or-cancel.md`](../reports/01-return-start-does-not-trigger-stripe-refund-or-cancel.md)

## 1. Why

Sprint 89 (`tests/Integration/RefundFlowIntegrationTest.php`) already
proves the **service-layer** chain `PaymentComponentResolutionHandler →
ReturnRefundRequestedEvent → broker → StripeRefundRequestEvent →
PaymentRefundedEvent → Resolved`. What is **not** locked down today,
and what report 01 forced into the open:

1. **No controller-level test exists.** `AdminReturnMainController`
   (`src/Controller/Admin/AdminReturnMainController.php`) wires
   "Approve", "Reject", "Mark Shipped/Received/Inspected/Replacement
   Shipped", and "Resolve" buttons of the Returns Details admin page to
   workflow / resolution services. None of these wire-ups have a unit
   test. A future refactor that loses one wire is invisible to CI.
2. **No "Approve does NOT trigger refund" assertion exists.** This is
   the negative invariant from report 01: when the admin clicks
   *Approve* on a `Requested` return, only `ReturnApprovedEvent` may
   leave the bus — `ReturnRefundRequestedEvent` and any concrete PSP
   request event must NOT. Without this, a regression that hooks the
   refund onto Approve (the very thing the original bug report
   suggested) would slip in undetected.
3. **No end-to-end "controller click → Stripe event" test exists.**
   `RefundFlowIntegrationTest` enters at the resolution **service**.
   We want one test that enters at the controller *function* the
   admin button posts to (`opalreturnsResolve`) and asserts the
   `StripeRefundRequestEvent` arrives at the PSP boundary with the
   expected `orderId / contractId / amount / reason`.

## 2. Goals

This sprint adds **three** new test files. No production code changes
are intended. Tests must pass when run with the existing
`phpunit.xml` Unit and Integration suites:

```
docker compose exec php php vendor/bin/phpunit -c extensions/opalreturns/tests/phpunit.xml --testsuite Unit
docker compose exec php php vendor/bin/phpunit -c extensions/opalreturns/tests/phpunit.xml --testsuite Integration
```

If a test cannot reach the assertion without a production change, the
sprint has uncovered a real defect — escalate as a separate finding,
do **not** silently shape tests around the bug.

### 2.1 Test file 1 — `Unit/Controller/Admin/AdminReturnMainControllerTest.php`

| # | Behaviour | Expected |
|---|---|---|
| T1 | `opalreturnsApprove()` with a valid edit-object id | calls `StatusTransitionService::transition(returnId, ReturnStatus::Approved)` exactly once; resolution service is NOT called |
| T2 | `opalreturnsApprove()` with no / `-1` edit-object id | no service call (early return) |
| T3 | `opalreturnsReject()` with valid id | transitions to `ReturnStatus::Rejected`; no resolution call |
| T4 | `opalreturnsMarkShipped()` / `opalreturnsMarkReceived()` / `opalreturnsMarkInspected()` / `opalreturnsMarkReplacementShipped()` (table-driven) | each transitions to the matching `ReturnStatus`; no resolution call |
| T5 | `opalreturnsResolve()` with `opalreturns_refund_amount=29,90` | calls `ReturnResolutionService::resolve('ret-1', 29.9)` (comma is normalised); transition service is NOT called from the controller |
| T6 | `opalreturnsResolve()` with empty refund amount | calls `resolve('ret-1', null)` — null lets the suggested amount be used |
| T7 | `opalreturnsResolve()` with no edit-object id | no resolution call |

T1 and T5 together carry the user's question: clicking Approve does
*not* invoke any refund pathway (T1 asserts the negative); clicking
Resolve *does* — and it goes through `ReturnResolutionService` which
the integration test then proves dispatches the right Stripe event.

### 2.2 Test file 2 — `Unit/Workflow/ApproveDoesNotEmitRefundEventTest.php`

A focused **negative invariant** test, isolated from the controller:

| # | Behaviour | Expected |
|---|---|---|
| N1 | `StatusTransitionService::transition('ret-1', ReturnStatus::Approved)` is called against a real `Psr14EventDispatcherAdapter` wired to a `Symfony\\EventDispatcher\\EventDispatcher` with `PaymentComponentRefundBrokerListener` and a `RefundRequestedEvent` spy registered on the broker | exactly one event dispatched: `ReturnApprovedEvent`. **Zero** `ReturnRefundRequestedEvent` instances. **Zero** `RefundRequestedEvent` instances on the broker. **Zero** `StripeRefundRequestEvent` instances on the PC dispatcher. |

This test exists so CI fails if anyone wires Approve into the refund
broker. It is the test that "report 01 wishes had existed."

### 2.3 Test file 3 — `Integration/AdminResolveDispatchesStripeRefundEventTest.php`

Extends the existing `RefundFlowIntegrationTest` pattern but **enters
at the controller**:

| # | Behaviour | Expected |
|---|---|---|
| I1 | A `TestableAdminReturnMainController` (subclass that bypasses OXID admin bootstrap) with `getEditObjectId()` returning `'ret-1'`, request mock returning `'29.90'` for `opalreturns_refund_amount`, and `opalreturnsGetResolutionService()` overridden to return a real `ReturnResolutionService` constructed with the in-memory dispatcher chain from the existing integration fixture; calls `opalreturnsResolve()`. | The Stripe spy registered on the PC dispatcher receives exactly one `StripeRefundRequestEvent` whose `orderId == 'order-1'`, `contractId == 'contract_stripe_1'`, `amount == 29.90`, `reason == 'return_credit'`. The return is transitioned to `Resolved` exactly once. |
| I2 | Same wiring but the contract repo has no contract for the order. | Stripe spy is **not** invoked; no `Resolved` transition occurs. |
| I3 | Same wiring but the contract's provider is `'unknown_provider'`. | Stripe spy is **not** invoked; no `Resolved` transition occurs. |

I1 is the user's exact ask: a test in opalreturns that proves the
refund request is sent through the proper Stripe event when the admin
acts on the Returns Details page.

## 3. Design — SOLID, Clean Code, Liskov, DRY

### 3.1 Seam: testable subclass on `AdminReturnMainController`

OXID's `AdminDetailsController` ancestry doesn't accept constructor
DI, so we follow the established **testable-subclass** pattern (see
`extensions/stripe/CLAUDE.md` §"Testable Subclass Pattern"):

```php
final class TestableAdminReturnMainController extends AdminReturnMainController
{
    public function __construct(
        private readonly ?StatusTransitionServiceInterface $tx = null,
        private readonly ?ReturnResolutionServiceInterface $resolution = null,
        private string $editObjectId = '',
        private string $refundAmountInput = '',
    ) {
        // intentionally skip parent::__construct — bypasses OXID
        // admin bootstrap (oxNew, Registry, virtual parent classes)
    }

    public function setEditObjectId(string $id): void { $this->editObjectId = $id; }
    public function setRefundAmountInput(string $raw): void { $this->refundAmountInput = $raw; }

    public function getEditObjectId(): string { return $this->editObjectId; }

    protected function opalreturnsGetTransitionService(): StatusTransitionServiceInterface
    {
        return $this->tx ?? throw new \LogicException('tx service not seeded in test');
    }

    protected function opalreturnsGetResolutionService(): ReturnResolutionServiceInterface
    {
        return $this->resolution ?? throw new \LogicException('resolution service not seeded in test');
    }

    // override Registry::getRequest() boundary inside opalreturnsResolve
    // by introducing a protected `readRefundAmountInput(): string`
    // method on the *production* class. (Single, small refactor —
    // see §5.)
}
```

**Liskov:** the testable subclass returns a service implementing the
same interface as production (`StatusTransitionServiceInterface`,
`ReturnResolutionServiceInterface`); behaviour is restricted, not
weakened — callers cannot tell the difference.

**ISP:** controllers depend on **interfaces** that already exist
(`StatusTransitionServiceInterface`,
`ReturnResolutionServiceInterface`). No new interfaces needed.

**DIP:** the controller already depends on abstractions through its
`opalreturnsGet*` lookup methods. Tests substitute the concrete
implementation behind the same abstraction. No DI container is
constructed in tests — that would make them integration tests by
default and slow them down.

**SRP:** each test class has one reason to change:
- File 1 — controller wiring of admin actions.
- File 2 — Approve never produces a refund.
- File 3 — Resolve produces the Stripe-bound refund event end-to-end.

### 3.2 Single small production refactor (§5) to remove the `Registry::getRequest()` static seam

`opalreturnsResolve()` currently reads the request inline:

```php
$raw = (string) Registry::getRequest()->getRequestEscapedParameter('opalreturns_refund_amount');
```

The static call is the only thing standing between this controller
function and a clean unit test. Extract a protected reader:

```php
protected function readRefundAmountInput(): string
{
    return (string) Registry::getRequest()->getRequestEscapedParameter('opalreturns_refund_amount');
}
```

…then `opalreturnsResolve()` calls `$this->readRefundAmountInput()`.
The testable subclass overrides `readRefundAmountInput()` to return a
seeded string. This is the **only** production change permitted in
this sprint, and it is required to satisfy T5 / T6 / I1.

The move follows the project's "OXID core static is the only valid
seam to override" rule
(`extensions/stripe/CLAUDE.md` §"Code Style Rules"); the same pattern
is used across the Stripe module's own admin controllers.

### 3.3 DRY — fixtures and the in-memory PC fixture

`RefundFlowIntegrationTest` has private helpers (`registerOpalListeners`,
`registerStripeSpyHandler`, `contract`, `contractsReturning`,
`inspectedCreditReturnRow`). They belong in a small reusable trait so
File 3 can compose the same chain without copy-paste:

```
tests/Integration/Support/PaymentComponentChainFixture.php   (trait)
```

Trait responsibilities (single, well-named):
- `setupChain(string $provider): { broker, contracts, returns, transition, opalDispatcher, capturedStripe }`
- `inspectedCreditReturnRow(string $returnId, string $orderId): array`

`RefundFlowIntegrationTest` switches to `use PaymentComponentChainFixture`
in a follow-up cleanup commit (out of scope for this sprint, listed
under §6 "Follow-ups"). For *this* sprint, File 3 introduces the
trait and uses it; the existing test stays untouched (see Liskov / OCP
— do not edit a green test in the same sprint as new tests).

### 3.4 Clean code rules (project standard)

- AAA layout: each test method has Arrange / Act / Assert clearly
  separated by blank lines, no hidden assertion helpers.
- Each test asserts **one** behaviour — see `T1` vs `T2` split.
- Test names read as English sentences (`testApproveTransitionsToApprovedAndDoesNotInvokeRefund`).
- No magic numbers — refund amount, return id, contract id, provider
  name are named constants of the test class.
- No `setUp()` mutation that escapes to test methods — fixtures are
  built per-test (PHPUnit best practice and a project rule from earlier
  sprints).
- All mocks are of **interfaces**, not concrete classes
  (memory `feedback_oxid_dao_mocking.md`). The few concrete classes
  needed (`EventBroker`, `StripeEventTranslator`,
  `Psr14EventDispatcherAdapter`) are real instances, not mocks.
- No `\Exception` inline references; explicit `use` for all imports.

## 4. Acceptance criteria

The sprint is done when, with no production code change other than
§3.2, the following all pass clean:

```
./bin/pre-commit-check.sh --full   # in extensions/opalreturns
```

…and the new test counts are visible in the integration run (delta
≥ 7 unit tests + 3 integration tests + ≥ 1 dedicated negative test).

PHPCS / PHPStan / PHPMD must remain at 0 new findings. The opalreturns
PHPMD baseline is **not** amended for this sprint.

Manual smoke (operator):
- Apply the §3.2 refactor locally.
- Activate `oe_payments_stripe_wallet`, place an order, ship it,
  start a return, walk through Approve → Mark Received → Mark
  Inspected → Resolve. Verify Stripe dashboard shows the refund.
- Re-run the test suite — it passes.

## 5. Allowed production change (only one)

`src/Controller/Admin/AdminReturnMainController.php`

```diff
-        $raw = (string) Registry::getRequest()->getRequestEscapedParameter('opalreturns_refund_amount');
+        $raw = $this->readRefundAmountInput();
         $amount = $raw !== '' ? (float) str_replace(',', '.', $raw) : null;
```

```diff
+    /**
+     * Boundary seam for the request-bound refund amount input.
+     *
+     * @return string Raw value as POSTed; empty string if absent.
+     */
+    protected function readRefundAmountInput(): string
+    {
+        return (string) Registry::getRequest()->getRequestEscapedParameter('opalreturns_refund_amount');
+    }
```

Nothing else. If a test requires more, raise a **separate** finding —
do not bundle architectural changes with a test sprint.

## 6. Out of scope / follow-ups

- Refactoring `RefundFlowIntegrationTest` to consume the new
  `PaymentComponentChainFixture` trait (separate sprint, behaviour
  preserved).
- Auto-refund on `ReturnRequestedEvent` (the bigger architectural
  question raised in report 01 — needs a product decision; not a
  testing sprint).
- Cancel-authorization fan-out for manual-capture returns (also from
  report 01, needs a new event class + broker translator on
  payment-component side).
- Frontend / Playwright coverage of the admin Returns Details page —
  out of scope; the e2e environment auth flakiness (see report 01
  §"Test-side notes") would dominate the work.

## 7. TDD walking order

1. Add the §3.2 refactor as a *failing* test first
   (T5 expects the controller to call resolve with `29.9`); the
   refactor lands in the same commit only if T5 is red without it.
2. Land File 1 (`AdminReturnMainControllerTest`) — T1…T7. Stop after
   green.
3. Land File 2 (`ApproveDoesNotEmitRefundEventTest`) — N1. Stop after
   green; this is the one test that, if it ever turns red later,
   re-opens the report-01 ticket.
4. Land File 3 (`AdminResolveDispatchesStripeRefundEventTest`) — I1
   first (positive), then I2 / I3 (negative). Reuse the new
   `PaymentComponentChainFixture` trait.
5. Run full pre-commit. Update test count baseline in dev-log status
   if applicable.

## 8. Done definition (checklist)

- [ ] Production change in §5 landed
- [ ] T1…T7 green
- [ ] N1 green
- [ ] I1, I2, I3 green
- [ ] PHPCS / PHPStan (level max) / PHPMD: 0 new findings
- [ ] Sprint moved to `done/` with completion report alongside it
- [ ] `status.md` updated with the new test count baseline
