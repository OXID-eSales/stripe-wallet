# Sprint 137 — IFRAME-05: a fresh session gets the payment sheet, never the button

> A shopper who opens the shop in a new browser session (incognito, first visit) and reaches
> `cl=order` with the payment-base iframe flag ON sees the classic **Order now** button instead of
> the Stripe Embedded Checkout sheet. Only after ticking Terms and clicking does the button vanish
> and the sheet mount. A returning session eager-mounts the sheet directly. Same shop, two order
> pages — decided by session history.
>
> **Root cause (see [research report](../reports/01-iframe-fresh-session-shows-button-research.md)):**
> `order.html.twig` computes `eagerIframe = iframe and not (blConfirmAGB and not priorConsent)`. A
> fresh session has no persisted consent, so the template falls back to the button as the
> "consent gate", because `StripeOrderController::createCheckoutSession()` answers **400** without
> `ord_agb=1`. Deliberate in IFRAME-02f (2026-07-29); wrong as product behaviour.

**Repo:** `extensions/stripe` (+ E2E submodule `tests/e2e/playwright`) · **Branch:** `fix/iframe-fresh-session-no-button`
**Ticket:** STRP-TBD · **Type:** bug fix (UX contract of iframe mode) · **Base:** `b-7.4.x`
**Mode:** TDD-first, reproduce-before-fix (reproduction is already RED, see S0). One commit per story, each RED → GREEN → REFACTOR.
**Binding every commit:** TDD · SOLID · DRY · Clean Code (no-else, ≤25-line methods) · No overengineering · PSR-12 · PHPStan max, no new baseline entries · `./bin/pre-commit-check.sh` green.

---

## 0. Decision gate — how consent and the sheet coexist

The sheet is Stripe's iframe; we cannot intercept its Pay button. AGB consent must therefore be
obtained **before the sheet is usable** and must be **recorded server-side** before payment.

| | **A — veiled eager mount (chosen)** | B — mount on consent |
|---|---|---|
| Fresh session sees | the sheet immediately, under a veil until Terms are ticked | the AGB card and a hint; the sheet appears on tick |
| Server change | relax the 400 guard for iframe mode; add a consent endpoint | none |
| Sessions created | one per page load (as today's eager mode already does) | one per tick |
| Legal record | consent POSTed and persisted when the veil lifts, before Pay is reachable | consent travels with the session request (today's mechanism) |
| Matches the report ("I expect the iframe") | yes | partly |

**Assumption taken (Daniil unavailable while this was written): A.** It is what the report asks for,
and it is the same veil pattern the OPC footer already uses for a stale sheet (`.stripe-sheet-stale`).
If B is preferred: drop S1 and S2, and in S4 replace the veil with "mount when the hidden button
becomes enabled" (the OPC `_armPayableWatcher` pattern); the S0 spec's "iframe attached on load"
assertion becomes "attached after the tick".

**Out of scope, explicitly:** refusing to finalise on `checkoutSuccess` when no consent was
recorded (payment already happened; stranding it is worse), the OPC footer (unaffected, mounts when
payable), Mollie/PayPal (redirect-only in iframe mode).

---

## 1. Definition of Done

- [ ] Fresh session, iframe ON, `blConfirmAGB` ON: `#stripe-checkout-btn` never visible; Stripe
      iframe attached on load; veil visible until `#checkAgbTop` is ticked; tick → `confirmAgb` 200 →
      veil gone; untick → veil back.
- [ ] Fresh session, iframe ON, `blConfirmAGB` OFF: sheet on load, no veil (unchanged).
- [ ] Redirect mode (`blPaymentBaseUseIframe` OFF): button, 400 without `ord_agb=1` — **unchanged**.
- [ ] Exactly one `createCheckoutSession` per order-page load (`stripe-eager-mount-single-session.spec.ts` still green).
- [ ] Zero `Content-Security-Policy` console errors on the checkout (S6).
- [ ] `stripe-order-page-fresh-session-iframe.spec.ts` GREEN against `https://daniil.oxiddev.de`;
      `stripe-order-page-iframe.spec.ts` adapted and GREEN; `stripe-checkout.spec.ts` GREEN.
- [ ] Unit + Integration suites green; PHPStan max 0 new; PHPCS/PHPMD clean; bundles rebuilt
      (`npm run build:prod` **and** `build:dev`), `oe:module:install-assets`, `var/cache` wiped.
- [ ] CHANGELOG entry; status.md + completion report in `reports/`.

---

## 2. Stories

### S0 — Reproduction spec (RED) ✅ written 2026-09-18
`tests/checkout/stripe-order-page-fresh-session-iframe.spec.ts` (E2E submodule). Fresh context,
login, basket, payment (Stripe), `cl=order`. Contract asserted with `expect.soft` so one run reports
everything: button not visible · iframe attached on load · veil visible while unticked · tick →
veil hidden + one `confirmAgb` 200 · no page errors · no console errors. Then documents the click
path and the same-session reload as evidence. **Currently fails on:** button visible, iframe absent,
14 CSP console errors. Stays RED until S4 + S6 land.

### S1 — Server: the 400 consent guard applies to redirect mode only
**File:** `src/Stripe/Controller/StripeOrderController.php` (`ensureAgbAccepted()`).
**RED** (`tests/Unit/Stripe/Controller/StripeOrderControllerAgbConsentTest.php`, testable-subclass pattern already there):
- `testCreateCheckoutSessionProceedsWithoutConsentInIframeMode` — iframe ON, `blConfirmAGB` ON, no
  `ord_agb` → no 400, session event dispatched, consent **not** persisted.
- `testCreateCheckoutSessionStillPersistsConsentInIframeModeWhenOrdAgbTruthy` — iframe ON,
  `ord_agb=1` → persisted (returning-session path keeps working).
- `testCreateCheckoutSessionRejectsMissingConsentInRedirectMode` — iframe OFF → 400 (pin today's behaviour).
**GREEN:** inject `IframeCheckoutSettingsInterface` the way the controller already resolves services
(`getServiceFromContainer`, overridable in the test subclass). `ensureAgbAccepted()` gains one early
return: `if ($this->isIframeMode()) { return true; }` placed *after* the "persist when accepted"
branch so a consent that does arrive is still recorded. Doc-comment explains: in iframe mode the
sheet is veiled client-side and consent is recorded by `confirmAgb()` (S2).
**REFACTOR:** none expected. Method stays ≤ 15 lines.

### S2 — Server: `confirmAgb` records or clears consent
**File:** `StripeOrderController.php` — new public action `confirmAgb(): void`.
**RED** (same test file):
- `testConfirmAgbPersistsConsentWhenOrdAgbTruthy` → session flag set, body `{"agb":true}`.
- `testConfirmAgbClearsConsentWhenOrdAgbFalsy` → flag deleted, body `{"agb":false}`.
- `testConfirmAgbRejectsInvalidSessionChallenge` → 403, flag untouched.
**GREEN:** `sendSecureJsonHeaders()` → `validateSessionChallenge()` (403 on failure, same message as
`createCheckoutSession`) → `getAgbAcceptedFromRequest() ? persistAgbConsent() : clearAgbConsent()` →
`echo json_encode(['agb' => $accepted])` → `exitWithJson()`. ≤ 20 lines, reuses the helper, no new
class (one caller, no abstraction). Register nothing extra: OXID routes `fnc=confirmAgb` on `cl=order`
through the existing controller chain.
**Security:** stoken-checked, session-scoped, idempotent, writes one boolean. No PII.

### S3 — Template: iframe mode never paints the button; adds the veil
**File:** `views/twig/extensions/themes/default/page/checkout/order.html.twig`.
**RED** (new `tests/Integration/Checkout/OrderPageIframeConsentTemplateTest.php`, modelled on
`SingleShippingOrderTemplateTest` — probe view/viewconf objects, real Twig via `TemplateRendererBridgeInterface`):
- iframe ON, `isConfirmAGBActive=true`, `isPriorAgbConsent=false` → button has `hidden`,
  `data-order-submit-eager-value="true"`, veil element `[data-order-submit-target="agbVeil"]` rendered,
  `data-order-submit-confirm-agb-url-value` contains `fnc=confirmAgb`.
- iframe OFF → button **not** hidden, no veil (regression pin).
**GREEN:**
- `{% set eagerIframe = stripeIframe %}` — delete `agbGating` and its comment; replace with a comment
  pointing at the veil.
- Wrap `#stripe-embedded-checkout` in `<div class="stripe-embedded-wrap position-relative">` and add
  the sibling veil (sibling, not child — an iframe owns its event surface, same reasoning as the OPC
  `.stripe-sheet-stale` overlay): `role="status" aria-live="polite"`, `hidden` by default, text
  `translate({ ident: 'STRIPE_AGB_UNLOCK_PAYMENT' })`.
- New values on the host div: `data-order-submit-confirm-agb-url-value="{{ sslSelfLink ~ 'cl=order&fnc=confirmAgb' }}"`.
- Translations `STRIPE_AGB_UNLOCK_PAYMENT` in `translations/en/stripe_lang.php` ("Please accept the
  Terms and Conditions above to unlock the payment form.") and `de/` ("Bitte akzeptieren Sie oben die
  AGB, um das Zahlungsformular freizuschalten.").
- Veil CSS: move the OPC `.stripe-sheet-stale` rules into a shared class in the module's frontend CSS
  only if a second caller appears — **for now duplicate the 12 lines inline** (don't pre-DRY).
**Note:** keep the `hidden` button in the DOM — `agb-validation` still toggles its `disabled`
attribute, and S4 reads that as the payability signal.

### S4 — JS: veil follows the payability signal, consent is recorded before the veil lifts
**File:** `resources/build/js/controllers/order_submit_controller.js` (+ rebuilt `assets/js/*`).
No JS unit harness exists in this module; the S0 spec is the executable test. **RED = S0 still failing.**
**GREEN:**
- `static targets` += `agbVeil`; `static values` += `confirmAgbUrl: String`.
- `connect()` unchanged (eager mount already keyed on `eagerValue`).
- `mountEmbeddedCheckout()` → after `mount(...)` call `this._armConsentVeil()`.
- `_armConsentVeil()`: if no `agbVeil` target or no `button` target → return. Sync once, then
  `MutationObserver` on `buttonTarget` (`attributeFilter: ['disabled']`) → `_syncConsentVeil()`.
  This is the same signal the OPC footer's `_armPayableWatcher` reads: the disabled state is where
  *every* requirement ends up, so a new requirement can't bypass the veil.
- `_syncConsentVeil()`: `const consented = !this.buttonTarget.disabled` → `agbVeilTarget.hidden = consented`
  after `await this._recordConsent(consented)`. On a non-2xx response keep the veil **up** and
  `presentError()` (fail closed). Debounce not needed: the checkbox toggles are human-paced.
- `_recordConsent(bool)`: `fetch(buildUrlWithCsrfToken(confirmAgbUrlValue) + '&ord_agb=' + (bool?1:0), {method:'POST', credentials:'same-origin'})`.
- `disconnect()`: `this._consentObserver?.disconnect()`.
- Keep `revealButton()` as the fallback when the eager mount fails (unchanged contract).
**Build & deploy:** `npm run build:prod && npm run build:dev`; from the shop root
`bin/oe-console oe:module:install-assets && rm -rf var/cache/*` (see 20260723 deploy note).
**REFACTOR:** if `order_submit_controller.js` exceeds its current size budget, extract
`consent_veil.js` beside `embedded_checkout_registry.js` — only if it does.

### S5 — E2E: turn S0 GREEN and re-align the older specs
- Run S0 against the live shop (`SHOP_URL=https://daniil.oxiddev.de`). Expect GREEN.
- `stripe-order-page-iframe.spec.ts`: the "AGB-gated iframe → button visible as consent gate"
  branch is now a **bug**, not a mode. Replace it with the veil assertions; keep the redirect-mode
  branch and the eager branch.
- `stripe-eager-mount-single-session.spec.ts`: still exactly one `createCheckoutSession`; add
  "exactly one `confirmAgb` per tick".
- `stripe-checkout.spec.ts` (adaptive): the inline branch must now tick AGB **before** interacting
  with the sheet.
- Attach screenshots per step; store the report under `reports/02-iframe-05-walkthrough/`.

### S6 — Remove the dead CSP `<meta>` (14 console errors per checkout)
**File:** `views/twig/frontend/base_js.html.twig:6`.
**Finding:** the `<meta http-equiv="Content-Security-Policy">` is rendered inside `block base_js`,
i.e. in `<body>`; the browser logs an error and ignores it on every Stripe-active page. It has never
been in force. Making it effective (moving it to `<head>`) would restrict `script-src` to self +
Stripe on pages where Mollie (`js.mollie.com`), PayPal and OPC scripts also run → **breakage**, and
a `<meta>` CSP cannot carry `frame-ancestors` or report-uri anyway. CSP belongs in the web-server /
shop HTTP headers, not in a payment module's template.
**RED** (`tests/Integration/Frontend/BaseJsTemplateTest.php`, render `@oe_payments_stripe_wallet/frontend/base_js.html.twig`
with a probe viewconf where `isStripeCheckoutActive()` = true): assert output contains
`js.stripe.com/v3/` and **does not** contain `Content-Security-Policy`.
**GREEN:** delete the `<meta>` and its comment; add a two-line comment: "No CSP here — a body
`<meta>` is ignored by browsers and a head one would have to be shop-wide; configure CSP in the
web server." Add a `for_merchant` docs note on recommended CSP sources
(`https://js.stripe.com https://m.stripe.com`, `frame-src https://checkout.stripe.com https://js.stripe.com`).

### S7 — Wrap-up
CHANGELOG (Fixed: "iframe mode showed the Order-now button in fresh sessions"; Fixed: "CSP meta
tag emitted in body logged a console error on every page"); `status.md`; completion report
`reports/03-sprint-137-completion.md`; move this file to `done/`.

---

## 3. Commit plan

| # | Commit | Gate |
|---|---|---|
| 0 | `test(e2e): prove a fresh session gets the button instead of the sheet in iframe mode` (submodule) | spec collects, RED |
| 1 | `fix(checkout): let iframe mode create the session before consent is recorded` | Unit ✓ |
| 2 | `feat(checkout): record AGB consent through fnc=confirmAgb` | Unit ✓ |
| 3 | `fix(checkout): never paint the Place-Order button in iframe mode; veil the sheet instead` | Integration ✓ |
| 4 | `fix(checkout): lift the consent veil only after the server recorded the consent` | bundles rebuilt, S0 GREEN |
| 5 | `test(e2e): align the iframe specs with the veiled sheet` (submodule) | all iframe specs GREEN |
| 6 | `fix(frontend): drop the CSP meta tag browsers were ignoring` | Integration ✓, 0 CSP console errors |
| 7 | `docs: sprint 137 completion` | — |

Every commit ends with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

## 4. Risks and how each is closed

- **Veil bypass via dev tools** → payment goes through without a recorded consent. Same exposure as
  any client-side gate; the server still records consent on the honest path and the order page still
  requires the checkbox visibly. Documented as out of scope (§0).
- **Session created before consent** creates a DRAFT contract + NOT_FINISHED order for shoppers who
  never tick. Already true today for returning sessions and for every abandoned eager mount; the
  payment-base `NotFinishedOrderCleanup` handles it. No new cleanup needed.
- **`confirmAgb` race with a fast Pay click** — impossible: the veil stays up until the 200 arrives.
- **Two mounts per page** — no new mount path is added; `stripe-eager-mount-single-session.spec.ts` guards it.
- **Redirect-mode regression** — pinned by S1's third test and the redirect branch of `stripe-order-page-iframe.spec.ts`.
