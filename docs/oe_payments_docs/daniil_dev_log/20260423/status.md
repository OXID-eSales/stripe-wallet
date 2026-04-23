# 2026-04-23 — Stripe module dev log

_Continues from `../20260422/` (opalreturns dev log under
`source/extensions/opalreturns/docs/dev_log/20260422/`). Previous
Stripe-side log was `../20260416/`._

| # | Task | Scope | Status | Landed |
|---|---|---|---|---|
| 1 | Remove loading spinner on the Stripe tab in admin order detail | `stripe` | ✅ Done | 2026-04-23 |
| 2 | Stripe tab shows blank on non-Stripe orders — render friendly notice | `stripe` | ✅ Done | 2026-04-23 |
| 3 | PayPal admin tab navigation is dead-end — missing `<form id="transfer">` and admin layout includes | `paypal` | ✅ Done — fix landed, OPC+PayPal E2E spec green end-to-end (order 706 saved) | 2026-04-23 |
| 4 | Stripe checkout completes but OXPAID not stamped (captureMode=manual) | `stripe` | ✅ Not a bug — manual-capture mode by design; Sprint-I spec covers visibility | 2026-04-23 |
| 5 | Sprint I — unified Payment admin tab owned by payment-component | `payment-component`, `stripe`, `paypal` | ✅ Commits 1–6 shipped (PC module + Stripe panel + PayPal panel + old `OrderRefund` tabs/templates/tests deleted + `payment-admin-tab.spec.ts` regression guard behind `PAYMENT_ADMIN_TAB_E2E=1` gate). Follow-up: env-prep script that reinstalls all three modules from `extensions/` on every CI run so the spec can run unconditionally. | 2026-04-23 |

## Legend
- ⬜ Not started
- 🟡 In progress
- ✅ Done
- 🚫 Blocked

## Core engineering requirements

Unchanged from the epoch invariants: **TDD-first**, **SOLID**,
**DI**, **DRY**, **Liskov**, **Clean Code** (15–25 line methods,
early returns, no `else`), **No overengineering**, **Drop
deprecated**, **pre-commit-check.sh --full green**.

## Task 1 — remove spinner on Stripe admin tab

**Motivation.** The Stripe tab in the admin order-details page
renders a full-screen fixed overlay with a purple rotating
spinner while the page loads. The overlay adds perceived latency
on an already fast-rendering page and has three tightly-coupled
pieces (CSS, HTML, JS with a `top.reloadEditFrame`
monkey-patch). Simpler to render the tab synchronously — the
data is already on the server by the time the template renders.

**Scope.**
- Remove the overlay HTML block in
  `views/twig/admin/stripe_order_refund.html.twig`.
- Remove the `.stripe-loading-overlay` / `.stripe-spinner` /
  `.s-content-loading` CSS.
- Remove the three-phase JS block (DOMContentLoaded reveal,
  form-submit re-show, `top.reloadEditFrame` monkey-patch).
- Drop the `s-content-loading` class application on the
  `stripeContent` wrapper.
- Remove the `STRIPE_LOADING` translation entry (en + de) —
  nothing else uses it.
- Leave `stripe-footer.html.twig` untouched — same class name
  but completely separate checkout 3DS overlay.

**Out of scope.**
- The customer-facing 3DS overlay in `stripe-footer.html.twig`.
- Any change to the Stripe tab's actual data-loading timing.

## Pending
- None (only one task queued today).
