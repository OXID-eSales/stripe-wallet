# Status — 2026-06-22

## Floating-point math code review + §5 extractions ✅

**Review:** `reports/02-floating-point-math-code-review.md` — exhaustive inventory of every monetary /
floating-point operation in payment-base + stripe, its test coverage, extraction targets, and the
BCMath assessment. Verdict: keep integer minor units for the Stripe wire path; extract & de-duplicate
the OXID-float math into tested units (a BCMath rewrite is not warranted).

**Implemented the §5 extraction backlog (TDD throughout):**
- **§5.1 `LineItemAmount`** (payment-base `Math\Money`) — `unitPrice × qty` / `net × qty` / `vat × qty`
  pulled out of `ContractService::extractProductItems()` into a pure, tested VO. 9 tests.
- **§5.2 `MinorUnitConverter`** (payment-base `Math\Money`) — canonical currency-aware major↔minor
  converter. Replaced the two duplicated, currency-blind `(int) round($x*100)` helpers in the ACP/UCP
  MCP formatters (latent JPY/BHD bug fixed). stripe `AmountConverter` now **delegates** to it,
  dropping the duplicated currency lists; its characterization suite is the parity net. 9 tests.
- **§5.3 `Money`** (payment-base `Math\Money`) — single `HALF_CENT_EPSILON` + `equals/greaterThan/
  atLeast/atMost`. Collapsed the three private `0.005` copies in `CaptureRefundTracker`,
  `RefundIntentHandler`, and stripe `CaptureService` into it. 20 tests.
- **§5.4 `CapturableAmount`** (stripe `Service`) — pure `remaining()` + `isExceededBy()` extracted
  from `CaptureService`'s inline over-capture guard. 13 tests.

**Behaviour:** all changes behaviour-preserving (additive in payment-base; no signature changes).

**Gates (all green):** payment-base Unit **1097** (was 1054) · payment-base Integration **85** (1 skip,
via `tests/phpunit-integration.xml`) · stripe Unit **1296** · stripe Integration **87**.
PHPCS 0 · PHPStan 0 (max) · PHPMD 0 new (no suppressions added) — both modules.

**Artifacts:**
- Sprint: `sprints/sprint-129-strp-xxx-floating-point-math-extractions.md`
- Report: `reports/02-floating-point-math-code-review.md`

**Open items:**
- §5 done; report action item #6 (string/BCMath-backed `Money` VO) remains **deferred** — only revisit
  if a concrete decimal defect surfaces in the OXID-float VAT path.
- **Not committed** — held awaiting a ticket number / explicit go. Two repos to commit (payment-base is
  a separate git repo): payment-base = new `Math\Money` units + MCP/tracker/handler edits; stripe =
  `AmountConverter` delegation + `CapturableAmount` + `CaptureService`.

---

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
