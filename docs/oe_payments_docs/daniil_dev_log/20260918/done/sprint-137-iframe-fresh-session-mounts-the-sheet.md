# Sprint 137 — IFRAME-05: a fresh session gets the payment sheet, never the button

> ## ✅ DONE — 2026-09-18, option B, verified live on daniil.oxiddev.de
>
> **Decision (Daniil, 2026-09-18): option B — mount on consent.** No server change. In iframe
> mode the Place-Order button is never painted; when Terms are not yet accepted a hint stands in
> its place and the Stripe sheet mounts the moment the checkbox is ticked. The tick carries
> `ord_agb=1`, so `ensureAgbAccepted()` records the consent exactly as a button click did.
> Completion report: [reports/02](../reports/02-sprint-137-completion.md).

> A shopper who opens the shop in a new browser session (incognito, first visit) and reaches
> `cl=order` with the payment-base iframe flag ON saw the classic **Order now** button instead of
> the Stripe Embedded Checkout sheet. Only after ticking Terms and clicking did the button vanish
> and the sheet mount. A returning session eager-mounted the sheet directly. Same shop, two order
> pages — decided by session history.
>
> **Root cause (see [research report](../reports/01-iframe-fresh-session-shows-button-research.md)):**
> `order.html.twig` computed `eagerIframe = iframe and not (blConfirmAGB and not priorConsent)`. A
> fresh session has no persisted consent, so the template fell back to the button as the
> "consent gate", because `StripeOrderController::createCheckoutSession()` answers **400** without
> `ord_agb=1`. Deliberate in IFRAME-02f (2026-07-29); wrong as product behaviour.

**Repo:** `extensions/stripe` (+ E2E submodule `tests/e2e/playwright`) · **Branch:** `b-7.4.x-iframe-05-fresh-session-sheet` (from `b-7.4.x`)
**Ticket:** STRP-TBD · **Type:** bug fix (UX contract of iframe mode)
**Mode:** TDD-first, reproduce-before-fix. RED → GREEN → REFACTOR per story.
**Binding every commit:** TDD · SOLID · DRY · Clean Code (no-else, ≤25-line methods) · No overengineering · PSR-12 · PHPStan max, no new baseline entries.

---

## 0. Decision gate — how consent and the sheet coexist

The sheet is Stripe's iframe; we cannot intercept its Pay button. AGB consent must therefore be
obtained **before the sheet is usable** and must be **recorded server-side** before payment.

| | A — veiled eager mount | **B — mount on consent (chosen)** |
|---|---|---|
| Fresh session sees | the sheet immediately, under a veil until Terms are ticked | the AGB card and a hint; the sheet appears on the tick |
| Server change | relax the 400 guard for iframe mode; add a consent endpoint | **none** |
| Sessions created | one per page load | one per tick (one per page load in practice) |
| Legal record | consent POSTed separately before the veil lifts | consent travels with the session request — today's mechanism, unchanged |
| Pattern | OPC stale-sheet veil | OPC footer's "mount when payable" (`_armPayableWatcher`) |

**Chosen: B** (Daniil, 2026-09-18). Smaller, keeps the server-side consent guard untouched, and
mirrors how the OPC footer already behaves in iframe mode.

**Out of scope, explicitly:** the OPC footer (unaffected), Mollie/PayPal (redirect-only in iframe
mode), the low-order-price `disabled` button (pre-existing; in that state nothing may mount, and nothing does).

---

## 1. Definition of Done

- [x] Fresh session, iframe ON, `blConfirmAGB` ON: `#stripe-checkout-btn` never visible; hint shown;
      no sheet and no `createCheckoutSession` before the tick; tick → exactly one call with
      `ord_agb=1` → 200 → sheet mounts; hint gone; button still hidden.
- [x] Same session reloaded: consent persisted → eager path, sheet on load, no button.
- [x] Iframe ON, `blConfirmAGB` OFF: sheet on load, no hint (integration test).
- [x] Redirect mode: visible button, no hint — **unchanged** (integration test).
- [x] Exactly one `createCheckoutSession` per order-page load (`stripe-eager-mount-single-session.spec.ts` GREEN).
- [x] Zero `Content-Security-Policy` console errors on the checkout (was 14).
- [x] E2E GREEN against `https://daniil.oxiddev.de`: `stripe-order-page-fresh-session-iframe`,
      `stripe-order-page-iframe`, `stripe-eager-mount-single-session`, `stripe-checkout` (EN + DE).
- [x] Integration suite 98/98; PHPCS clean; PHPStan max 0 errors; bundles rebuilt (prod + dev),
      served through the `out/modules` symlink; `var/cache` wiped.
- [x] CHANGELOG entry; status.md; completion report.
- [ ] Pre-existing, **not** from this sprint (no PHP under `src/` changed — `git diff b-7.4.x -- src/` is empty):
      PHPMD `TooManyMethods` on `StripeOrderController` (27 > 25); Unit suite aborts at collection
      with `Mollie\Controller\PaymentController_parent not found` (multi-PSP class-chain build in
      this dev shop). Both reported in the completion report.

---

## 2. Stories (as executed)

### S0 — Reproduction spec ✅ RED 2026-09-18 → GREEN after S3/S4
`tests/checkout/stripe-order-page-fresh-session-iframe.spec.ts` (E2E submodule). Fresh context,
login, basket, payment (Stripe), `cl=order`. Asserts the option-B contract, logs every observation
(host data-values, button/sheet state, console errors, session calls), then reloads the same
session to prove the eager path. First run RED on: button visible, no sheet on tick path, 14 CSP
console errors.

### S1 / S2 — Server ✅ dropped (option B)
`ensureAgbAccepted()` and the 400 guard stay exactly as they are. No `confirmAgb` action.

### S3 — Template: iframe mode never paints the button ✅
**RED:** `tests/Integration/Checkout/OrderPageIframeConsentTemplateTest.php` (4 tests; renders the
real Twig with probe `oView`/`oViewConf` — a context variable shadows the `oViewConf` global, so the
iframe flag is under test control). Fresh+iframe → button `hidden`+`disabled`, `eager=false`,
`mount-on-consent=true`, hint rendered · consented+iframe → `hidden`, `eager=true`, no hint ·
AGB off+iframe → eager, no hint · redirect → visible button, no hint.
**GREEN:** `order.html.twig`: `mountOnConsent = stripeIframe and agbGating`; button `hidden` whenever
`stripeIframe`, `disabled` when `mountOnConsent` (so the JS can never read "payable" before
agb-validation connects); new `data-order-submit-mount-on-consent-value`; `alert-info` hint
`data-order-submit-target="consentHint"` (`role=status aria-live=polite`); translations
`STRIPE_AGB_CONTINUE_TO_PAYMENT` (en/de).
**Gotcha:** the tests only went green after `rm -rf var/cache/*` — compiled Twig is cached there.
The first RED→GREEN attempt also exposed a test bug: `[^>]*` stops at the `>` inside
`data-action="click->…"`; the extractor now respects quoted values.

### S4 — JS: mount when the checkout becomes payable ✅
`resources/build/js/controllers/order_submit_controller.js`: `connect()` branches on render mode →
`autoMountEmbedded()` when `eager`, `armConsentMount()` when `mountOnConsent`.
`armConsentMount()` observes the hidden button's `disabled` attribute (MutationObserver) and syncs
once; `mountWhenPayable()` mounts exactly once (`_mounting` guard, observer disconnected), hides the
hint, calls the existing `handleStripeCheckout()` (which appends `ord_agb=1` from the ticked
checkbox), reveals the button as fallback on failure — the same fallback the eager path has.
`disconnect()` releases the observer. Signal choice = the OPC footer's: the button's disabled state
is where every requirement ends up. Bundles rebuilt (`build:prod` + `build:dev`); the admin bundle's
minifier-rename noise was reverted to keep the diff honest.

### S5 — E2E aligned ✅
`stripe-order-page-iframe.spec.ts`: the "AGB-gated → visible button" branch was the bug; it now
asserts hint + no sheet, then tick → sheet, button hidden. `stripe-checkout.spec.ts`: the gated
branch no longer clicks; it asserts the button is hidden in iframe mode. `stripe-eager-mount-single-session.spec.ts`:
ticks AGB (a fresh session never mounted before), still exactly one session call. All GREEN.

### S6 — Dead CSP `<meta>` removed ✅
**RED:** `tests/Integration/Frontend/BaseJsTemplateTest.php` renders
`@oe_payments_stripe_wallet/frontend/base_js.html.twig`: must load `js.stripe.com/v3/`, must not
contain `Content-Security-Policy`. **GREEN:** the `<meta>` and its SRI comment replaced by a
comment saying why there is none. Console errors on the checkout: 14 → 0 (measured by S0).

### S7 — Wrap-up ✅
CHANGELOG `[Unreleased]` · status.md · [completion report](../reports/02-sprint-137-completion.md).

---

## 3. Commits

| # | Commit | Repo |
|---|---|---|
| 0 | `test(e2e): prove a fresh session gets the button instead of the sheet in iframe mode` | submodule (`3764d37`, branch `fix/iframe-fresh-session-spec`) |
| 1 | `docs(sprint-137): research and plan …` | stripe |
| 2 | `fix(checkout): mount the Stripe sheet when Terms are ticked instead of painting the button` | stripe |
| 3 | `fix(frontend): drop the CSP meta tag browsers were ignoring` | stripe |
| 4 | `test(e2e): iframe mode mounts the sheet on the Terms tick, never a button` | submodule |
| 5 | `docs(sprint-137): completion, changelog, e2e pointer` | stripe |

## 4. Risks, and how each was closed

- **Mount before agb-validation connects** → would 400 and reveal the button. Closed: the server
  renders the button `disabled` in mount-on-consent mode; the JS reads only that attribute.
- **Two mounts per page** (observer fires twice, or tick/untick/tick) → `_mounting` + `_embeddedCheckout`
  guards, observer disconnected on first mount; `stripe-eager-mount-single-session.spec.ts` GREEN.
- **Redirect-mode regression** → pinned by `testRedirectModeKeepsTheVisibleButton` and the redirect
  branch of `stripe-order-page-iframe.spec.ts`.
- **Untick after mount** → sheet stays, as in today's eager path (consent already persisted server-side).
