# Sprint 128 — Order-now button disabled after returning from the external payment page (fresh-load + AGB on)

> The customer accepts Terms, clicks **Order now**, is sent to the external (Stripe-hosted)
> payment page, then returns to the shop on checkout step 3 (`cl=order`). The **Order now**
> button is disabled and they cannot place the order.
>
> Sprints 122/123 already fixed the **bfcache / browser-Back** variant of this. This ticket
> is the **remaining, uncovered path**: a *fresh page load* return (Stripe redirect-back,
> cancel link, mobile Safari that skips bfcache) with **`blConfirmAGB` ON**. On that render
> the apex AGB checkbox (`#checkAgbTop`) comes back **unchecked**, so
> `agb_validation_controller.updateButtonStates()` disables the button. Today the suite
> *treats that as correct* (sprint-122 spec Case C asserts `disabled === !checked`).
>
> **Product decision (2026-06-22, confirmed by Daniil): restore prior consent.** The
> customer's earlier AGB acceptance — already captured server-side as `ord_agb=1` when the
> checkout session was created — is persisted for the checkout lifetime and re-applied on
> return, so `#checkAgbTop` renders checked and the button is enabled. We restore the UI to
> reflect consent that was genuinely given; we do not fabricate new consent.

**Repo:** `extensions/stripe` · **Branch:** `b-7.4.x`
**Ticket:** STRP-XXX (TBD) · **Type:** bug fix
**Mode:** TDD-first, **reproduce-before-fix**. Multi-commit (A → B → C), each RED → GREEN → REFACTOR.
**Binding every commit:** TDD · SOLID · DRY · Clean Code · No overengineering · PSR-12 · PHPStan max.

---

## 1. Bug report (as filed)

**Preconditions**
- OXID eShop storefront accessible.
- An external payment method is available (here: **Stripe Wallet**, the hosted/redirect method).
- Customer has products in the cart and is on checkout step 3 (`cl=order`).

**Steps to reproduce**
1. Go to checkout.
2. Select the external payment method.
3. Continue to the external payment page.
4. Return from the external payment page to the shop.
5. Check the **Order now** button.

**Actual:** The **Order now** button is disabled after returning to the shop.
**Expected:** The **Order now** button should be enabled after returning, so the customer can place the order.

> Screenshot on file: `Screenshot 2026-05-12 at 12.23.47 PM.png` (button greyed out on `cl=order`).

---

## 2. Why this is NOT already fixed (relationship to sprints 122/123)

Sprints 122/123 wired a submit-lifecycle event seam:

- `order_submit_controller.js` — `pageshow(persisted)` → `hideLoading()` → dispatch `oe:stripe:submit-end`.
- `agb_validation_controller.js` — listens for `oe:stripe:submit-end` → `unlockCheckbox()` + `updateButtonStates()`.

That seam fires **only on a bfcache restore** (`pageshow.persisted === true`). It does not fire on a
**fresh page load** return. On a fresh load:

- `order_submit_controller.connect()` runs but `hideLoading()` is *not* called (`persisted === false`).
- `agb_validation_controller.connect()` runs `updateButtonStates()`, which reads the freshly
  rendered `#checkAgbTop` (**unchecked**) and sets `button.disabled = true`
  (`agb_validation_controller.js:130-134`).

Existing coverage that this sprint must keep green and re-interpret:

| Spec | Path covered | After this sprint |
|------|--------------|-------------------|
| `tests/.../checkout/order-button-enabled-after-back.spec.ts` Case A/B | bfcache restore + AGB ticked → enabled | unchanged, stays green |
| same, **Case C** | fresh-load `goto` **without submitting** (no `ord_agb` persisted) → button disabled = correct | **stays green** — Case C never persists consent, so restoration does not trigger |

> The fresh-load + AGB-on + **after-submit** cell is the one nobody tested. That is the bug.

### Decision matrix (the cell we are fixing)

| AGB | Return mechanism | Submitted before leaving? | Button today | After fix |
|-----|------------------|---------------------------|--------------|-----------|
| off | bfcache | yes | enabled (sprint 122) | enabled |
| off | fresh load | yes | enabled (template) | enabled |
| on  | bfcache | yes | enabled (sprint 122) | enabled |
| **on** | **fresh load** | **yes** | **DISABLED ← bug** | **enabled (restore consent)** |
| on  | fresh load | no (never consented) | disabled (correct) | disabled (correct) |

---

## 3. Root cause (one sentence)

On a fresh-load return to `cl=order` the apex `#checkAgbTop` re-renders unchecked, so
`agb_validation_controller` disables the Order-now button — even though the customer already
accepted Terms (captured as `ord_agb=1` at `createCheckoutSession`), because that consent is
never persisted past the redirect.

---

## 4. Fix design (consent-restore)

Three coordinated, minimal changes. Consent is persisted server-side, surfaced to the
module-owned template, and re-applied to the apex checkbox client-side.

### 4.1 Persist consent on submit — `ControllerRequestHelper` + `StripeOrderController`
All session access in this controller goes through `ControllerRequestHelper` (the controller has **no**
`getSession()`). Add three methods + a constant to the helper (`ControllerRequestHelper.php`):

```php
public const AGB_CONSENT_SESSION_KEY = 'stripe_agb_confirmed';

public function persistAgbConsent(): void
{
    $this->setSessionVariable(self::AGB_CONSENT_SESSION_KEY, true);
}

public function hasPersistedAgbConsent(): bool
{
    return (bool) Registry::getSession()->getVariable(self::AGB_CONSENT_SESSION_KEY);
}

public function clearAgbConsent(): void
{
    $this->deleteSessionVariable(self::AGB_CONSENT_SESSION_KEY);
}
```

`createCheckoutSession()` (`StripeOrderController.php:166`) already gates consent via
`ensureAgbAccepted(ControllerRequestHelper $helper)` (`:299`), which reads
`$helper->getAgbAcceptedFromRequest()` (`AGB_REQUEST_KEY = 'ord_agb'`, appended by
`order_submit_controller.appendAgbState()`). Persist at the point acceptance is confirmed — single
source of truth, no duplicate request read:

```php
// ensureAgbAccepted(): accepted branch
if ($helper->getAgbAcceptedFromRequest()) {
    $helper->persistAgbConsent();
    return true;
}
```

When `isAgbConfirmationRequired()` is false (AGB off) the flag is never set, which is correct — the
button is not AGB-gated in that config.

> **Why a separate key (not the `clearStripeSessionVariables()` set):** the reported repro is a
> browser-Back **fresh load** of `cl=order`. That render runs `cleanupStaleCheckoutOnRender()` →
> `clearStripeSessionVariables()`, which wipes `stripe_contract_id` etc. The consent flag is
> deliberately **outside** that set, so it survives the render and the button can be restored. No
> capture-before-cleanup gymnastics needed — the getter reads the live session directly.

### 4.2 Surface consent to the template — `StripeOrderController::isPriorAgbConsent()`
New small public getter (≤ 3 lines), consumed by `oView` in the button block exactly like the existing
`oView.isConfirmAGBActive()`:

```php
public function isPriorAgbConsent(): bool
{
    return $this->getRequestHelper()->hasPersistedAgbConsent();
}
```

> Because the consent key is outside `clearStripeSessionVariables()` (§4.1), it is still present after
> `cleanupStaleCheckoutOnRender()` runs in `render()` — the getter can read the live session directly,
> no controller-property capture needed.

### 4.3 Template — `order.html.twig` button block (module-owned, editable)
Add a Stimulus value on the **module-owned** `agb-validation` wrapper div (NOT on the apex
checkbox — see §0 boundary):

```twig
<div data-controller="agb-validation"
     data-agb-validation-enabled-value="{{ oView.isConfirmAGBActive() ? 'true' : 'false' }}"
     data-agb-validation-prior-consent-value="{{ oView.isPriorAgbConsent() ? 'true' : 'false' }}">
```

### 4.4 JS — `agb_validation_controller.js`
Add `priorConsent: Boolean` to `static values`. In `connect()`, before the existing
`updateButtonStates()` call, re-apply consent **once** when it is missing from the freshly
rendered checkbox:

```js
// Sprint 128: restore consent given pre-redirect so the button is enabled on return.
if (this.enabledValue && this.priorConsentValue
    && this._coreCheckbox && !this._coreCheckbox.checked) {
  this._coreCheckbox.checked = true
}
if (this.enabledValue) {
  this.updateButtonStates()
}
```

This re-checks `#checkAgbTop` (JS manipulation of that element is already in-bounds — the
controller locks/unlocks it today) and lets the existing `updateButtonStates()` enable the button.

### 4.5 Consent lifecycle / clearing
- **Set:** `ensureAgbAccepted()` → `persistAgbConsent()` when `ord_agb=1` (§4.1).
- **Survives:** `clearStripeSessionVariables()` / `cleanupStaleCheckoutOnRender()` — by design (§4.1),
  so the browser-Back fresh-load render restores the button.
- **Clear:** in `checkoutSuccess()` (order placed, alongside `clearStripeSessionVariables()`) **and** in
  `checkoutCancel()` (customer explicitly cancelled on the Stripe page — the attempt is over, re-consent
  on a new attempt). Use `$helper->clearAgbConsent()`.

> Scope guard: a genuinely fresh checkout that never submitted has no flag → checkbox unchecked →
> button disabled (unchanged, correct — the "never consented" matrix row).
>
> **Accepted residual edge:** if a customer leaves via browser-Back (not the cancel link), abandons
> without completing, and then — *within the same session* — builds a brand-new basket and reaches
> `cl=order`, the flag would still be set and the box pre-ticked. This is the narrow cost of the
> "restore prior consent" decision (Daniil, 2026-06-22). It is bounded by clearing on success and on
> explicit cancel; widening the clear to "basket changed" is deferred (no overengineering) unless legal
> requires it.

---

## 5. Edit boundaries (§0)

- **`#checkAgbTop` apex partial is OFF-LIMITS** — do not add `checked`/attributes there. Restoration
  happens via the module-owned wrapper flag (§4.3) + JS re-check (§4.4). This preserves the Sprint 101
  boundary that the controller resolves the checkbox by stable ID.
- **`payment-base` / `payment-component`:** no changes expected. If touched, additive-only (see memory
  `feedback_payment_base_additive_only`).
- Build artifacts: edit JS under `resources/build/js/controllers/`, then `npm run build` (esbuild →
  `assets/js`). Never hand-edit `assets/js`.

---

## 6. TDD plan — RED first, per commit

No JS unit harness exists in this module (package.json has esbuild only). JS behavior is covered by
Playwright E2E, consistent with sprints 122/123. PHP behavior is covered by PHPUnit using the
testable-subclass pattern.

### Commit A — PHP unit (RED → GREEN): consent persist + surface
`tests/Unit/Stripe/Controller/StripeOrderControllerAgbConsentTest.php`

1. `testCreateCheckoutSessionPersistsAgbConsentWhenOrdAgbTruthy()` — request `ord_agb=1` ⇒ session
   flag set true.
2. `testCreateCheckoutSessionDoesNotPersistConsentWhenOrdAgbAbsent()` ⇒ flag stays false/null.
3. `testIsPriorAgbConsentReflectsSessionFlag()` — true when set, false when unset.
4. **`testConsentSurvivesClearStripeSessionVariables()`** — the survival guard: set the flag, call
   `clearStripeSessionVariables()`, assert `hasPersistedAgbConsent()` is still true (proves the key is
   deliberately outside the cleared set, §4.1). This is the regression sentinel for the whole fix.
5. `testCheckoutSuccessClearsConsent()` and `testCheckoutCancelClearsConsent()` — both terminal paths
   clear the flag (§4.5).

Use the **testable subclass** pattern (skip OXID admin/bootstrap; inject a fake session). Mock the
session as an interface, not a concrete (memory: "Mock interfaces not concrete classes").

### Commit B — JS + template (GREEN via E2E): consent restore
`tests/e2e/.../checkout/order-button-enabled-after-external-return.spec.ts`

Headline reproduction (mirror, and **invert the gate of**, sprint-122's instrumentation):

- Inject the `pageshow` instrument (`window.__pageshowPersisted`).
- Navigate to `cl=order` with Stripe Wallet, **tick AGB**, assert enabled (precondition).
- Click **Order now**, wait for redirect to `checkout.stripe.com`.
- Return via a **fresh load** of the order URL (`page.goto(orderUrl)`), NOT `goBack()`, to force the
  non-bfcache path.
- **GATE:** assert `__pageshowPersisted === false` (prove we exercised the fresh-load path, not
  bfcache — otherwise this duplicates sprint 122 and silently "passes on the wrong path").
- **PRIMARY (RED on HEAD):** `#checkAgbTop` is **checked** AND `#stripe-checkout-btn` is **enabled**.
- **Unstuck proof:** clicking the button issues a fresh `createCheckoutSession` request.
- **Negative control:** a separate fresh checkout that never submitted ⇒ checkbox unchecked, button
  disabled (consent not fabricated).

### Commit C — REFACTOR + regression
- Run the full sprint-122 spec (`order-button-enabled-after-back.spec.ts`) — Cases A/B/C must stay
  green (Case C never submits, so consent restoration does not trigger).
- Run `agb-checkbox-locked-during-submit.spec.ts` and `stripe-agb-confirmation.spec.ts` — green.
- Tidy: extract any shared navigation helper; confirm method sizes 15–25 lines; no `else`.

---

## 7. Verification gates (must all pass before commit)

```bash
# PHP unit (new + full module)
docker compose exec -w /var/www/extensions/stripe -T php \
  php vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit

# Style / static analysis
cd source/extensions/stripe && ./bin/pre-commit-check.sh --full
#  PHPCS 0 · PHPStan 0 (level max) · PHPMD 0 new

# Build JS after editing controllers
npm run build

# E2E (from tests/e2e/playwright/playwright)
npx playwright test tests/checkout/order-button-enabled-after-external-return.spec.ts
npx playwright test tests/checkout/order-button-enabled-after-back.spec.ts   # regression
```

> E2E env caveat (memory `project_playwright_e2e_env_and_findings`): the Stripe redirect can flake on
> remote latency, and bfcache vs fresh-load is environment-dependent. The `__pageshowPersisted === false`
> gate makes a wrong-path run **fail loudly** rather than green-wash. If the environment cannot produce a
> Stripe redirect, the test `skip`s with a descriptive reason (do not silently pass — memory
> `feedback_integration_silent_skips_lie`).

---

## 8. DONE criteria

- [ ] On a fresh-load return from the external payment page with `blConfirmAGB` ON and a prior submit,
      `#checkAgbTop` is checked and **Order now** is enabled; the customer can place the order.
- [ ] A fresh checkout that never submitted leaves the checkbox unchecked / button disabled (no
      fabricated consent).
- [ ] bfcache path (sprints 122/123) and AGB-off path unchanged.
- [ ] New PHP unit tests + E2E spec committed; all green. Sprint-122/123 specs still green.
- [ ] PHPCS/PHPStan(max)/PHPMD clean; no new suppressions; apex `#checkAgbTop` untouched.
- [ ] Consent cleared on order completion; not cleared by the stale-cleanup render.

---

## 9. Risks & notes

- **Legal/audit:** restoring consent re-applies acceptance the customer genuinely gave pre-redirect
  (recorded as `ord_agb=1` and on the contract). Note this explicitly in the commit message; the
  customer can still untick. Decision owner: Daniil, 2026-06-22.
- **Survival invariant (highest risk):** the fix depends on the consent key being **outside**
  `clearStripeSessionVariables()` so it survives `cleanupStaleCheckoutOnRender()` on the back-to-order
  render. If a future change adds it to that set, the button breaks again. Commit A test #4 is the
  sentinel guarding this exact invariant.
- **Scope creep:** do not generalize to a "remember all checkout form state" feature. Persist only the
  single AGB-consent flag for the active checkout. (No overengineering.)
- **`cl=order` controller identity (confirmed):** `StripeOrderController` is the **`OrderController`
  class extension** — `metadata.php:57` maps `OrderController::class => StripeOrderController::class`
  (it is NOT a standalone `controllers` entry), and it `extends StripeOrderController_parent`. So on
  `cl=order` the rendered `oView` is this class, and `fnc=createCheckoutSession` dispatches to its
  `createCheckoutSession()`. `isPriorAgbConsent()` and the `render()` capture-before-cleanup both go
  here. Because it is a virtual-parent extension, annotate `oxNew`/parent calls per the project
  PHPStan convention (OXID-core suppression only).
