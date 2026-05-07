# Sprint 101 — Enforce AGB confirmation on Stripe "Order now"

**Repo:** `extensions/stripe` (no payment-component or opalreturns changes)
**Mode:** TDD-first. Tests precede implementation; no production code edits
unless a red test demands it.
**Trigger report:**
[`../reports/01-stripe-order-button-bypasses-agb-confirmation.md`](../reports/01-stripe-order-button-bypasses-agb-confirmation.md)
**Sprint window:** 2026-05-07 (1 day)
**Definition of Done (sprint-level):** With `blConfirmAGB = on`,
`createCheckoutSession()` rejects requests that do not carry `ord_agb=1`
(unit-tested), and the Stripe order button is disabled in the rendered DOM
until the customer ticks `#checkAgbTop` (achieved by the Stimulus
controller wiring already present in the build).

## 0. Edit boundary (HARD)

Changes are confined to **`source/extensions/stripe/`** (and, only if
strictly required, `source/extensions/payment-component/`). The following
paths are **read-only context** for this sprint and must NOT be modified:

- `source/source/**` — OXID core (apex theme, OrderController, etc.).
  Files like `source/source/Application/views/apex/tpl/page/checkout/inc/agb.html.twig`
  and `source/source/Application/Controller/OrderController.php` are cited
  in the report only to identify the markup IDs (`#checkAgbTop`, input
  name `ord_agb`) and the canonical access path for `blConfirmAGB`. We do
  not patch them.
- `source/vendor/**` — third-party / OXID dependencies.

Every production-side step in §6 lives under `source/extensions/stripe/`.
If at any point a test demands an edit outside this directory, stop and
escalate. This is a strict hold-the-line constraint per direct user
instruction.

## 1. Why

Report 01 documents that with the OXID core admin setting "Users have to
Confirm General Terms and Conditions during Check-Out" (`blConfirmAGB`)
turned **on**, the Stripe checkout button bypasses the AGB checkbox. The
customer can pay through Stripe without the system having any record that
they accepted the Terms — a legal/compliance defect.

The fix is two complementary layers: the **controller** is the
authoritative gate; the **frontend** is the UX feedback. Without the
controller gate the protection is bypassable; without the frontend the
customer sees a confusing 4xx after a click. Both are in scope.

## 2. Out of scope

- Editing **any** file under `source/source/` or `source/vendor/` — see §0. The AGB checkbox markup (`#checkAgbTop` / `ord_agb`) lives in apex core; we treat it as a **read-only contract** that our module-internal code consumes.
- Introducing a new admin setting. We honour the existing `blConfirmAGB`.
- Auto-checking or hiding the AGB checkbox under any circumstance.
- Validation of `oxdownloadableproductsagreement` / `oxserviceproductsagreement`. Same root cause but a separate sprint — `blConfirmAGB` is the headline issue and the others are gated on different basket conditions.
- Playwright e2e regression. Covered by unit + integration tests for now; e2e to follow once auth flakiness in the e2e env is resolved (referenced in earlier reports).
- Any change to the Payment Element flow (`executeStripePayment`) — sprint 101 is scoped to the Checkout Session entry point used by `#stripe-checkout-btn`. If the Payment Element button has the same gap, it gets its own sprint after this one lands.

## 3. Risks & unknowns

- **R1 — Twig override coupling:** the order template extension lives in `views/twig/extensions/themes/default/page/checkout/order.html.twig`. Wiring `data-controller="agb-validation"` and `data-agb-validation-target="submitButton"` on `#stripe-checkout-btn` must not break the existing OPC widget controller (`stripe-checkout-footer`) registered on a different element. Mitigated by the fact that Stimulus controllers compose freely on overlapping subtrees.
- **R2 — Detecting `blConfirmAGB`:** we read it via `Registry::getConfig()->getConfigParam('blConfirmAGB')`, the same path `OrderController::isConfirmAGBActive()` uses. No new abstraction is required.
- **R3 — Existing `ControllerRequestHelper` test coverage:** the helper already has its own tests; adding a new reader method is mechanical. Beyond mocking risk only if we don't expose the method on a seam — handled below by reading via the helper, not via static `Registry::getRequest()` inside the controller.
- **R4 — DOM coupling to apex's `#checkAgbTop`:** because the edit boundary (§0) forbids touching apex's `agb.html.twig`, the Stimulus controller resolves the checkbox by its stable apex ID rather than a `data-agb-validation-target`. Mitigation: that ID has been stable across OXID 6 and 7 and is the same ID OXID's own `agb.js` consumes. The risk is bounded — if apex ever renames it, our controller silently leaves the button enabled (the controller's null-guard keeps the page functional). The **backend gate** (§6.2) still rejects the bad request, so the worst-case outcome of a future apex rename is a UX regression, not a compliance failure.

## 4. Goals — what tests must turn green

This sprint adds **2** new test files and **extends 1** existing test file.
Production code changes are listed in §6 and constrained to four files.

```
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration
```

If a test cannot reach the assertion without an additional production
change, the sprint has uncovered a real defect — escalate as a separate
finding, do **not** silently shape the test to mask the bug.

### 4.1 Test file 1 — `Unit/Stripe/Controller/StripeOrderControllerAgbConfirmationTest.php`

Covers the controller gate end-to-end at unit scope, mirroring the
established testable-subclass pattern from
`StripeOrderControllerRetryTest.php`. Mocks the request helper and the
event dispatcher.

| # | Behaviour | Expected |
|---|---|---|
| T1 | `blConfirmAGB = true`; request `ord_agb` missing; `createCheckoutSession()` invoked | HTTP 400; JSON body `{"error": "<i18n key for AGB required>"}`; `StripeCheckoutSessionRequestEvent` is **NOT** dispatched; `RetryCleanupService` is **NOT** invoked |
| T2 | `blConfirmAGB = true`; request `ord_agb = "0"` | same as T1 |
| T3 | `blConfirmAGB = true`; request `ord_agb = "1"` | dispatcher receives exactly one `StripeCheckoutSessionRequestEvent`; HTTP 200; JSON body has `id` / `url` / `contract_id` |
| T4 | `blConfirmAGB = false`; request `ord_agb` missing | dispatcher receives the event; HTTP 200 — the gate is bypassed when the admin setting is off |
| T5 | `blConfirmAGB = true`; `ord_agb = "1"`; basket empty | the AGB check passes but the existing "Basket is empty" 500 error wins. AGB check must run **after** session-challenge validation but **before** any cleanup or basket logic — order matters because we don't want to start the contract-cleanup side effects on a request that's going to be rejected anyway. Asserts the new code is positioned correctly. |
| T6 | `blConfirmAGB = true`; `ord_agb` missing; session challenge invalid | the existing 403 ("Session expired") wins. AGB check is **after** session validation. Asserts ordering vs T5. |

T1, T2, T3 carry the user's contract: with the admin setting on, the
request must include `ord_agb=1`. T4 proves we don't break installations
where the admin opted out of AGB confirmation. T5 and T6 lock in the
ordering of guards so a future refactor doesn't accidentally make the AGB
check leak side effects (cleanup) before validation completes.

### 4.2 Test file 2 — `Unit/Stripe/Controller/ControllerRequestHelperAgbReaderTest.php`

A focused reader test on `ControllerRequestHelper`, isolated from the
controller. We only assert the boundary — not the controller's interpretation
of the boundary.

| # | Behaviour | Expected |
|---|---|---|
| H1 | `getAgbAcceptedFromRequest()` with `Registry::getRequest()` returning empty params | returns `false` |
| H2 | request has `ord_agb=""` | returns `false` |
| H3 | request has `ord_agb="0"` | returns `false` |
| H4 | request has `ord_agb="1"` | returns `true` |
| H5 | request has `ord_agb="true"` | returns `false` — strict; we accept only `"1"`, matching what apex `agb.js` writes (`item.value = this.checked ? '1' : '0'`) |

Reading via the helper is the standard pattern for the controller (cf.
sprint 71 / 88 — `ControllerRequestHelper` exists precisely to keep static
`Registry::getRequest()` calls out of the controller). Tests use
`Registry::set()` to seed a fake request, the same way other helper tests
do today.

### 4.3 Test file 3 (extends existing) — `Unit/Stripe/Controller/StripeOrderControllerRetryTest.php`

The existing retry test must keep proving its current invariants. We add
**one** assertion to T (existing) "session challenge passes; create session"
test variant: explicitly seed `ord_agb=1` so the test's intent stays
sharp, and add a regression assertion that `getAgbAcceptedFromRequest()`
was consulted (via the helper). No behaviour change to existing tests.

### 4.4 Template wiring (no new test file — covered by visual regression at §7.4)

Order template gains:

```twig
<div data-controller="agb-validation"
     data-agb-validation-enabled-value="{{ oView.isConfirmAGBActive() ? 'true' : 'false' }}">
    {# existing content of block checkout_order_next_step_side #}
    <button id="stripe-checkout-btn"
            ...
            data-agb-validation-target="submitButton">
```

and on the AGB block override (a new file, see §6.4):

```twig
<input id="checkAgbTop" ... data-agb-validation-target="checkbox"
       data-action="change->agb-validation#checkboxChanged">
```

Why no PHP test: the template is a pure render that Stimulus consumes at
runtime, and we already have a Playwright fixture path that exercises the
button. Asserting the data-attributes via a Twig render test buys little
over visual regression.

## 5. Design — SOLID, Clean Code, Liskov, ISP, DRY

### 5.1 SRP

Each new piece does one thing:
- `ControllerRequestHelper::getAgbAcceptedFromRequest(): bool` — read one bit out of the request.
- `ControllerRequestHelper::isAgbConfirmationRequired(): bool` — read one bit out of `Registry::getConfig()`. Already analogous to `getCaptureMode()` / `getActiveLanguageId()` on the same class.
- `StripeOrderController::ensureAgbAccepted(ControllerRequestHelper $helper): bool` — the *guard* method. Returns false if the request fails the gate; controller returns immediately. Responsible only for "should I keep going". 10-15 lines.
- `agb-validation` Stimulus controller — already exists; SRP unchanged.

### 5.2 DIP

The controller continues to depend on `ControllerRequestHelper` (already
the seam used in `StripeOrderControllerRetryTest`). No new abstraction
introduced. **Do not** introduce an `AgbConfirmationCheckerInterface` —
no second consumer exists. (No-overengineering rule: "What concrete,
present requirement forces this to exist?" None.)

### 5.3 LSP

`StripeOrderController extends OrderController`. The base
`OrderController::execute()` enforces AGB through OXID's own path. Our
`createCheckoutSession()` is a sibling entry point; we are **adding** a
guard to that sibling, not weakening the inherited contract. Subclass
behaviour remains a superset of the parent's (guarded paths still go
through the parent's implicit invariants where they apply).

### 5.4 ISP

No new interface. `ControllerRequestHelper` already has 20+ public methods
serving the controller and its tests; adding 2 readers does not push it
past the project's documented ISP threshold for OXID-style helpers.

### 5.5 DRY

- Reuse `Registry::getConfig()->getConfigParam('blConfirmAGB')` — same access pattern OXID's `OrderController::isConfirmAGBActive()` uses.
- Reuse the JSON error path already in `createCheckoutSession()` (HTTP 4xx + `echo json_encode`) — do not introduce a second response-writing helper.
- The Stimulus `agb-validation` controller is already built and registered. Wire it up rather than write a new one.
- No new translation keys: reuse the existing OXID i18n key `READ_AND_CONFIRM_TERMS` (or the closest equivalent). The error message is short and well-known to OXID admins.

### 5.6 Clean Code

- `ensureAgbAccepted()` returns `bool` — early return in `createCheckoutSession()`. No `else`.
- All new methods named as verbs (`ensureAgbAccepted`, `getAgbAcceptedFromRequest`, `isAgbConfirmationRequired`).
- No magic numbers; the request key `'ord_agb'` and accept value `'1'` are class constants on `ControllerRequestHelper`.
- AAA layout in tests; one behaviour per test method.
- Mocks of interfaces only (per memory `feedback_oxid_dao_mocking.md`). For `ControllerRequestHelper` we use the `StubControllerRequestHelper` already present in tests.

### 5.7 No overengineering

- No interface for the new check.
- No event for the new check (we're not building an extension point — there's no second consumer).
- No new admin setting. We honour the existing OXID one.
- No client-side validation library; the existing `agb_validation_controller.js` is enough.

## 6. Production changes — exactly three files (all under `extensions/stripe/`)

### 6.1 `src/Stripe/Controller/ControllerRequestHelper.php`

Add two readers and two constants:

```php
public const AGB_REQUEST_KEY = 'ord_agb';
public const AGB_ACCEPTED_VALUE = '1';

public function getAgbAcceptedFromRequest(): bool
{
    $raw = Registry::getRequest()->getRequestEscapedParameter(self::AGB_REQUEST_KEY);
    return is_string($raw) && $raw === self::AGB_ACCEPTED_VALUE;
}

public function isAgbConfirmationRequired(): bool
{
    return (bool) Registry::getConfig()->getConfigParam('blConfirmAGB');
}
```

Both methods are protected against PHPMD via boring naming and stay under
10 lines.

### 6.2 `src/Stripe/Controller/StripeOrderController.php`

Insert the guard inside `createCheckoutSession()`, **after**
`validateSessionChallenge()` and **before**
`cleanupPreviousCheckoutAttempt()`:

```php
if (!$this->ensureAgbAccepted($helper)) {
    return;
}
```

with the new private method:

```php
private function ensureAgbAccepted(ControllerRequestHelper $helper): bool
{
    if (!$helper->isAgbConfirmationRequired()) {
        return true;
    }
    if ($helper->getAgbAcceptedFromRequest()) {
        return true;
    }

    http_response_code(400);
    echo json_encode(['error' => 'You must accept the Terms and Conditions to continue.']);
    $this->exitWithJson();
    return false;
}
```

`exitWithJson()` is already overridable (see lines 464-467) — tests
substitute it the way `StripeOrderControllerRetryTest` already does.
Position is dictated by §4.1 T5 / T6.

### 6.3 `extensions/stripe/views/twig/extensions/themes/default/page/checkout/order.html.twig`

Wrap the existing `block checkout_order_next_step_side` Stripe branch with
the Stimulus container. The container is rendered **only** by our module's
override; the apex `agb.html.twig` partial is reused **unchanged** from
core via the parent's render — we do not need to add Stimulus attributes
to the checkbox markup itself, because the controller can find it in the
DOM. New shape:

```twig
{% block checkout_order_next_step_side %}
    {% if payment.isStripePaymentMethod() == false %}
        {{ parent() }}
    {% else %}
        <div data-controller="agb-validation"
             data-agb-validation-enabled-value="{{ oView.isConfirmAGBActive() ? 'true' : 'false' }}">
            {# existing button markup #}
            <button id="stripe-checkout-btn"
                    ...
                    data-agb-validation-target="submitButton">
                ...
            </button>
        </div>
    {% endif %}
{% endblock %}
```

### 6.4 `extensions/stripe/resources/build/js/controllers/agb_validation_controller.js` — locate the core checkbox in DOM

Because we cannot edit apex's `agb.html.twig` to attach
`data-agb-validation-target="checkbox"` to `#checkAgbTop`, the Stimulus
controller resolves the checkbox by its well-known ID in `connect()`:

```js
connect() {
    // The AGB checkbox lives in the apex theme partial we cannot modify;
    // resolve it by the stable apex ID and attach a change listener.
    this._coreCheckbox = document.getElementById('checkAgbTop');
    if (this._coreCheckbox) {
        this._coreCheckbox.addEventListener('change', () => this.checkboxChanged());
    }
    if (this.enabledValue) {
        this.updateButtonStates();
    }
}
```

with corresponding adjustments to `updateButtonStates()` so the
"checked" state reads from `this._coreCheckbox.checked` (with a null
guard: if the checkbox isn't on the page — e.g. `blConfirmAGB` is off and
apex did not render it — the controller leaves the buttons enabled, which
is the correct outcome for that path).

This keeps **every** edited file under `source/extensions/stripe/` and
removes the original §6.4 dependency on a Twig theme override that would
have to mirror an apex partial we are not allowed to edit. The trade-off:
the controller now has a small awareness of the apex DOM contract
(`#checkAgbTop`). That ID has been stable across OXID 6 and 7 and is the
same ID OXID's own `agb.js` consumes — coupling risk is low and is
recorded in §3 R4.

## 7. Acceptance criteria

The sprint is done when, with the four production changes above and no
others, the following all pass clean.

### 7.1 Pre-commit

```
./bin/pre-commit-check.sh --full
```

run from `extensions/stripe`. Includes PHPCS, PHPStan (level max),
PHPMD (no new findings, baseline unchanged), Unit + Integration test
suites.

### 7.2 Test count baseline

Delta vs `MEMORY.md` "Test Baseline (as of Sprint 61)":

- Unit: **+ 11** tests (T1…T6, H1…H5).
- No new integration tests.
- Total assertion delta: **+ ~22** (each test asserts dispatcher invocation count + HTTP code + JSON body, or single boolean).

Unit test count after sprint: `1707 + 11 = 1718` (subject to any tests added in
sprints 96-100 that land before this one — verify with the actual baseline at
sprint start).

### 7.3 Static analysis

| Tool | Required state |
|---|---|
| PHPCS | 0 errors |
| PHPStan (level max) | 0 new errors |
| PHPMD | 0 new findings; `tests/PhpMd/phpmd.baseline.xml` unchanged |

If PHPMD reports complexity growth on `StripeOrderController`, **do not**
amend the baseline. Extract the guard into a helper class if necessary
(memory rule: "PHPMD thresholds must be reasonable — don't raise limits to
hide problems").

### 7.4 Manual smoke (operator)

1. Activate the module, set `blConfirmAGB = on` in admin Core Settings.
2. `bin/oe-console oe:cache:clear` and `rm -rf source/tmp/*`.
3. Reach the Stripe order page. Verify `#stripe-checkout-btn` is rendered with `disabled` attribute (Stimulus added it on connect because checkbox is unchecked).
4. Click `#stripe-checkout-btn` programmatically via dev-tools (`document.getElementById('stripe-checkout-btn').click()`); the request still hits the controller — verify the controller returns HTTP 400 with the error JSON.
5. Tick `#checkAgbTop`; the button enables; clicking proceeds to Stripe Checkout normally.
6. With `blConfirmAGB = off`: button is enabled regardless of checkbox; checkout proceeds without the gate (T4 semantics).

## 8. Allowed production changes (only three, all under `extensions/stripe/`)

The three files in §6.1, §6.2, §6.3 plus the JS controller adjustment in
§6.4. **No file outside `source/extensions/stripe/` may be touched** (§0).
If a test requires more, **escalate as a separate finding** — do not
bundle scope.

## 9. Out of scope / follow-ups

- AGB enforcement on the Payment Element entry point (`executeStripePayment`). Same root cause; separate sprint. Punted because the Payment Element flow has additional state machinery (`stripeReturn`, 3DS) that needs its own consideration.
- AGB enforcement on `oxdownloadableproductsagreement` and `oxserviceproductsagreement`. Same architectural slot, but these are conditioned on basket contents and deserve a dedicated sprint with their own report.
- Playwright e2e regression. Add once the e2e auth flow is stable.
- Migrating the Stimulus `agb-validation` controller from `resources/build/js/controllers/` to a directly-bundled controller wired at module-activation time. Today it's already in `app.js`; we don't touch the build pipeline.

## 10. TDD walking order

1. **Helper readers first.** Add §4.2 H1…H5 to a new test file. Run — red. Add §6.1 production code. Run — green. Commit.
2. **Controller gate next.** Add §4.1 T1, T2 (negative paths) to a new test file. Run — red. Add §6.2 production code with the guard between session-challenge and cleanup. Run — green. Commit.
3. **Positive path.** Add §4.1 T3 (positive case). Run — green out of the box (helper returns true → guard returns true → existing dispatch path). Verify the existing positive integration in `StripeOrderControllerRetryTest` still passes and add the assertion in §4.3. Commit.
4. **Off-flag path.** Add §4.1 T4. Run — green. Commit.
5. **Ordering invariants.** Add §4.1 T5, T6. Run — green if §6.2 placed the guard correctly; red if anyone moved it. Commit.
6. **Frontend wiring.** Add §6.3 + §6.4. Manual smoke per §7.4. No new PHP tests. Commit.
7. Run `./bin/pre-commit-check.sh --full`. Move sprint to `done/` with completion report alongside it. Update `status.md` with the new test count baseline.

## 11. Done definition (checklist)

- [ ] §6.1 — `ControllerRequestHelper` readers + constants landed (file under `extensions/stripe/`)
- [ ] §6.2 — `StripeOrderController` guard landed (file under `extensions/stripe/`)
- [ ] §6.3 — Order template Stimulus container landed (file under `extensions/stripe/`)
- [ ] §6.4 — `agb_validation_controller.js` resolves `#checkAgbTop` in `connect()` (file under `extensions/stripe/`)
- [ ] **No edits anywhere outside `source/extensions/stripe/`** — verified via `git status`
- [ ] T1…T6 green
- [ ] H1…H5 green
- [ ] §4.3 existing-test extension green; no other existing tests changed
- [ ] PHPCS / PHPStan (level max) / PHPMD: 0 new findings; baseline unchanged
- [ ] Manual smoke §7.4 walked through and recorded in completion report
- [ ] Sprint moved to `done/` with `sprint-101-completion-report.md` alongside it
- [ ] `status.md` updated with the new test count baseline