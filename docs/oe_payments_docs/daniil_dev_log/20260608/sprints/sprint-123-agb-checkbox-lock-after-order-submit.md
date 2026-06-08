# Sprint 123 — Terms-and-Conditions checkbox can be unchecked after clicking Order now

**Date planned:** 2026-06-08
**Ticket:** (bug) "Terms and Conditions checkbox can be unchecked after clicking Order now"
**Branch:** feature branch off `b-7.4.x` (suggested: `b-7.4.x-fix-agb-lock-on-submit`) — lowest applicable branch, merge up.
**Repos touched:** `extensions/stripe` ONLY — zero payment-base changes, zero one-page-checkout changes, zero apex edits.
**Predecessor:** [`sprint-122-order-button-stuck-disabled-after-return.md`](sprint-122-order-button-stuck-disabled-after-return.md). **Do 122 first** — it introduces the `oe:stripe:submit-end` seam and makes `agb-validation` the authority on the resting button state. This sprint adds the mirror `oe:stripe:submit-start` (lock) and reuses `submit-end` (unlock) on the same seam.

---

## 0. Value & cost — read before scheduling

Be honest about what this fixes. **The consent that is submitted is already correct without this change:**

- `appendAgbState()` (`order_submit_controller.js:277-284`, verified) reads `checkbox.checked` and appends `ord_agb=1` **at the moment the fetch URL is built** — i.e. synchronously inside the click handler, *before* any post-click unticking can matter.
- The real consent enforcement is **server-side** in `StripeOrderController::createCheckoutSession()`.
- A disabled checkbox does **not** clear `checked`, so even the eventual native form value is unaffected.

So unticking the box during the in-flight window has **no functional or data effect**. This is a **UI-integrity / perceived-trust** fix only: it stops the box visibly contradicting a submission already in progress. That is a legitimate but **low-severity** goal. The cost is deliberately kept minimal (two one-line methods + reuse of 122's event seam, no new lifecycle). **If the backlog is contended, this can wait** — flag it to the product owner rather than treating it as a must-ship. We do not expand scope to "justify" the ticket.

## 1. Why

Standard checkout, step 3 (`cl=order`), Stripe payment, `blConfirmAGB` active. Repro:

1. Order page; AGB checkbox (`#checkAgbTop`) ticked; Order-now button enabled.
2. Click **Order now**.
3. Try to **untick** the AGB checkbox during the session-creation round-trip.
4. **Actual:** the checkbox can still be toggled off *after* submission has begun, so the displayed consent visibly contradicts the in-flight submit.

### Root cause (verified)

Clicking Order now disables only the **button**, never the **checkbox**:

```
order_submit_controller.js:289-293  showLoading()  → this.element.disabled = true   (button only)
agb_validation_controller.js:36-46  connect()      → #checkAgbTop change → updateButtonStates()
```

The AGB checkbox is a plain apex-theme element (`#checkAgbTop`) the module may not template-edit (edit boundary, Sprint 101). `agb-validation` reads it but never locks it. During the async window between click and the `window.location.href` redirect, the checkbox stays fully interactive.

The invariant we want to hold (for UI integrity only — see §0): **once the order submit is in flight, the consent the submit was predicated on is visibly frozen.**

## 2. Goals

1. On **Order now** click (submission start), the AGB checkbox `#checkAgbTop` becomes **non-interactive** for the duration of the in-flight submit.
2. If the submit **fails** (error path → `hideLoading()` → `oe:stripe:submit-end`), the checkbox is **unlocked** so the customer can correct and retry.
3. On **redirect away** to Stripe, the lock simply leaves with the page. On **bfcache return** (Sprint 122's restore path, which calls `hideLoading()` → dispatches `oe:stripe:submit-end`), the checkbox is **unlocked** again — locking is a submit-in-flight property, not a permanent one. No new lifecycle: this falls out of reusing 122's seam.
4. No change to server-side consent enforcement or to `ord_agb` (already correct, §0); this is a UI-integrity fix.

## 3. Out of scope (explicit — no overengineering)

- **Editing the apex AGB partial** to add a Stimulus target — forbidden by the edit boundary. We keep resolving `#checkAgbTop` by its stable ID, exactly as `agb-validation` already does (`agb_validation_controller.js:38`).
- **OPC footer path** — the OPC "Pay with Stripe" widget has no AGB checkbox in the same template; separate concern, not this sprint.
- **Server-side re-validation of AGB** — already enforced; we do not duplicate it.
- **A generic "freeze the form" utility** locking every input — we lock exactly the one element with the integrity requirement (SRP).
- **Visual "locked" styling beyond the native disabled affordance** — the browser's disabled rendering is enough; no new CSS.

## 4. Architecture

### 4.1 Ownership — the lock belongs to `agb-validation` (SRP)

`agb-validation` already owns the `#checkAgbTop` reference (`this._coreCheckbox`, resolved in `connect()`). The checkbox lock is an **AGB concern**, so the lock/unlock methods live there — not in `order-submit`, which would otherwise reach for an element it does not own (SRP violation) and duplicate the ID lookup (DRY break).

New public methods on `agb-validation`:

```js
lockCheckbox() {
  if (this._coreCheckbox) this._coreCheckbox.disabled = true
}
unlockCheckbox() {
  if (this._coreCheckbox) this._coreCheckbox.disabled = false
}
```

Both are null-guarded (blConfirmAGB off → no checkbox → no-op), idempotent, and LSP-safe (pure additions).

### 4.2 Coordination — reuse Sprint 122's event seam (DIP)

Sprint 122 established that `order-submit` and `agb-validation` coordinate through **`document` CustomEvents**, not a hard `getControllerForElementAndIdentifier` reach-in — looser coupling, both depend on the event contract (Dependency Inversion), and it matches the house style (`oe:payment:error`, `stripe-footer.html.twig:340`).

122 already wired:

```
order_submit_controller.js
  hideLoading() → document.dispatchEvent(new CustomEvent('oe:stripe:submit-end'))
agb_validation_controller.js
  connect()/disconnect() → add/remove listener 'oe:stripe:submit-end' → updateButtonStates()
```

This sprint adds the **mirror start event** and the **unlock on end**:

```
order_submit_controller.js
  showLoading() → document.dispatchEvent(new CustomEvent('oe:stripe:submit-start'))   // NEW

agb_validation_controller.js
  connect()    → document.addEventListener('oe:stripe:submit-start', this._onSubmitStart)   // NEW
  disconnect() → document.removeEventListener('oe:stripe:submit-start', this._onSubmitStart) // NEW
  _onSubmitStart = () => this.lockCheckbox()                                                 // NEW
  _onSubmitEnd   = () => { this.unlockCheckbox(); if (this.enabledValue) this.updateButtonStates() }  // EXTENDED
```

Because `showLoading`/`hideLoading` already bracket the submit lifecycle (and `hideLoading` runs in the `finally` on error **and** on the bfcache restore from 122), the lock/unlock is automatically correct on **all three** endings — success-redirect, failure, and bfcache return — with **no new lifecycle to reason about** (DRY: reuse the existing bracketing established in 122).

### 4.3 Ordering of `showLoading` vs `appendAgbState` (verified safe)

`showLoading()` fires `submit-start` → `lockCheckbox()` sets `disabled = true`. `appendAgbState()` later reads `checkbox.checked`. Setting `disabled` does **not** clear `checked`, so `ord_agb=1` is still appended correctly even though the box is now locked. The spec asserts this explicitly (§5.1 step 6).

### 4.4 Why this depends on 122 shipping first

The unlock path relies on `oe:stripe:submit-end` being dispatched from both `hideLoading()` (122) and therefore from the bfcache restore handler (122). Introduce 122 first so this sprint only **adds** `submit-start` + lock/unlock, never re-implements the seam. The two fixes share one documented event contract.

## 5. TDD plan

Vehicle: Playwright E2E (no JS unit harness). Template: `tests/checkout/stripe-agb-confirmation.spec.ts` (drives the AGB checkbox + Stripe order button).

### 5.1 RED — new spec (write first, must fail today)

`tests/e2e/playwright/playwright/tests/checkout/agb-checkbox-locked-during-submit.spec.ts`

1. Login, add product, reach `cl=order`, tick `#checkAgbTop`, confirm `#stripe-checkout-btn` enabled.
2. Intercept/slow the `createCheckoutSession` response (Playwright `route` with a delay) so the in-flight window is observable before redirect.
3. Click **Order now**.
4. **Assertion (fails today):** `await expect(page.locator('#checkAgbTop')).toBeDisabled()` while the request is in flight.
5. Attempt `uncheck({ force: true })` → assert it remains `checked` (cannot be toggled while locked).
6. **Consent-not-lost:** assert the captured `createCheckoutSession` request URL contains `ord_agb=1` (proves locking did not strip consent — §4.3).
7. **Unlock-on-error case:** force the session call to return an error → assert `#checkAgbTop` becomes enabled again after `hideLoading()` → `submit-end` (retry possible).
8. **Unlock-on-bfcache-return case:** reuse Sprint 122's `goBack()` + `__pageshowPersisted === true` gate → assert `#checkAgbTop` is interactive again after restore.

### 5.2 GREEN

Add `lockCheckbox`/`unlockCheckbox`, the `submit-start` dispatch in `showLoading()`, and the listener wiring (§4.2). `npm run build`. Spec goes green.

### 5.3 REFACTOR

Verify both event listeners are removed in `disconnect()` (no leak across widget reloads); confirm `unlockCheckbox()` idempotency covers the double `submit-end` (error `finally` + restore). Run `stripe-agb-confirmation.spec.ts` + `stripe-checkout.spec.ts` for no regression.

## 6. Files touched

| File | Change |
|---|---|
| `resources/build/js/controllers/order_submit_controller.js` | dispatch `oe:stripe:submit-start` in `showLoading()` (the `submit-end` dispatch already exists from Sprint 122) |
| `resources/build/js/controllers/agb_validation_controller.js` | add `lockCheckbox`/`unlockCheckbox`; listen for `oe:stripe:submit-start`→lock; extend the existing `oe:stripe:submit-end` handler to `unlockCheckbox()` then `updateButtonStates()` |
| `assets/js/stripe-frontend.js` + `.min.js` (+ maps) | regenerated via `npm run build` |
| `tests/e2e/playwright/.../checkout/agb-checkbox-locked-during-submit.spec.ts` | **new** RED→GREEN spec |

No PHP, no Twig, no metadata, no payment-base, no apex edits.

## 7. Risks

- **Disabled checkbox is not submitted by native forms** — irrelevant here: consent travels as `ord_agb=1` computed by `appendAgbState()` *before* the lock matters, and real enforcement is server-side (§0, §4.3). Asserted in §5.1 step 6.
- **Event-name collision** — `oe:stripe:submit-start`/`-end` namespaced under `oe:stripe:` (matches `oe:payment:error`); `grep` to confirm uniqueness before wiring (`submit-end` already introduced by 122).
- **Double-fire of `submit-end`** (error `finally` + a later restore) — `unlockCheckbox()` and `updateButtonStates()` are idempotent, so harmless.
- **Sprint 122 not merged** — this sprint's unlock-on-restore and the `submit-end` listener assume 122 is in place. Do not merge 123 ahead of 122; CI gate on the predecessor branch.
- **Build step** — `npm run build` mandatory; pre-commit does not bundle JS.

## 8. Acceptance criteria

- [ ] New E2E spec fails on `HEAD`, passes after the fix.
- [ ] After Order-now click, `#checkAgbTop` is `disabled` and cannot be unticked while the submit is in flight.
- [ ] `ord_agb=1` is present in the captured `createCheckoutSession` request (consent not lost by the lock).
- [ ] On submit error, the checkbox is re-enabled (retry possible).
- [ ] On bfcache return (Sprint 122 path, `__pageshowPersisted === true`), the checkbox is interactive again.
- [ ] `stripe-agb-confirmation.spec.ts` + `stripe-checkout.spec.ts` still pass.
- [ ] `assets/js` rebuilt; PHPCS/PHPStan/PHPMD unaffected (JS-only change).
