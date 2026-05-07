# 2026-05-06 — Stripe module dev log

_Continues from `../20260505/`._

| # | Task | Scope | Status | Landed |
|---|---|---|---|---|
| 1 | Diagnose why "Retour starten" on a captured / authorized Stripe order does NOT trigger an automatic refund or cancel-authorization. Drive the customer side via a new Playwright spec, capture the gap in `reports/01-…md`. | `stripe`, `opalreturns`, `payment-component` | ✅ Diagnosed — opalreturns dispatches `ReturnRequestedEvent` on customer initiation; the only listener is `ReturnRequestedEmailListener`. The Stripe refund pipeline (`PaymentComponentRefundBrokerListener` → broker → Stripe) is wired to `ReturnRefundRequestedEvent`, which is dispatched only from the admin **Resolved** transition. There is no cancel-authorization fan-out at all. | 2026-05-06 |
| 2 | Sprint 94 — TDD lock-down of the admin Approve / Resolve → Stripe-refund event chain in opalreturns. Three new test files (controller wiring, Approve-does-not-emit-refund negative invariant, controller-to-Stripe end-to-end). One small production refactor allowed (extract `Registry::getRequest()` seam). | `opalreturns` | ✅ Landed — +13 unit, +3 integration tests; full pre-commit green; plan in `done/sprint-94-admin-approve-refund-event-tdd.md`, completion report alongside. Test totals: 246 unit + 7 integration = 253 (+16 net). | 2026-05-06 |
| 3 | Fix runtime crash on admin "Approve": `TCPDF` class not found in `PdfTemplateInstructionProvider`. The module relied on TCPDF without declaring it as a composer dependency. Added `tecnickcom/tcpdf: ^6.6` to `extensions/opalreturns/composer.json` and re-installed. | `opalreturns` | ✅ Landed — class is now loadable; root `composer.json` was kept clean (the require lives in the module that uses it) | 2026-05-06 |
| 4 | Fix `oxv_oxdeliveryset_1_en.oxactive` 1054 on admin OrderMain. `Registry::get(DeliverySetList::class)` is a per-process singleton; its lazy `_oBaseObject` cached an EN view-name and core's `getFilterSelect()` then mixed `_de` (FROM) and `_en` (WHERE) view names in the same SQL. Fix in OPC's `DeliverySetList` override: rebind base-object language to current request language at `getFilterSelect()` boundary. | `one-page-checkout` (root cause in OXID core) | ✅ Landed — fix + 3 regression tests in OPC; details in `reports/02-…md` | 2026-05-06 |
| 5 | Fix "Start return" button missing on fresh shipped orders. `PaymentComponentDataProvider` collapsed "no contract row" and "row exists with NULL captured amount" to the same `null` semantics in code, but cast PHP `null` to `(float) 0.0` in practice — making `ReturnEligibilityService` flag committed-but-not-yet-captured orders as `fully_refunded`. Adapter now returns `null` for both `false` and `null` from `fetchOne()`, plus DRY'd the two near-identical methods into one. | `opalreturns` | ✅ Landed — order 48 now `eligible=true`; +7 unit tests; full pre-commit green (260 tests). Details in `reports/03-…md` | 2026-05-06 |
| 6 | Wire admin Resolve to **cancel-authorization** (not refund) for authorized-only orders. `PaymentComponentRefundBrokerListener` now picks `RefundRequestedEvent` vs `CancelAuthorizationRequestedEvent` based on contract state + captured amount. Stripe-side translator and handler already existed — this is the missing opalreturns wire from report 01 §"Cancel-authorization is even more orphaned". | `opalreturns` | ✅ Landed — +3 unit, +2 integration tests; full pre-commit green (265 tests). Details in `reports/04-…md` | 2026-05-06 |

## Legend
- ⬜ Not started
- 🟡 In progress
- ✅ Done
- 🚫 Blocked

## Summary

Customer-initiated returns currently do not move the Stripe payment.
opalreturns models the flow as an admin RMA process: the customer click
just creates a `Requested` record + e-mail; the Stripe refund only
runs at the very end, when the admin walks the workflow to
`Resolved`. A captured order therefore stays captured, and an
authorized-only order keeps its authorization, until the admin acts.

`extensions/stripe/src/Stripe/Service/CancelAuthorizationServiceInterface.php`
already exists, but no event from opalreturns ever reaches it.

Full trail in
[`reports/01-return-start-does-not-trigger-stripe-refund-or-cancel.md`](reports/01-return-start-does-not-trigger-stripe-refund-or-cancel.md).

## Pending
- Decide whether to auto-resolve `credit` returns at `Requested` time
  (settings-gated), or to keep the admin RMA gates and instead expose
  a one-click "auto-refund this return" admin action.
- ~~Add a `ReturnCancelAuthorizationRequestedEvent` + broker listener
  so manual-capture orders can be cancel-authorized via the same
  route.~~ ✅ Landed via task #6 (no new event class needed — re-uses
  the existing `CancelAuthorizationRequestedEvent` already in
  `payment-component`).
- Re-introduce the admin-side end of the e2e spec once admin/shop
  cookie isolation is sorted (or move the read-out to a dedicated
  admin-only spec that reuses an order created by a prior run).
- (`payment-component`) Add a `PaymentAuthorizationCancelledEvent`
  outgoing event from `StripeCancelAuthorizationRequestHandler` so
  the cancel-auth path has the same audit-trail closure as the
  refund path. Currently the return is moved to `Resolved`
  synchronously by `ReturnResolutionService` — sufficient for the
  state machine, but a follow-up event would let analytics /
  reporting listeners react to the cancel just like they do for
  refunds.

## Artifacts
- New spec: `tests/e2e/playwright/playwright/tests/admin/return-triggers-refund-or-cancel.spec.ts`
- Eligibility-gate screenshot: `reports/screenshots/return-form-not-eligible-because-not-shipped.png`
- Sprint 94 plan + completion report: `done/sprint-94-admin-approve-refund-event-tdd.md`, `done/sprint-94-completion-report.md`
- TCPDF dependency added to `extensions/opalreturns/composer.json`
- DeliverySetList language-mismatch fix: `extensions/one-page-checkout/src/Application/Model/DeliverySetList.php`
- DeliverySetList regression test: `extensions/one-page-checkout/tests/Integration/Application/Model/DeliverySetListLanguageMismatchTest.php`
