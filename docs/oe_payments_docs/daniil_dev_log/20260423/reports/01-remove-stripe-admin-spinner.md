# Task 1 — remove loading spinner on the Stripe admin tab (2026-04-23)

## What shipped

**`views/twig/admin/stripe_order_refund.html.twig`** — three
pieces deleted:

1. The `.stripe-loading-overlay` / `.stripe-spinner` /
   `.stripe-spinner::before` / `.stripe-spinner-text` /
   `.stripe-admin.s-content-loading` CSS block (~40 lines,
   including the `@keyframes s-spin` rule).
2. The conditional `<div class="stripe-loading-overlay"
   id="stripeLoadingOverlay">…</div>` overlay block rendered
   immediately before the content wrapper.
3. The trailing `<script>` block that ran three phases:
   - `DOMContentLoaded` reveal (removed `s-content-loading` +
     hid the overlay),
   - `submit` listener on `#captureForm`, `#cancelForm`, `#search`
     that re-showed the overlay,
   - `top.reloadEditFrame` monkey-patch that injected an
     inline-styled spinner into `top.basefrm.edit.document`
     before the tab navigated.

The `s-content-loading` class no longer appears on the
`stripeContent` wrapper either — the template renders content
synchronously now.

**`views/admin_twig/{en,de}/stripe_lang.php`** — the
`STRIPE_LOADING` entry ("Loading Stripe data…" /
"Stripe-Daten werden geladen…") removed from both language
files. Nothing else referenced it (verified via grep).

**Not touched:**
- `views/twig/widget/checkout/stripe-footer.html.twig` — same
  `stripe-loading-overlay` class name, but completely separate
  scope: customer-facing 3DS processing overlay during checkout.
  Intentionally left intact.

## Why

The spinner-overlay pattern added perceived latency on a page
that otherwise renders server-side in one request — the Stripe
API call that fetches the transaction history happens during
server-side template-data construction, not client-side. By the
time the browser receives the HTML, the data is already
embedded. The `s-content-loading { visibility: hidden }` +
overlay combination just made users wait an extra animation
frame before seeing content that was already there.

The `top.reloadEditFrame` monkey-patch was also fragile — it
reached into `top.basefrm.edit.document` and injected inline
styles across frames, which only worked because admin
templates live in a predictable frameset. Any change to OXID's
admin frame layout would silently break it.

## Verification

- `./bin/pre-commit-check.sh` — all checks green (PHPCS ✓,
  PHPStan ✓, PHPMD ✓, PHPUnit: **809 tests / 1922 assertions**
  ✓). No test references the removed selectors or translation
  key.
- Grep confirms no residual references to `stripeLoadingOverlay`,
  `stripe-loading-overlay`, `stripe-spinner`, `s-content-loading`,
  or `STRIPE_LOADING` in Stripe source/template/lang files
  (except the checkout-widget overlay, which is a separate
  concern).

## Follow-ups

None. The change is isolated to the admin tab and removes
complexity without changing functional behaviour.
