# Status — 2026-09-18

**Branch:** `b-7.4.x-iframe-05-fresh-session-sheet` (stripe, from `b-7.4.x`) · E2E submodule branch `fix/iframe-fresh-session-spec`

## IFRAME-05 / Sprint 137 — iframe mode showed the button in a fresh session — ✅ DONE
- ✅ **Reproduced live** with Playwright in a fresh context against `daniil.oxiddev.de`
  (`tests/checkout/stripe-order-page-fresh-session-iframe.spec.ts`, RED first).
- ✅ **Root cause:** template gated the eager mount on persisted AGB consent because
  `createCheckoutSession` 400s without `ord_agb=1` — an IFRAME-02f design decision, not a runtime
  failure. Report: [reports/01](reports/01-iframe-fresh-session-shows-button-research.md).
- ✅ **Decision:** option **B — mount on consent** (Daniil). No server change.
- ✅ **Fix:** in iframe mode the button is never painted; hint + mount-on-tick when consent is
  gated, eager mount otherwise. JS observes the hidden button's `disabled` attribute (OPC pattern).
- ✅ **CSP:** body `<meta http-equiv="Content-Security-Policy">` removed from `base_js.html.twig`
  — console errors per checkout 14 → 0.
- ✅ **Verified:** 4 e2e specs GREEN live (fresh-session, order-page-iframe, single-session,
  checkout EN+DE); Integration 98/98; PHPCS clean; PHPStan max 0.
- ⚠️ **Pre-existing, not ours:** PHPMD `TooManyMethods` on `StripeOrderController` (27>25); Unit
  suite aborts at collection on the Mollie `PaymentController_parent` chain crash with all PSPs
  active. Details in [reports/02](reports/02-sprint-137-completion.md).
- ✅ **Review follow-up:** "Creating checkout session…" is cleared once the sheet renders (spec-pinned).
- Sprint file: [done/sprint-137](done/sprint-137-iframe-fresh-session-mounts-the-sheet.md).
