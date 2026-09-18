# Status — 2026-09-18

**Branch:** `fix/iframe-fresh-session-no-button` (stripe) · E2E submodule branch `fix/iframe-fresh-session-spec`

## IFRAME-05 — iframe mode shows the button in a fresh session
- ✅ **Reproduced live** with Playwright in a fresh context against `daniil.oxiddev.de`
  (`tests/checkout/stripe-order-page-fresh-session-iframe.spec.ts`, RED as intended).
  Fresh session: `eager=false`, `priorConsent=false`, button visible+disabled, 0 iframes, no session
  call. After tick+click: sheet mounts. Same session reloaded: `eager=true`, sheet on load.
- ✅ **Root cause:** template gates the eager mount on persisted AGB consent
  (`eagerIframe = iframe and not agbGating`) because `createCheckoutSession` answers 400 without
  `ord_agb=1`. Design decision from IFRAME-02f, not a runtime failure. Report:
  [reports/01](reports/01-iframe-fresh-session-shows-button-research.md).
- ✅ **Console:** 0 page errors, 0 module JS errors. **14 CSP errors** per checkout from the
  `<meta http-equiv="Content-Security-Policy">` in `base_js.html.twig` (rendered in body → ignored).
  Rest of the console is theme preload / Stripe dashboard-config warnings.
- 📝 **Sprint 137 written:** [sprints/sprint-137](sprints/sprint-137-iframe-fresh-session-mounts-the-sheet.md).
  Decision taken by assumption: **A — veiled eager mount** (sheet on load, veiled until Terms ticked,
  consent recorded via new `fnc=confirmAgb`). Alternative B (mount on tick) documented with the exact
  story deltas. **Awaiting Daniil's confirmation of A vs B before S1.**
- ⏭ Next: confirm decision → S1.
