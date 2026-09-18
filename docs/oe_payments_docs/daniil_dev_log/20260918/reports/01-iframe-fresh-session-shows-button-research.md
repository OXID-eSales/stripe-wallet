# Research — iframe mode shows the Place-Order button in a fresh session (IFRAME-05)

**Date:** 2026-09-18 · **Repo:** `extensions/stripe` · **Branch:** `fix/iframe-fresh-session-no-button`
**Reported by:** Daniil · **Shop under test:** `https://daniil.oxiddev.de` (Stripe test mode, iframe flag ON)
**Reproduced with:** Playwright, fresh browser context (= incognito),
`tests/checkout/stripe-order-page-fresh-session-iframe.spec.ts` (E2E submodule).

---

## 1. The report

> In iframe mode, when the session is new (incognito), the shopper sees the **button** on the order
> page instead of the iframe. Clicking the button makes the button disappear and the iframe appear.

## 2. Reproduction (live, 2026-09-18)

Fresh context → login as `playwright.user@oxid-esales.dev` → add "Wishbone aluminum" → address →
payment (Stripe) → `cl=order`. Observed straight after load (6 s grace for any eager mount):

| Observation | Fresh session (incognito) | Same session, page reloaded after one click |
|---|---|---|
| `data-order-submit-render-mode-value` | `iframe` | `iframe` |
| `data-order-submit-eager-value` | **`false`** | `true` |
| `data-agb-validation-enabled-value` | `true` | `true` |
| `data-agb-validation-prior-consent-value` | **`false`** | `true` |
| `#stripe-checkout-btn` | **visible**, `disabled`, title "Please accept the terms and conditions" | present but `hidden` |
| `#stripe-embedded-checkout` iframes | **0** | 1 |
| `createCheckoutSession` calls on load | **none** | 1 (HTTP 200, `client_secret` returned) |
| `#checkAgbTop` | present, unchecked | present, **checked** (restored from session) |

Clicking through: tick `#checkAgbTop` → button enables → click → one `createCheckoutSession`
call with `ord_agb=1` → 200 → sheet mounts inline → button hidden. Exactly the reported behaviour.

Screenshot of the fresh-session state (button under the Summary, no sheet): Playwright
`reports/test-results/…/test-failed-1.png` in the E2E submodule; run log at
`$CLAUDE_JOB_DIR/tmp/fresh-session-run.log` (copied to `reports/01-fresh-session-run.log`).

## 3. Root cause — this is a *design decision*, not a JS failure

Nothing fails at runtime. The server decides **up front** whether to eager-mount, in
`views/twig/extensions/themes/default/page/checkout/order.html.twig`
(`checkout_order_next_step_side`):

```twig
{% set agbGating   = oView.isConfirmAGBActive() and not oView.isPriorAgbConsent() %}
{% set eagerIframe = stripeIframe and not agbGating %}
```

- `isConfirmAGBActive()` = shop config `blConfirmAGB` → **ON** on this shop (`oxconfig`).
- `isPriorAgbConsent()` = session flag `stripe_agb_confirmed`
  (`ControllerRequestHelper::hasPersistedAgbConsent()`), written **only** by
  `StripeOrderController::ensureAgbAccepted()` when a `createCheckoutSession` request carries
  `ord_agb=1`. A fresh session has never made that request → `false` → `agbGating = true` →
  `eagerIframe = false` → the button is rendered visible and the JS `connect()` skips
  `autoMountEmbedded()`.

Why it was built that way (IFRAME-02f, 2026-07-29, `docs/dev_logs/daniil_dev_log/20260723/status.md`):
an eager mount without consent would hit the **400 guard** in `createCheckoutSession()` —
`ensureAgbAccepted()` rejects the request unless `ord_agb=1` is present — so the button was kept
as "the consent gate + trigger". That guard exists because in *redirect* mode the session creation
leads straight to Stripe's hosted page; consent had to be captured before it.

Consequence: the same shop renders **two different order pages** depending on whether the session
has already clicked once. Returning shoppers (and the developer who tested IFRAME-02f in a warm
session) see the sheet; every first-time / incognito shopper sees the button. The e2e spec
`stripe-order-page-iframe.spec.ts` is *adaptive* and accepts both branches, which is why it never
caught this.

**Not involved:** the OPC footer widget (`stripe-footer.html.twig`) has its own "mount when
payable" logic and is unaffected. No CSRF / stoken problem, no session loss, no JS exception.

## 4. Browser console — what a fresh checkout actually logs

Collected over the whole walk (home → login → product → basket → address → payment → order):

| Kind | Count | Message | Verdict |
|---|---|---|---|
| **error** | 14 | `The Content Security Policy 'script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://m.stripe.com;' was delivered via a <meta> element outside the document's <head>, which is disallowed. The policy has been ignored.` | **Ours.** `views/twig/frontend/base_js.html.twig:6` emits a CSP `<meta http-equiv>` inside `block base_js`, which apex renders in the `<body>`. Browsers ignore it — so the policy has **never** been in force, on every page where Stripe is active, and it logs an error on each. Fix in Sprint 137 S6. |
| warning | 1/page | apex `scripts.min.js` preloaded but not used | Theme, not ours. Ignore. |
| warning | 2/mount | `[Stripe.js] payment method types not activated: link` / Apple Pay domain not registered | Stripe Dashboard configuration, test mode only. Ignore. |
| warning | 1/mount | `Unrecognized feature: 'tools'` | Stripe's own iframe `allow` attribute. Ignore. |
| warning | 1 | WebGL GPU stall | headless Chromium. Ignore. |
| pageerror | **0** | — | No uncaught JS. |
| module JS `console.error` | **0** | no `[order-submit] eager embedded mount failed`, no `Order submission failed` | The button is a server decision, not a JS fallback. |

## 5. What "fixed" has to mean

In iframe mode the Place-Order button must **never** be the shopper's control, in any session
state. The payment sheet is the page's only pay control. AGB consent still has to be given before
the shopper can press Stripe's Pay, and still has to be recorded server-side.

Two ways to get there — see Sprint 137 for the decision and the plan:

- **A — veiled eager mount (recommended):** mount the sheet on load in every session; while
  `blConfirmAGB` is on and `#checkAgbTop` is unticked, cover the sheet with a veil ("Accept the
  terms above to unlock payment"); ticking records consent via a small AJAX action and lifts the veil.
  Matches the reported expectation ("I want to see the iframe"). Needs the 400 guard relaxed for
  iframe mode + a consent endpoint.
- **B — mount on consent:** no button, no sheet until `#checkAgbTop` is ticked; the tick triggers
  the (consent-carrying) session creation. Smaller change, no server change, but the fresh-session
  shopper still sees *no* payment form until they tick.
