# Status — dev_log 20261008 · Stripe webhook endpoint registration

**Branch:** `b-7.4.x-stripe-webhook-endpoint` (from `b-7.4.x` after the GRAPH-QL merge). **Merged into `b-7.4.x` 2026-10-08** (merge commit `6795f83`, at the user's request).

| Story | State | Notes |
|---|---|---|
| Webhook endpoint registration works for the shop's own account | **DONE** 2026-10-08 | [done/stripe-webhook-endpoint-registration.md](done/stripe-webhook-endpoint-registration.md) — own key first, verified Connect fallback, Dashboard instruction otherwise; Unit 1638, gates green |
| The endpoint answers before the stale-order sweep | **DONE** 2026-10-08 | [done/stripe-webhook-answers-before-sweep.md](done/stripe-webhook-answers-before-sweep.md) — 200 released (`fastcgi_finish_request`) before the STRP-100 sweep; 32 s → 0.33 s; order 817 ended by Stripe's webhook alone; Unit 1641, gates green |

## Dev-shop finding

The shop's Stripe account (Violet Seesaw, `acct_1TuDSd…`) is a Connect **connected** account; the configured "platform
key" belongs to OXID eSales AG (`acct_1OyE4t…`), which does not control it. No API key available to the module may
create or list endpoints on the shop's account, so Stripe can reach this shop only through an endpoint created in the
Violet Seesaw Dashboard (or `stripe listen` after `stripe login` into that account). The fixed button says exactly that.

**Correction (same day):** `stripe login` lands in *OXID eSales AG Plattform Sandbox* (`acct_1TBvVe…`), and that sandbox
does control Violet Seesaw. A secret key of that sandbox in the platform-key setting lets the fixed button register the
Connect endpoint; meanwhile `stripe listen` on that login (with `--forward-connect-to`) delivers, and did: order 817 was
ended by the webhook alone. Its signing secret is now in `sStripeWebhookEndpointSecret`.
