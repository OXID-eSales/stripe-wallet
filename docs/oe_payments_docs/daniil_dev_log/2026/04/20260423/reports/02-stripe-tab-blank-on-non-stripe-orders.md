# Task 2 — Stripe tab shows blank for non-Stripe orders (2026-04-23)

## Reported symptom

User: "Stripe tab is not working anymore at all. only paypal tab
works. Order #689 should have Stripe tab — it is a stripe order,
but when I click nothing happens."

## Diagnosis

Ran the existing `tests/admin/stripe-admin-order.spec.ts`
Playwright spec against `daniil.oxiddev.de`. The test correctly
**opens** the Stripe tab (`✓ Opened Stripe tab`) but the
transaction-ID assertion fails with `Received string: ""`.

Failure screenshot shows the Stripe tab is active (underlined),
but the content area below the tab-bar is blank — no cards, no
table, no "no data" message.

DB inspection of the order the test picked:

```
OXORDERNR | OXPAYMENTTYPE      | OXPROVIDER (oe_payments_contract)
689       | oe_payments_paypal | paypal
```

Order 689 is a **PayPal** order in both `oxorder` and the
`oe_payments_contract` table — not a Stripe order. `isStripeOrder()`
in `OrderRefund.php:244-253` correctly returns `false`, which
matches how the template gates its content:

```twig
{% if oView.isStripeOrder() == true %}
    {# all cards, transaction table, forms #}
{% endif %}
```

→ On non-Stripe orders the `<div class="stripe-admin">` wrapper
rendered empty. Tab switches correctly, fetches, renders — and
shows nothing. That matches the user's "nothing happens" report,
even though functionally the tab is doing exactly what it was
told to do.

The PayPal equivalent template already handles this case —
`paypal_order_refund.html.twig:71-75` renders a
`PAYPAL_NOT_A_PAYPAL_ORDER` notice in the `{% else %}` branch.
Stripe didn't have the equivalent branch, so the user saw a
blank tab and assumed the tab was broken.

## What changed

**`views/twig/admin/stripe_order_refund.html.twig`** — added the
missing `{% else %}` branch:

```twig
{% else %}
    <div class="s-alert s-alert-info" data-testid="stripe-not-a-stripe-order">
        {{ translate({ ident: "STRIPE_NOT_A_STRIPE_ORDER" }) }}
    </div>
{% endif %}
```

**`views/admin_twig/{en,de}/stripe_lang.php`** — added the
`STRIPE_NOT_A_STRIPE_ORDER` translation entry:

- EN: "This order was not paid with Stripe."
- DE: "Diese Bestellung wurde nicht mit Stripe bezahlt."

## Why this is the right fix

- **Symmetry with PayPal** — both modules' admin tabs now
  explain themselves when the user clicks them on an
  incompatible order.
- **No functional change** — the Stripe tab still only renders
  its payment/refund/capture UI for real Stripe orders. The
  else branch just surfaces a message instead of blank HTML.
- **Minimal surface area** — template edit + two lang keys,
  no controller changes, no route changes. Can't regress
  anything.

## What this is NOT

This change does NOT attempt to change the definition of "Stripe
order". `isStripeOrder()` remains a payment-type check on
`oxorder__oxpaymenttype`. If the user intended order 689 to be a
Stripe order but the OPC checkout flow mis-tagged it as PayPal,
that's a separate OPC-side bug — not addressable by a template
edit. The friendly message makes the current state legible.

## Verification

- Rendered a known PayPal order (`55ebb70f191d472f…`) through the
  controller: `isStripeOrder: NO`, output contains the new
  `data-testid="stripe-not-a-stripe-order"` element.
- Rendered a known Stripe order (`1114c0775ef0…`):
  `isStripeOrder: YES`, full card/table/form UI still renders
  (58 stripe-admin class hits, 26 KB output).
- `./bin/pre-commit-check.sh` → all green, no test regressions.

## Root-cause investigation on order 689

User believed 689 was a Stripe-paid order. All three authoritative
records say otherwise — it is a PayPal order end-to-end:

| Source | Field | Value |
|---|---|---|
| `oxorder` | `OXPAYMENTTYPE` | `oe_payments_paypal` |
| `oe_payments_contract` | `OXPROVIDER` | `paypal` |
| `oe_payments_contract` | `OXPROVIDERORDERID` | `0DG468370M033530W` (PayPal token) |
| `oe_payments_contract` | `OXMETADATA.payment_id` | `oe_payments_paypal` |
| `oe_payments_contract` | `OXMETADATA.paypal_approval_url` | `https://www.sandbox.paypal.com/checkoutnow?token=0DG468370M033530W` |

The `paypal_approval_url` field is dispositive — only PayPal's
checkout flow writes a PayPal-sandbox approval URL into contract
metadata. No Stripe code path writes that key.

This was not a mis-tagging or unified-return-chain bug. The
preceding four orders (685, 687, 688, 689) in the test run were
all PayPal. Orders 680, 682, 683, 684 (earlier that day) were
Stripe and kept their Stripe tagging. The unification epoch's
shared handler chain preserves each PSP's own `OXPROVIDER` —
verified by contract 684 still showing `provider=stripe,
providerOrderId=pi_3TOypMRKy8lrhVfC0oGQGojh`.

## Also confirmed: PayPal tab hides on non-PayPal orders

Rendered the PayPal admin tab for a Stripe-paid order
(`1114c0775ef0…`, order 684):

- `isPayPalOrder()` returned `false`.
- Output contains the `PAYPAL_NOT_A_PAYPAL_ORDER` friendly
  notice.
- No `paypal-card` / `paypalOrderId` / `PAYPAL_CAPTURED`
  markup rendered.

PayPal's template already had the correct `{% else %}` branch
(since Sprint D). This task only added Stripe's missing
equivalent.

## Follow-ups

- The shipped Playwright spec `stripe-admin-order.spec.ts`
  picks "the first order matching customer name Marc", which
  the test environment now sometimes resolves to a PayPal order.
  The spec should be changed to pick the first order whose
  `OXPAYMENTTYPE = oe_payments_stripe_wallet` — unrelated to
  this fix, but worth filing.
- `stripe-loading-indicator.spec.ts` still asserts on the
  now-removed loading overlay (see Task 1 report). That spec is
  obsolete and should be deleted in a follow-up.
