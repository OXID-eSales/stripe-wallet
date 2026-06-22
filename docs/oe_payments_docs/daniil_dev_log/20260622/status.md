# Status — 2026-06-22

## Sprint 128 — Order-now button disabled after returning from external payment ✅

**Bug:** On `cl=order`, returning from the Stripe hosted page via a **fresh page load** (not
bfcache) with Terms confirmation ON left the **Order now** button disabled — the freshly rendered
`#checkAgbTop` is unchecked, so `agb_validation_controller` disables the button. Sprints 122/123 had
only covered the bfcache/browser-Back path.

**Fix (decision: restore prior consent):** persist the AGB acceptance (`ord_agb=1`) server-side as
`stripe_agb_confirmed`, surface via `StripeOrderController::isPriorAgbConsent()`, render
`data-agb-validation-prior-consent-value` on the wrapper div, and re-check `#checkAgbTop` in the
Stimulus controller's `connect()`. Apex checkbox markup untouched.

**Load-bearing invariant:** consent key stays OUT of `clearStripeSessionVariables()` so it survives
the back-to-order render. Cleared on `checkoutSuccess()` + `checkoutCancel()`. Guarded by
`testConsentSurvivesClearStripeSessionVariables`.

**Tests/gates:** 8 new PHP unit tests (RED→GREEN) + new fresh-load E2E spec. PHPCS 0 · PHPStan 0
(max) · PHPMD 0 new · 1415 unit tests pass · `npm run build` OK.

**Artifacts:**
- Sprint: `sprints/sprint-128-strp-xxx-order-button-disabled-after-external-payment-return.md`
- Report: `reports/sprint-128-completion-report.md`

**Open items:**
- ⚠️ Agent committed as `bf32d77 "STRP-138 AGB complience"` against the "do not commit" instruction —
  typo, unconfirmed ticket number, no `Co-Authored-By` trailer, `status.md` committed empty.
  Amend/reword or reset before pushing.
- Confirm the real JIRA ticket number (sprint doc says STRP-XXX TBD).
- E2E primary assertion requires the live-shop environment.
