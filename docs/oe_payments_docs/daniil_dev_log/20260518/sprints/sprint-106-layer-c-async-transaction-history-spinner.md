> **🧊 FROZEN — 2026-05-18**
>
> Over-engineered for the actual operator pain point. This sprint
> splits the panel into a synchronous skeleton + async-loaded
> fragment with a new admin XHR endpoint, a fragment template, a
> JS controller, and bidirectional state sync — to solve "first
> paint feels slow on cold blob" and "operator can fire double
> mutating actions before page reload."
>
> Sprint 107 (busy overlay) addresses the in-flight-action UX gap
> directly with ~80 lines of CSS + JS, no new endpoint, no
> architecture change. After Sprint 104's API-call collapse, the
> first-paint latency is no longer an operator complaint.
>
> Resume only if a future requirement reintroduces both pains
> together (e.g. a dashboard that opens the panel inside an iframe
> on a slow connection).
>
> Plan is kept intact for that scenario.

# Sprint 106 — Stripe panel: async transaction-history fragment with spinner

**Module:** `extensions/stripe`
**Mode:** single atomic commit. One new admin XHR endpoint, one
Stimulus controller, one template change, and the Playwright
acceptance harness from the latency report §1.1.
**Gates on:** Sprints [104](sprint-104-layer-a-dedup-stripe-api-calls.md) and [105](sprint-105-layer-b-contract-snapshot-blob-cache.md). 106 works
without 105 (and provides UX value either way), but the spinner is
shown on every revisit when 105 is absent; with 105 in place the
spinner appears only on rare cold-blob renders.
**Trigger:** [report `02-stripe-payment-tab-latency.md`](../reports/02-stripe-payment-tab-latency.md) — Layer C.

## 1. Why

After Sprints 104 and 105, the cold first render of a Stripe-paid
order still pays for one Stripe API round-trip — and that round-trip
runs **before** the panel HTML is streamed. From an operator's
point of view the entire tab "hangs" for ~150 ms even though all
the panel needs synchronously is local DB data (contract id,
payment type, transaction id, OXID amounts).

Sprint 106 splits the panel render in two:

- **Synchronous render** — everything that comes from the order /
  contract row directly. Zero Stripe calls, zero blob reads, ~30
  ms total.
- **Asynchronous fragment** — the transaction-history table and the
  amount fields that need fresh PSP data (Factual Captured Amount,
  Refunded Amount, refund-form `max`/`min`). Loaded via XHR after
  page paint. Spinner shows during the fetch; on success the
  fragment replaces the placeholder.

The Refund and Capture forms stay disabled until the fragment
loads — operators cannot submit an action against unknown PSP
state. This matches the existing UX for the "API error" code path
(which already disables the forms when the Stripe fetch fails).

## 2. Goals

- **G1.** Panel HTML reaches the browser without any Stripe API
  call on the server. Server-render time ≤ 100 ms.
- **G2.** Transaction-history table and PSP-derived amounts arrive
  via a single XHR to a new admin endpoint:
  `?cl=StripePanelAsyncController&fnc=fetch&orderid=<oxid>`.
  Returns either an HTML fragment (rendered by a small Twig
  template) or a JSON shape — pick one and defend the choice in
  the sprint review; default proposal is **HTML fragment** for
  simplicity (no JS-side templating, can copy the existing
  table markup verbatim).
- **G3.** Spinner is visible from the moment the panel paints
  until the fragment loads (typical ≤ 200 ms after Sprint 105 is
  in place; up to ~500 ms on a cold blob).
- **G4.** Refund / Capture / Cancel buttons disabled until
  fragment is loaded. They re-enable based on the fragment's
  `data-is-refundable` / `data-is-capturable` flags.
- **G5.** Playwright spec from latency-report §1.1 lands as
  `tests/e2e/playwright/playwright/tests/admin/stripe-tab-latency.spec.ts`
  with the threshold tightened to 300 ms on the `stripe panel
  painted` segment (now measuring synchronous render only).
- **G6.** `./bin/pre-commit-check.sh --full` green.

## 3. Scope inventory

| File | Concern |
|---|---|
| `src/Stripe/Controller/Admin/StripePanelAsyncController.php` (new) | One `fetch()` action. Validates the order id, instantiates the existing `StripePanelViewDataBuilder`, returns either an HTML fragment or JSON. |
| `views/twig/admin/panel/stripe_panel.html.twig` | Wrap transaction-history table + PSP-derived amount fields in `<div data-async-fragment data-fragment-url="...">`. Add `<div data-stripe-panel>` wrapper for the Playwright selector. Add `data-flash="success"` to the existing success banner. |
| `views/twig/admin/panel/stripe_panel_fragment.html.twig` (new) | The asynchronously-rendered fragment — extracted from `stripe_panel.html.twig` lines 233–260 (amount card) + 263–360 (transaction history). |
| `out/twig/admin/css/stripe_panel.css` (or similar — locate via the existing CSS bundling pattern) | Add `.stripe-spinner` styling. Inline SVG; no new asset dependency. |
| `out/twig/admin/js/stripe-panel-async.js` (new) | Minimal Stimulus / vanilla-JS controller. On `DOMContentLoaded`, find every `[data-async-fragment]`, fetch its `data-fragment-url`, replace innerHTML, re-enable any `[data-needs-async-data]` buttons. |
| `metadata.php` | Register `StripePanelAsyncController` in the `controllers` array. |
| `services.yaml` | Register the new controller; constructor-injects `StripePanelViewDataBuilder` and the `Order` repository the existing admin controllers use. |
| `tests/Unit/Stripe/Controller/Admin/StripePanelAsyncControllerTest.php` (new) | Pins the endpoint behaviour: returns 200 + fragment for a valid id; returns 404 for an unknown id; returns the API-error shape when the underlying builder reports an error. |
| `tests/e2e/playwright/playwright/tests/admin/stripe-tab-latency.spec.ts` (new) | The harness from the latency report §1.1, wired to the CI `admin-tests` project. |

### Explicitly *not* touched

- The synchronous portion of `stripe_panel.html.twig` (contract id,
  payment type, transaction id row — those stay in the static
  render).
- The Capture and Refund admin endpoints — they still take the
  same form posts.
- The webhook handlers, the contract state machine, the cache
  service from Sprint 105.
- `OrderRefundViewDataProvider` and the `Order` extension —
  Sprints 104/105 already finished their work.

## 4. Design

### 4.1 The split

Synchronous, rendered server-side at panel paint:

- Contract ID, Order ID, Payment type, Stripe Transaction ID,
  Order currency
- Static "Stripe Dashboard" link (no API needed)
- API-error placeholder (hidden by default)
- Spinner placeholder for the async fragment

Asynchronous, rendered by the XHR fragment:

- Factual Captured Amount
- Refunded Amount + the "has refunds" indicator
- Capturable Amount + Capturable raw
- Refundable Amount + Refundable raw
- isOrderCapturable / isOrderRefundable / isCancellable flags
  (rendered as data-attributes on the buttons so the JS can
  enable/disable them)
- Transaction History table

### 4.2 The XHR contract

Request:

```
GET /admin/index.php?cl=StripePanelAsyncController&fnc=fetch
                    &orderid=<oxid>&stoken=<csrf>
```

Response (HTML fragment, ≈ 4–8 KB):

```html
<div class="stripe-amounts">
  <p>Factual Captured Amount: <strong>100,00 EUR</strong></p>
  ...
</div>
<table class="stripe-tx-history">
  ...
</table>
<script type="application/json" data-fragment-flags>
{"isCapturable":false,"isRefundable":true,"isCancellable":false}
</script>
```

The trailing `<script type="application/json">` carries the
button-enable flags so the JS controller doesn't have to parse
the HTML. This is the same pattern OXID uses elsewhere for
controller-to-JS hand-off.

### 4.3 Error and disabled states

- **API failure (provider reports `hasApiError`).** Fragment
  renders an inline error notice and the buttons remain disabled.
  Same UX as today's "API error" branch.
- **Timeout (XHR > 10 s).** JS shows a "retry" link. Buttons stay
  disabled.
- **Already-failed contract.** Fragment short-circuits the
  amount calls; renders only the transaction-history table.

### 4.4 Why one endpoint and not two

A single fragment endpoint is simpler than per-card endpoints
(amounts vs. history). The data sources are coupled — both come
from the same expanded PaymentIntent — so splitting the endpoint
would just multiply the PI fetch (Sprint 105's snapshot cache
makes that a no-op, but the round-trip still happens).

## 5. The five pillars

### 5.1 SOLID

- **SRP.** `StripePanelAsyncController` does one thing: turn an
  order id + CSRF token into a fragment HTTP response. It does
  not own the panel's domain logic — it delegates to the
  existing `StripePanelViewDataBuilder`.
- **OCP.** Adding a new field to the async fragment means
  extending the Twig template and the data-fragment-flags JSON.
  No controller or JS change.
- **LSP.** No class hierarchy changes.
- **ISP.** The new controller depends on the *existing*
  builder interface, not on the wider `OrderRefundViewDataProvider`
  surface.
- **DIP.** Controller receives builder + repo via constructor DI.
  The JS controller depends on `data-*` attributes, not on a
  specific markup shape — fragment authors can rearrange the
  HTML freely.

### 5.2 TDD

Walking order:

1. **Endpoint red.** Write `StripePanelAsyncControllerTest`. Four
   cases (§6.1). Red because the controller does not exist.
2. **Endpoint green.** Implement the controller. The view-data
   builder is reused as-is from Sprint 105.
3. **Template split.** Extract the fragment from the existing
   `stripe_panel.html.twig` into `stripe_panel_fragment.html.twig`.
   The static panel renders without the extracted block. Visual
   regression check: open a Stripe order in the admin, confirm
   the synchronous portion looks identical to today's full panel
   minus the lazy content.
4. **JS controller.** Write Playwright spec (§6.2) first. Red
   because the spinner placeholder doesn't exist yet. Implement
   the JS controller. Spec turns green.
5. **Pre-commit gate.**

### 5.3 DRY

The amount fields, the transaction history table, and the
button-enable flags exist in **one** Twig template
(`stripe_panel_fragment.html.twig`), reachable from the panel
either synchronously (server-side include via the builder) or
asynchronously (XHR through `StripePanelAsyncController`). Grep
gate:

```bash
grep -c 'Factual Captured Amount' source/extensions/stripe/views/twig/admin/panel/
```

returns exactly 1 file match (the fragment template).

### 5.4 Liskov

The new controller's contract: given a valid order id + CSRF, the
response body is the fragment HTML on success or a JSON shape with
`error` and `code` keys on failure. The JS controller substitutes
cleanly with any backend that returns the same shape; this is the
substitutability hook for a future fragment cache.

### 5.5 Clean Code / DI

- One short controller (≤ 50 lines). Action method ≤ 25 lines.
- JS controller ≤ 80 lines of vanilla DOM code; no jQuery
  required (the admin still loads jQuery, but the new controller
  doesn't depend on it).
- Inline SVG spinner; no new asset.
- No `oxNew(StripePanelAsyncController::class)` anywhere — the
  controller is dispatched by OXID's `cl=` routing, per memory
  `feedback_oxid_no_oxnew_for_controllers`.

## 6. Test matrix

### 6.1 `StripePanelAsyncControllerTest`

| # | Test | Asserts |
|---|---|---|
| 1 | `testFetchReturnsFragmentForValidStripeOrder` | 200; body contains `<table class="stripe-tx-history">`; flags JSON parses |
| 2 | `testFetchReturns404ForUnknownOrderId` | 404; no PSP call attempted |
| 3 | `testFetchReturnsApiErrorFragmentWhenBuilderReportsError` | 200; body contains error notice; flags JSON `isRefundable:false` |
| 4 | `testFetchRejectsMissingCsrfToken` | 403; no PSP call attempted |

### 6.2 Playwright spec (`stripe-tab-latency.spec.ts`)

Wire to the existing `admin-tests` Playwright project. Body is
the spec from latency report §1.1, with two assertions:

- Soft: `stripe panel painted` segment ≤ 300 ms (synchronous
  render only).
- Soft: total `stripe tab clicked → spinner gone` ≤ 1500 ms
  (warm with Sprint 105 active); ≤ 3000 ms (cold).

The two thresholds are wired separately so a regression in the
async path doesn't mask a regression in the sync path.

### 6.3 Manual smoke

- Open a Stripe-paid order's Stripe tab. Spinner visible
  briefly, fragment replaces it. Refund button enables.
- Click Refund with 1.00 EUR. Success flash appears. Reload the
  tab. Updated amount and one new "Refund" row appear in the
  transaction history.
- Disable network mid-load (DevTools throttling). Verify the
  spinner stays put and the retry link appears.

## 7. Acceptance gates

- [ ] `./bin/pre-commit-check.sh --full` green. Test total ≥
      pre-sprint baseline + 4 (controller).
- [ ] PHPStan max: 0 new errors. PHPCS: 0 errors. PHPMD: 0 new.
- [ ] Playwright spec passes against staging with Sprint 105
      active: panel-painted segment ≤ 300 ms.
- [ ] DRY grep gate from §5.3 returns 1 file match.
- [ ] Manual smoke from §6.3, all three scenarios.
- [ ] No diff to webhook handlers, `CaptureService`,
      `RefundService`, the snapshot cache from Sprint 105.

## 8. Out of scope / explicit deferrals

- **Streaming the fragment via SSE.** Overkill for a 4–8 KB
  payload.
- **Pre-fetching the fragment on order-list hover.** Vetoed in
  the latency report §5 (recreates the original problem at
  N=hover-events).
- **Replacing the existing panel CSS with a redesign.**
  Out-of-scope cosmetic.
- **JS framework migration.** The new controller is plain
  vanilla DOM; if/when OXID admin migrates to Stimulus globally,
  the controller adapts trivially (the data-attributes are
  Stimulus-friendly already).

## 9. Risk register

- **Risk: XHR endpoint becomes a side-channel for unauth'd PSP
  data probing.** **Mitigation:** the controller requires the
  admin session cookie + the OXID CSRF token; same auth surface
  as the main panel.
- **Risk: spinner flashes for ≤ 50 ms on warm renders, feels
  janky.** **Mitigation:** show the spinner after a 100 ms
  delay so sub-100 ms responses paint the final state directly.
- **Risk: keyboard-only operators tab into the disabled Refund
  button before the fragment loads.** **Mitigation:** the
  spinner placeholder has `aria-busy="true"` and `aria-live="polite"`;
  screen readers announce when the fragment arrives.
- **Risk: the fragment endpoint runs in the OXID admin session
  context but the dispatched URL contains the `orderid` GET
  parameter — open-redirect / IDOR exposure?** **Mitigation:**
  no redirect path involved; the `orderid` is validated against
  the admin session's accessible-order set (same check OXID
  uses for the main panel — already present in the builder).

## 10. Done definition

- [ ] §7 acceptance, every box.
- [ ] Sprint markdown moves to `done/` with a completion
      report including the Playwright run output, a before/after
      Lighthouse-style screenshot of the panel paint, and a
      DevTools Network screenshot of the synchronous render
      showing zero Stripe API calls.
- [ ] `status.md` updated with the new Playwright project
      anchor and the final paint-segment number.
- [ ] Layer-A/B/C arc closed: a copy of the latency report's
      §6 rollout table is annotated with "delivered" against
      each layer.
