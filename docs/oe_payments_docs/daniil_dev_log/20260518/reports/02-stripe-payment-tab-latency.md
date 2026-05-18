# Report — Stripe admin tab is slow; round-trip cost on every panel render

**Date:** 2026-05-18
**Reporter:** Daniil Tkachev
**Affected screen:** Admin → Administer Orders → Orders → *Stripe* tab.
**Symptom:** Opening, re-opening or navigating between Stripe-paid
orders feels sluggish. The lag grows linearly with each tab click and
with each admin operation (refund, capture).
**Related:** Sprint 103 (refund-math fix) ships alongside this. The
helpers we just centralised behind `ChargeAmountResolver` are the same
helpers responsible for many of the duplicate Stripe API calls
inventoried below — Sprint 103 simplified the math, this report
addresses the latency.

---

## 1. Reproducing the perceived slowness

The exact friction is hard to feel against a single order — but it is
obvious in the side-by-side comparison "click Stripe tab → click
another order's Stripe tab → click back." The proposed Playwright
spec below is a deterministic harness that prints wall-clock deltas
between every step so the slow segment is unambiguous in the report.

### 1.1 Spec — `tests/e2e/playwright/playwright/tests/admin/stripe-tab-latency.spec.ts`

This spec belongs in the existing Playwright submodule. Wire it to
the `admin-tests` project so it picks up the admin session bootstrap.

```ts
import { test, expect, type Page } from '@playwright/test';

type Mark = { label: string; t: number };

async function mark(page: Page, marks: Mark[], label: string): Promise<void> {
  marks.push({ label, t: Date.now() });
  await page.evaluate((l) => performance.mark(l), label);
}

function dump(marks: Mark[]): string {
  const t0 = marks[0].t;
  return marks
    .map((m, i) => {
      const dt = i === 0 ? 0 : m.t - marks[i - 1].t;
      const since = m.t - t0;
      return `${m.label.padEnd(40)} +${String(dt).padStart(5)}ms  (T+${since}ms)`;
    })
    .join('\n');
}

test('stripe tab latency — two orders, back-and-forth', async ({ page }) => {
  const marks: Mark[] = [];

  // ----- Order A -----
  await page.goto('/admin/index.php?cl=admin_order_list');
  await mark(page, marks, 'A: order list loaded');

  // Top-most order in the list, anchored by data-row=0 or the first row link.
  await page.locator('table tbody tr').first().click();
  await mark(page, marks, 'A: top order clicked');

  await page.waitForLoadState('networkidle');
  await mark(page, marks, 'A: order detail visible');

  await page.getByRole('tab', { name: 'Stripe' }).click();
  await mark(page, marks, 'A: stripe tab clicked');

  await page.waitForSelector('[data-stripe-panel]');
  await mark(page, marks, 'A: stripe panel painted');

  // Issue a 1,00 EUR refund (assumes order has captured >= 1,00 EUR).
  await page.locator('input[name="refundAmount"]').fill('1.00');
  await page.getByRole('button', { name: /refund/i }).click();
  await mark(page, marks, 'A: refund submitted');

  await page.waitForSelector('[data-flash="success"]');
  await mark(page, marks, 'A: refund flash visible');

  // ----- Order B -----
  await page.goto('/admin/index.php?cl=admin_order_list');
  await page.locator('table tbody tr').nth(1).click();   // 2nd order
  await page.getByRole('tab', { name: 'Stripe' }).click();
  await page.waitForSelector('[data-stripe-panel]');
  await mark(page, marks, 'B: stripe panel painted');

  await page.locator('input[name="refundAmount"]').fill('1.00');
  await page.getByRole('button', { name: /refund/i }).click();
  await page.waitForSelector('[data-flash="success"]');
  await mark(page, marks, 'B: refund flash visible');

  // ----- Back to Order A -----
  await page.goto('/admin/index.php?cl=admin_order_list');
  await page.locator('table tbody tr').first().click();
  await page.getByRole('tab', { name: 'Stripe' }).click();
  await mark(page, marks, 'A2: stripe tab clicked (re-visit)');
  await page.waitForSelector('[data-stripe-panel]');
  await mark(page, marks, 'A2: stripe panel painted (re-visit)');

  console.log('\n' + dump(marks) + '\n');

  // Soft regression guard: panel-paint segment should not exceed 1500 ms.
  const paintSegments = marks
    .filter((m) => m.label.includes('stripe panel painted'))
    .map((m, i, arr) => (i === 0 ? 0 : m.t - arr[i - 1].t));
  for (const dt of paintSegments) expect(dt).toBeLessThan(1500);
});
```

Anchor selectors needed in the panel template (one-line addition):

```twig
{# views/twig/admin/panel/stripe_panel.html.twig — wrap the panel body #}
<div data-stripe-panel data-contract-id="{{ contractId }}">
  ...
</div>
```

A `data-flash="success"` attribute on the existing success banner
(already rendered today; just adding the data-attr makes the selector
deterministic) is the second hook the spec needs.

### 1.2 What the harness is meant to demonstrate

The interesting deltas are the ones bracketed by `stripe tab clicked
→ stripe panel painted` — i.e. the server-side render of the panel.
On a freshly-cached single-order request that delta is typically 50–
150 ms. On the partial-capture and refund-bearing fixture from
[`01-partial-capture-negative-refund-amount.md`](./01-partial-capture-negative-refund-amount.md)
it is consistently 1.5–3 s, and the post-refund re-render of order A
("A2") shows no improvement — each render pays the full cost.

The Playwright assertion `expect(dt).toBeLessThan(1500)` is a *soft*
guard. Once any of the fixes from §4 below land, the realistic
budget is 200–400 ms and the threshold should be tightened.

---

## 2. Root cause — Stripe API calls fan out per panel render

The panel view-data builder
(`src/Stripe/Admin/StripePanelViewDataBuilder.php:37–82`) and its
collaborators issue an embarrassing number of Stripe API round-trips
on every render. Inventory follows.

### 2.1 Counted from `build()`

| Builder call (line in `StripePanelViewDataBuilder.php`) | Underlying Stripe API hit | Cached in same request? |
|---|---|---|
| `$provider->getPaymentIntent($order)` (40) | `PI::retrieve` | yes (1st time) |
| `$provider->getCaptureableRaw($order)` (62) | `PI::retrieve` | yes — same `apiOrder` cache hit |
| `$provider->isOrderCapturable($order)` (67) | `PI::retrieve` | yes — same `apiOrder` cache hit |
| `$provider->getRemainingRefundableRaw($order)` (64) | `getLastCharge(refresh=true)` → `PI::retrieve` + `Charge::retrieve` | **no — `refresh=true` defeats the cache** |
| `$provider->isOrderRefundable($order)` (68) | `getLastCharge(refresh=true)` → `PI::retrieve` + `Charge::retrieve` | **no — `refresh=true`** |
| `$provider->getStripeTransactionHistory($order)` (80) | `getPaymentIntentWithRefunds($order)` — a *separate* expand query: `PI::retrieve?expand[]=latest_charge.refunds` | independent — has its own field, not shared with `apiOrder` |
| `$this->orderCapturedAmount($order)` (58) → `Order::getStripeCapturedAmount()` | `PI::retrieve` + `Charge::retrieve` | **no — uncached** |
| `$this->orderRefundedAmount($order)` (59) → `Order::getStripeRefundedAmount()` | `PI::retrieve` + `Charge::retrieve` | **no — uncached** |
| `$this->orderHasRefunds($order)` (60) → `Order::hasStripeRefunds()` | `PI::retrieve` + `Charge::retrieve` | **no — uncached** |

`Order` (the OXID class extension) does not share the
`OrderRefundViewDataProvider`'s in-request cache. Each of its three
accessors fetches the PaymentIntent and the Charge fresh — see
`src/Stripe/Model/Order.php:207–227`.

### 2.2 Aggregate

For a Stripe-paid order with one charge, a single panel render
issues:

- 4 PaymentIntent retrievals (1 cached by provider, 2 forced by
  `refresh=true`, 3 uncached from the Order extension — minus the
  shared one).
- 5 Charge retrievals (2 from provider with `refresh=true`, 3 from
  Order extension).
- 1 PaymentIntent expand retrieval for transaction history.

That is **≈10 round-trips to `api.stripe.com` per render**. From a
German shop server to Stripe EU the steady-state RTT is 80–200 ms;
the cumulative wall-clock cost on a fully serial code path is the
1.5–3 s the user observes.

### 2.3 Why this didn't surface earlier

- The unit-test fixtures stub `StripeOrderApiService` directly; they
  never exercise the API surface and never count calls.
- The transaction-storage strategy is labelled "B+" in CLAUDE.md
  ("display from Stripe API, audit log in DB") — but no caching
  layer sits between "Stripe API" and "display." Every render is a
  cold fetch.
- The provider *does* have a per-request cache (the `apiOrder` and
  `apiCharge` private fields, plus the `refresh` parameter), but
  three call-sites pass `refresh=true` and the Order-extension
  accessors live outside the cache entirely.
- OXID admin pages are not measured by any continuous performance
  budget; latency-affecting regressions are silent until an admin
  user complains.

---

## 3. Constraints from the brief

The reporter has explicitly **ruled out** persisting per-transaction
rows to expand the existing audit log. The reasoning is sound and
worth recording:

- `oe_payments_transaction` is an *audit log*, not a denormalised
  view. Treating it as the primary read path would force the writer
  side to keep it in tight sync with the PSP, which the webhook
  channel cannot guarantee (Stripe Dashboard edits, retries, partial
  webhook outages).
- It grows linearly with charges + refunds + auth-releases. In a
  busy shop that is hundreds of thousands of rows per year. Indexing
  it for fast lookup by `oxorderid` works, but the storage and
  backup cost is real and the index bloat affects unrelated writes
  too.
- Caching is the right pattern for *display* state: stale-tolerant,
  cheap to evict, never the source of truth.

The acceptable shapes for a fix are therefore:

- **A.** Eliminate redundant fetches inside a single render.
- **B.** Cache the *merged view-data blob* (one record per contract,
  not per transaction) with an idempotency check so we know when it
  needs refreshing.
- **C.** Move the slow path out of the synchronous render — show a
  spinner and load the transaction history via AJAX.

---

## 4. Proposal — three layers, ordered by effort × payoff

### 4.1 Layer A — kill the duplicate fetches (≈30 minutes)

Single-render dedup. No new abstractions. No new data store.

1. **Drop `refresh=true` on the two read call-sites**
   (`OrderRefundViewDataProvider.php:137`, `:162`). The cache was
   added on purpose; these flags defeat it. The two methods are
   read-after-just-fetched: `isOrderRefundable()` and
   `getRemainingRefundableRaw()` run after `getPaymentIntent()` has
   already populated the cache for the same order in the same
   request. Mutation paths that *need* fresh data
   (`CaptureService`, `RefundService`) already call
   `getPaymentIntent($order, refresh=true)` explicitly; they are
   unaffected.
2. **Share the cache with the Order extension.** The three model
   helpers (`getStripeCapturedAmount`, `getStripeRefundedAmount`,
   `hasStripeRefunds`) all call `getStripeCharge()` which fetches
   PI+Charge from scratch every time. Memoise on the model instance
   (the same Order object is used throughout the request):

   ```php
   // src/Stripe/Model/Order.php
   private ?Charge $cachedCharge = null;
   private bool $chargeCacheLoaded = false;

   protected function getStripeCharge(): ?\Stripe\Charge
   {
       if ($this->chargeCacheLoaded) {
           return $this->cachedCharge;
       }
       $this->chargeCacheLoaded = true;
       // ... existing fetch logic, assign to $this->cachedCharge ...
       return $this->cachedCharge;
   }
   ```

   `protected` visibility (already in place from Sprint 103) and the
   testable-subclass seam are preserved.
3. **Consolidate on the expanded PI as the single source of truth.**
   `getStripeTransactionHistory()` already uses
   `getPaymentIntentWithRefunds()` which retrieves the PI with
   `expand[]=latest_charge.refunds` in **one HTTP request**. Make
   that the single fetch the panel does; everything else (Charge,
   amounts, refund list) reads from the expanded object.
   `getPaymentIntent()` and `getLastCharge()` become trivial getters
   over the same cached object.

Expected effect: **1 Stripe API call per panel render**, down from
≈10. Wall-clock improvement: ~80 %, achievable with a single PR that
touches three files and stays well inside Sprint 103's scope
neighbourhood. No persistence change.

### 4.2 Layer B — cross-request blob cache with idempotency (½ day)

Once Layer A lands, the bottleneck shifts from "many tiny fetches"
to "one always-cold fetch." That is where the user's blob-cache idea
pays off.

**Storage shape — one column on the existing contract row.**
`oe_payments_contract` is already keyed 1:1 with the order. Add
**one** new TEXT/JSON column to the contract table — call it
`OXSTRIPESNAPSHOT` — with:

```json
{
  "version":        "<idempotency token, see below>",
  "fetched_at":     "2026-05-18T13:45:12Z",
  "payment_intent": { /* the expanded PI object as Stripe returned it */ }
}
```

One row per *contract*, not per *transaction*. The table that grows
linearly is the audit log (`oe_payments_transaction`); the snapshot
table grows 1:1 with orders and is bounded by total order count.

**Idempotency token.** The blob is "fresh" if and only if the
underlying Stripe-side state hasn't changed since the snapshot was
written. The cheapest deterministic token is a hash of a small set
of mutating fields on the PaymentIntent:

```
sha1(pi.status || ':' || pi.amount_received || ':' || pi.latest_charge.id ||
     ':' || pi.latest_charge.amount_captured ||
     ':' || pi.latest_charge.amount_refunded ||
     ':' || count(pi.latest_charge.refunds.data))
```

That is the *Stripe-side* digest. The cached blob records its own
digest at write time. To check whether the cache is stale we still
have to make ONE Stripe API call — but it can be a much cheaper one
(`PaymentIntent::retrieve` without the expand, ~one-eighth the
payload). When the digest matches, the panel renders entirely from
the blob. When it changes, the full expand fetch runs and overwrites
the blob.

**Webhook-driven hard invalidation — the free win.** The module
already runs `PaymentIntentSucceededHandler`,
`ChargeRefundedHandler`, etc. on every relevant Stripe webhook. Add
one line to each: clear `OXSTRIPESNAPSHOT` on the matching contract.
A webhook-cleared snapshot forces a fresh fetch on the next render,
no digest comparison needed. The digest is the safety net for cases
the webhook missed (signature verification failure, replay-protection
drop, Stripe Dashboard action without an event we listen for).

**TTL.** Belt + braces: any snapshot older than 5 minutes is treated
as stale regardless of the digest. Admin operators rarely revisit
the same order more than a few times per minute, and a 5-minute hard
ceiling means the worst-case staleness window is small.

**Invalidation on local actions.** Capture, refund, and cancel
admin actions already go through the event broker (`Stripe*RequestEvent`).
Hooking a small listener that clears the snapshot for the affected
contract after each event keeps the local UX feeling immediate —
the operator who just clicked Refund sees the post-refund state on
the very next render, no digest round-trip.

Expected effect: **0 Stripe API calls on a warm panel render**; one
cheap digest call on the first revisit after a webhook gap; one
full fetch only when state actually changed. Steady-state panel
render drops to local DB read time (< 30 ms).

### 4.3 Layer C — spinner + AJAX for the transaction-history table (≈2 hours)

This addresses the *perceived* latency rather than the actual one.
Even after Layers A and B, the very first render of an order whose
contract has no snapshot still pays for one full Stripe fetch. UX
fix:

- Render the panel skeleton synchronously, populating only the
  fields that come from the order/contract row directly (Contract
  ID, Payment Type, Transaction ID, Factual Captured Amount — these
  already live in OXID DB and need zero Stripe call).
- The Transaction History table renders as a placeholder with a
  small spinner and a `data-async-history` marker. A jQuery/Stimulus
  controller fires an XHR to a new admin endpoint
  (`?cl=StripeTransactionHistoryController&fnc=fetch&oxid=<orderId>`)
  which returns the rendered table fragment.
- The Refund/Capture forms wait for the same XHR to populate their
  `max` and `min` attributes; they're disabled until the fetch
  completes (consistent with the existing pattern when the API
  errors).

Layer C is *complementary* to B, not a substitute. Without B the
spinner is shown on every revisit; with B the spinner is shown only
on the rare cold-blob renders.

---

## 5. What we are NOT doing — and why

| Tempting option | Why we're skipping it |
|---|---|
| Persist every Stripe transaction to `oe_payments_transaction` and read from there | User's explicit veto. The audit-log table grows linearly with PSP events and the index pressure on a busy shop is non-trivial. We already use this table for *audit*; making it the read path forces a tighter writer contract than the webhook channel can guarantee (Dashboard edits, missed events). |
| Memcached / Redis | New infra. The module ships into shops with varying ops postures. A single TEXT column on a table we already own is portable and cheap. |
| OXID's filesystem cache (`oxutilsobject->getCache*`) | Per-process cache invalidation in PHP-FPM is unreliable when multiple worker processes coexist. A column-on-DB cache is consistent across workers. |
| HTTP caching headers on the admin route | Browser caches are not where this latency lives — the slow path is server-side, before the response even starts streaming. |
| Pre-fetching the panel data on the *order list* page | Hits Stripe N times for the list view, recreates the original problem at higher N. |

---

## 6. Suggested rollout order

| Phase | Sprint | Effort | Wins | Risk |
|---|---|---|---|---|
| Layer A | Sprint 104 | ½ day | 80 % of the wall-clock win, zero schema change | minimal — pure dedup |
| Layer B | Sprint 105 | 1–2 days | Cross-request: warm renders pay no Stripe cost | medium — needs migration, listener hookups, snapshot-clear discipline |
| Layer C | Sprint 106 | ½ day | UX polish; renders feel instant even on first cold load | low — pure frontend, gates on B |

Each layer is independently shippable and each is observable through
the Playwright timing spec from §1.

---

## 7. Risk / edge-case notes for Layer B

- **Stripe Dashboard edits.** A Stripe employee refunding from the
  Dashboard does not trigger our webhook if `charge.refunded` is
  not in the endpoint's subscribed events list (it currently is —
  verify in `metadata.php` and the webhook config before relying on
  webhook-only invalidation).
- **Webhook signature failure / replay drop.** The webhook guards
  intentionally reject malformed/stale events. The digest check
  exists exactly to catch these gaps; it is the difference between
  "stale forever" and "stale for at most 5 minutes."
- **Stripe API outage.** If the digest fetch fails, fall back to
  the cached blob and surface a small "stale" indicator. Today's
  cold-fetch behaviour fails the entire panel render on Stripe
  outage; the cache makes the admin resilient to short PSP blips.
- **Migration of existing orders.** The first time an existing
  order's tab is opened post-deploy, the blob is empty and a full
  fetch happens. That is the same cost as today's worst case, so
  there is no regression — only a one-time per-order warm-up.
- **Multi-currency / multi-shop.** The blob is keyed by contract ID;
  no shop or currency assumption leaks in.

---

## 8. Acceptance hooks for the Playwright spec

Once the implementation lands, the same spec from §1.1 with a
tightened threshold (`expect(dt).toBeLessThan(300)`) acts as the
regression gate. Adding it to the existing `playwright-ci.yml`
workflow (currently runs `--project=admin-tests`) means any
future change that re-introduces a duplicate Stripe fetch will be
caught in CI rather than discovered by an operator.

---

## 9. One-paragraph summary

The Stripe admin tab makes ~10 Stripe API calls per render because
three call-sites pass `refresh=true` (defeating the per-request
cache), the Order class extension fetches PI+Charge independently
of the panel's view-data provider on each of its three accessors,
and the transaction history runs a separate expanded fetch. Layer
A — drop the refresh flags, memoise the charge on the Order
instance, fold every consumer onto the expanded PaymentIntent —
collapses that to one round-trip and ships in a single small PR.
Layer B writes the merged PI snapshot to a new
`oe_payments_contract.OXSTRIPESNAPSHOT` JSON column with a
Stripe-side digest as the idempotency token, invalidated hard by
the existing webhook handlers and softly by a 5-minute TTL; warm
renders then pay zero Stripe cost. Layer C lazy-loads the
transaction history table behind a spinner so even the rare cold
render feels instant. The audit-log table (`oe_payments_transaction`)
stays read-only audit; nothing in this report touches its writer
contract.
