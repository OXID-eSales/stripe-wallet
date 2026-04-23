# Task 3 — PayPal admin tab dead-ends navigation (2026-04-23)

## Reported symptom

User: "I click on PayPal tab, then I click on any other tab after
that — the tab header switches, but the iframe is not updated.
The PayPal content stays."

## Root cause

OXID admin tab switching is driven by JS on the parent
frameset:

1. Tab link click calls `top.oxid.admin.editThis(oxid)` or
   similar.
2. That code reads **`document.getElementById('transfer')` inside
   the edit frame** to get the current controller key and
   session id.
3. It updates the `cl` hidden input and submits the transfer
   form, causing the edit frame to reload at the new controller.

`source/extensions/paypal/views/twig/admin/paypal_order_refund.html.twig`
**did not emit a `<form id="transfer">`** and **did not include
the admin layout closes** (`bottomnaviitem.html.twig` +
`bottomitem.html.twig`).

Consequence: while the PayPal tab was loaded into the edit
frame, the frame had no `#transfer` element. Any subsequent tab
click's JS got `null` from `getElementById('transfer')` and
silently failed — tab header toggled, iframe didn't reload.

Stripe's `stripe_order_refund.html.twig` has both pieces (lines
180-184 and 452-453) — that's why the issue was PayPal-only.

## What changed

`views/twig/admin/paypal_order_refund.html.twig`:

**1. Added the transfer form** right after the template header
comment, mirroring Stripe's:

```twig
<form name="transfer" id="transfer" action="{{ oViewConf.getSelfLink() }}" method="post">
    {{ oViewConf.getHiddenSid()|raw }}
    <input type="hidden" name="oxid" value="{{ oxid }}">
    <input type="hidden" name="cl" value="PayPalOrderRefund">
</form>
```

**2. Added the admin layout closes** after the main `</div>`:

```twig
{% include "bottomnaviitem.html.twig" %}
{% include "bottomitem.html.twig" %}
```

No controller, services.yaml, or translation changes.

## Verification

- Rendered PayPal tab for order 689 via `UtilsView::getTemplateOutput`:
  - `<form id="transfer">` present
  - `<input name="cl" value="PayPalOrderRefund">` present
  - Final `</body></html>` present
- `./bin/pre-commit-check.sh --no-phpunit` on PayPal → all green.

## Follow-ups

- Worth a regression test: assert every PSP admin tab template
  emits `<form id="transfer">` with the correct `cl` value and
  a properly-closed HTML document. Could live as a PHPUnit
  rendering test in each module, or a shared-test package — but
  that's a separate sprint.
