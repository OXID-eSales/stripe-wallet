# Sprint 122 — Order-now button stays disabled after returning from the external payment page

**Date planned:** 2026-06-08
**Ticket:** (bug) "Order now button stays disabled after returning from external payment page"
**Branch:** feature branch off `b-7.4.x` (suggested: `b-7.4.x-fix-order-button-stuck-disabled`) — lowest applicable branch, merge up per the branching strategy.
**Repos touched:** `extensions/stripe` ONLY — zero payment-base changes, zero one-page-checkout changes.
**Sibling sprint:** [`sprint-123-agb-checkbox-lock-after-order-submit.md`](sprint-123-agb-checkbox-lock-after-order-submit.md) — same two controllers, same submit lifecycle. **Do Sprint 122 first**: it introduces the `oe:stripe:submit-end` coordination seam and makes `agb-validation` the single authority on the button's resting state. Sprint 123 adds the `submit-start`/lock half on the *same* seam.

---

## 1. Why

Standard checkout, step 3 (`cl=order`), Stripe wallet payment. Repro:

1. Checkout → select Stripe (external) payment → reach the order page.
2. Click **Order now** (`#stripe-checkout-btn`).
3. Browser redirects to the external Stripe Checkout page.
4. Return to the shop via the browser **Back** button (or Stripe's back arrow).
5. **Actual:** the Order-now button is `disabled`; the customer is stuck and cannot retry.

### What the code does today (verified against source)

```
order.html.twig:92-103   <div data-controller="agb-validation" ...>
                            <button id="stripe-checkout-btn"
                                    data-controller="order-submit"
                                    data-action="click->order-submit#handleSubmit"
                                    data-order-submit-target="button"
                                    data-agb-validation-target="submitButton" ...>

order_submit_controller.js:289-293  showLoading()  → this.element.disabled = true   (on click)
order_submit_controller.js:298-303  hideLoading()  → this.element.disabled = false  (finally block)
order_submit_controller.js:154-156  window.location.href = data.url  → navigates away

agb_validation_controller.js:36-46  connect()           → updateButtonStates()
agb_validation_controller.js:63-88  updateButtonStates() → button.disabled = !checkbox.checked
```

So the button is **disabled in two independent places** — `order-submit` (transient, while submitting) and `agb-validation` (resting, driven by `#checkAgbTop`) — and **neither re-runs when the page is restored from the back-forward cache (bfcache)**.

### Root cause — what we know vs. what we must confirm

**Known (verified by the Explore sweep):** there are **zero `pageshow` / `pagehide` / `popstate` listeners** anywhere in the module (`grep` over `resources/` returns nothing). On a bfcache restore the browser reinstates the frozen DOM and does **not** re-instantiate Stimulus controllers — `connect()` does **not** re-fire. So whatever `disabled` value the DOM was frozen with is exactly what the customer sees on return, and nothing recomputes it.

**Not yet pinned down — confirm before locking the guard (see §5.0):** *why* the frozen value is `disabled = true`. Two candidate mechanisms:

- **(M1) `hideLoading()` never ran before freeze.** `handleStripeCheckout()` does `window.location.href = …; return`; the `finally { hideLoading() }` is queued as a microtask that the navigation may pre-empt, freezing the button at `disabled = true`.
- **(M2) `hideLoading()` *did* run** (button frozen `disabled = false`), but on return the **resting AGB state was never recomputed**, and the page was frozen at a moment where the AGB-driven disable applied.

We do **not** need to win this argument to fix the bug, and we deliberately do not over-claim it as "verified." The fix below is **correct under both M1 and M2** because it re-establishes the resting state from the live checkbox on restore, regardless of the frozen value. §5.0 adds a cheap trace step so the dev log records which mechanism is real, rather than asserting one we haven't observed.

This is the same bfcache class of bug as `coupon-survives-back-navigation.spec.ts` (STRP-105), but on the button's DOM state rather than the basket.

## 2. Goals

1. After returning from the external payment page **via bfcache restore** (Back button / Stripe back arrow), the Order-now button is in its **correct resting state**, recomputed from the live `#checkAgbTop` checkbox — **not** blindly enabled:
   - enabled when AGB is ticked (or AGB inactive),
   - disabled only because the AGB checkbox is unticked (the one legitimate reason).
2. The correct state is established **deterministically**, not by relying on the order in which two controllers happen to receive the same event.
3. No regression to the in-flight loading state: while a submit is genuinely mid-redirect (page still visible), the button stays disabled exactly as today.

## 3. Out of scope (explicit — no overengineering)

- **OPC "Pay with Stripe" footer** (`stripe-footer.html.twig`) — different controller, different page; its `connect()` already force-enables (`stripe-footer.html.twig:81-84`, verified). If it shares the bfcache flaw it is a **follow-up sprint**, not this one. Do not touch it here.
- **Disabling bfcache** (e.g. `Cache-Control: no-store` on the order page) — heavy-handed, hurts back-nav UX, a server concern. We fix the client resting state, not the cache.
- **The non-bfcache fresh-load Back path.** When bfcache is *not* used, Back triggers a full reload, `connect()` re-fires, and `updateButtonStates()` already runs — so that path is **not broken** and needs no new code. We test it (§5.1, case C) only to prove we did not regress it; we do not add a code path for it.
- **Card-element (`paymentType: 'card'`) flow** — the same restore path covers it for free; no card-specific logic is added.
- **A generic page-lifecycle framework / state machine** — one `pageshow` handler in the controller that owns the transient state, plus one event the resting-state owner listens to, is sufficient (SRP). No speculative abstraction.
- **Server-side session cleanup on cancel** — already handled by `checkoutCancel()`; out of scope.

## 4. Architecture

### 4.1 The fragility we are removing

The naive fix — give *both* controllers a `pageshow` listener (`order-submit#hideLoading` to clear the transient disable, `agb-validation#updateButtonStates` to recompute the resting state) — is **wrong**, because the final `disabled` value would depend on **which listener fires last**, and that order is not guaranteed.

Window `pageshow` listeners fire in `addEventListener` registration order, which equals Stimulus `connect()` order. The `agb-validation` div is the **parent** of the `order-submit` button, so Stimulus most likely connects `agb-validation` **first** → its listener fires **first** → `order-submit#hideLoading` fires **last** and runs `this.element.disabled = false` **unconditionally**. Result on restore with AGB **unticked**: the button ends up **enabled** — re-opening the exact "submit without AGB" hole, violating Goal 1. We must not ship a fix whose correctness rests on undocumented listener ordering.

### 4.2 The seam: one synchronous `submit-end` event, AGB is the authority (SRP / DIP)

`hideLoading()` clears the *transient* submit state. It must **not** be the final word on `disabled` — the resting state is an **AGB concern**. So:

1. `order-submit#hideLoading()` clears its transient state (re-enable + restore text) and then **dispatches `oe:stripe:submit-end`** on `document`.
2. `agb-validation` listens for `oe:stripe:submit-end` and runs `updateButtonStates()` — reading the live checkbox, the source of truth — as the **last** mutation of `disabled`.

Because `dispatchEvent` is **synchronous**, the AGB recompute completes *inside* the `hideLoading()` call, deterministically **after** `hideLoading` set `disabled = false`. There is no listener-ordering race: there is exactly one `pageshow` owner (`order-submit`), and the resting-state owner (`agb-validation`) reacts to an event, not to a second `pageshow` subscription.

```
order_submit_controller.js
  connect()      → window.addEventListener('pageshow', this._onPageShow)
  disconnect()   → window.removeEventListener('pageshow', this._onPageShow)
  _onPageShow    = (e) => { if (e.persisted) this.hideLoading() }   // bfcache restore only
  hideLoading()  → this.element.disabled = false; restore text;
                   document.dispatchEvent(new CustomEvent('oe:stripe:submit-end'))

agb_validation_controller.js
  connect()      → document.addEventListener('oe:stripe:submit-end', this._onSubmitEnd)
  disconnect()   → document.removeEventListener('oe:stripe:submit-end', this._onSubmitEnd)
  _onSubmitEnd   = () => { if (this.enabledValue) this.updateButtonStates() }
```

This is robust to **both** M1 and M2 from §1: whatever the frozen `disabled` value, `hideLoading()` first clears it, then `updateButtonStates()` sets the truthful resting value from the checkbox. `agb-validation` keeps **no** `pageshow` listener of its own.

### 4.3 Why this also sets up Sprint 123

`oe:stripe:submit-end` is exactly the "submit lifecycle ended" signal Sprint 123 needs to **unlock** the AGB checkbox. By introducing it here (and its mirror `oe:stripe:submit-start` in 123), the two bug fixes share one documented event contract instead of 123 reaching back into 122's `pageshow` handler. The naming follows the house convention (`oe:payment:error`, `stripe-footer.html.twig:340`, verified).

### 4.4 Listener lifecycle (no leaks, LSP-safe)

Each controller binds its handler once, stores it on the instance, adds it in `connect()` and removes the exact same reference in `disconnect()` — symmetric, leak-free across Stimulus reconnects. We only **add** behaviour to the lifecycle contract; we never change the published meaning of `connect`/`disconnect`, `hideLoading`, or `updateButtonStates`. All three are idempotent, so re-invocation is safe (LSP-safe).

### 4.5 DRY

`order-submit` reuses its existing `hideLoading()`; `agb-validation` reuses its existing `updateButtonStates()`. The only new code is listener wiring + one `dispatchEvent` line. No enable/disable logic is duplicated. The wiring shape differs per controller (one owns `pageshow`, the other owns the event reaction), so there is nothing to extract — a shared mixin would be overengineering.

## 5. TDD plan

There is **no JS unit harness** in the module (no jest/vitest in `package.json`). The contract here is browser-lifecycle (bfcache), which only a real browser exercises — so the failing-test-first vehicle is Playwright E2E. Template: `tests/checkout/coupon-survives-back-navigation.spec.ts` (drives select-Stripe → order page → redirect → return).

### 5.0 (Pre-RED) Confirm the mechanism — do not skip

Before writing the guard, run the repro once with a throwaway instrument to record which mechanism (M1/M2, §1) is real, so the dev log states an observed fact, not a guess:

- In a `page.addInitScript`, register a one-line `pageshow` listener that records `window.__pageshowPersisted = e.persisted` and `window.__btnDisabledOnShow = document.getElementById('stripe-checkout-btn')?.disabled`.
- Reproduce the Back navigation; read both values.
- **If `__pageshowPersisted` is never `true` in headless CI**, bfcache did not engage — STOP and record it (see §7). A spec that green-lights without `persisted === true` would be testing the wrong path (the "silent skips lie" lesson). Do not let the spec pass by falling through to a fresh load.

This script is **test-only instrumentation**, never shipped in `resources/`.

### 5.1 RED — new spec (write first, must fail on current code)

`tests/e2e/playwright/playwright/tests/checkout/order-button-enabled-after-back.spec.ts`

Shared `addInitScript` (test-side) captures `window.__pageshowPersisted` so every case can assert the branch under test actually executed.

**Case A — bfcache restore, AGB ticked (the bug):**
1. Login, add product, navigate to `cl=order`, tick `#checkAgbTop`.
2. Click Order now; `expect(page).toHaveURL(/checkout\.stripe\.com/)`.
3. `page.goBack()` (real back-forward navigation, bfcache-eligible).
4. **Gate the assertion on the real mechanism:** `expect(await page.evaluate(() => window.__pageshowPersisted)).toBe(true)` — if this is false, the test **errors out loud** (it has not exercised the fix), it does not silently pass.
5. **Fails today:** `await expect(page.locator('#stripe-checkout-btn')).toBeEnabled()` (AGB still ticked → must be enabled).
6. Re-click the button → URL goes to Stripe again — proves the user is unstuck, not merely visually enabled.

**Case B — bfcache restore, AGB unticked (no over-correction):**
Same as A, but untick `#checkAgbTop` on the order page before going back is not possible (we are mid-redirect); instead, after `goBack()` and confirming `__pageshowPersisted === true`, untick the box and assert the button is `disabled`; then assert that the *restore itself* did not blindly enable — to test the restore path specifically, restore with the box already unticked by submitting from a state where AGB was satisfied, then simulate an unticked restore via a frozen-DOM fixture if bfcache preserves the unticked value. Concretely: assert that immediately after restore the button's `disabled` equals `!#checkAgbTop.checked`. This is the assertion that would have caught the §4.1 ordering bug.

**Case C — fresh-load Back (regression guard, not a fix path):**
Force a fresh load on Back (`page.goBack()` after disabling bfcache for the context, or `goto(cl=order)`), assert `window.__pageshowPersisted === false`, and assert the button state is still correct because `connect()` re-ran. This documents that we did **not** regress the non-bfcache path and that the fix is *not* what makes it work there.

### 5.2 GREEN

Add the `pageshow` listener + `submit-end` dispatch to `order_submit_controller.js`, and the `submit-end` listener to `agb_validation_controller.js` (§4.2), under `resources/build/js/controllers/`. Then `npm run build` to regenerate `assets/js/stripe-frontend*.js`. Cases A and B go green; C stays green.

### 5.3 REFACTOR

Confirm no duplicated state logic; confirm `disconnect()` removes the exact bound references; run `stripe-agb-confirmation.spec.ts` + `stripe-checkout.spec.ts` to prove no regression to the happy path.

## 6. Files touched

| File | Change |
|---|---|
| `resources/build/js/controllers/order_submit_controller.js` | add `pageshow`(persisted)→`hideLoading()` in `connect`/`disconnect`; `hideLoading()` dispatches `oe:stripe:submit-end` |
| `resources/build/js/controllers/agb_validation_controller.js` | listen for `oe:stripe:submit-end`→`updateButtonStates()` in `connect`/`disconnect` (no own `pageshow` listener) |
| `assets/js/stripe-frontend.js` + `.min.js` (+ maps) | regenerated via `npm run build` (build output — do not hand-edit) |
| `tests/e2e/playwright/.../checkout/order-button-enabled-after-back.spec.ts` | **new** RED→GREEN spec with `__pageshowPersisted` gating |

No PHP, no Twig, no metadata, no payment-base.

## 7. Risks

- **bfcache not engaged in headless CI** — Chromium may serve a fresh load instead of a bfcache restore, masking the bug. Mitigation: §5.0 + the `__pageshowPersisted` gate make a wrong-path pass **error loudly** instead of green-washing. If CI genuinely cannot produce `persisted === true`, record it in the dev log and mark Case A as environment-gated rather than letting it pass for the wrong reason.
- **`pageshow` fires on the very first load too** (`persisted === false`); the `if (e.persisted)` guard prevents re-running on initial render. The guard is a small optimisation, not a correctness crutch — `hideLoading()`/`updateButtonStates()` are idempotent, so an accidental extra run would be harmless.
- **`submit-end` dispatched on the normal error path too** — `hideLoading()` runs in the `finally` after a failed submit and will now also dispatch `submit-end`; `agb-validation` recomputes from the (unchanged, ticked) checkbox → button re-enabled, same as today. No regression, and it keeps "submit ended" a single concept regardless of cause.
- **Build step required** — forgetting `npm run build` ships stale `assets/`. Add to the sprint checklist; pre-commit does not bundle JS.

## 8. Acceptance criteria

- [ ] §5.0 mechanism trace recorded in the dev log (M1 vs M2 observed, not assumed).
- [ ] New E2E spec fails on `HEAD`, passes after the fix.
- [ ] Case A: bfcache restore with AGB ticked → `__pageshowPersisted === true` **and** button enabled, re-click starts a fresh session.
- [ ] Case B: on restore the button's `disabled` equals `!#checkAgbTop.checked` (no over-correction; would have caught the listener-ordering bug).
- [ ] Case C: fresh-load Back → `__pageshowPersisted === false`, state still correct via `connect()` (no regression, not a new fix path).
- [ ] `stripe-checkout.spec.ts` + `stripe-agb-confirmation.spec.ts` still pass.
- [ ] `assets/js` rebuilt; PHPCS/PHPStan/PHPMD unaffected (JS-only change).