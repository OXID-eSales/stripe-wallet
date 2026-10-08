# Status — dev_log 20261008 · Stripe webhook endpoint registration

**Branch:** `b-7.4.x-stripe-webhook-endpoint` (from `b-7.4.x` after the GRAPH-QL merge). Merge on the product owner's word.

| Story | State | Notes |
|---|---|---|
| Webhook endpoint registration works for the shop's own account | **DONE** 2026-10-08 | [done/stripe-webhook-endpoint-registration.md](done/stripe-webhook-endpoint-registration.md) — own key first, verified Connect fallback, Dashboard instruction otherwise; Unit 1638, gates green |

## Dev-shop finding

The shop's Stripe account (Violet Seesaw, `acct_1TuDSd…`) is a Connect **connected** account; the configured "platform
key" belongs to OXID eSales AG (`acct_1OyE4t…`), which does not control it. No API key available to the module may
create or list endpoints on the shop's account, so Stripe can reach this shop only through an endpoint created in the
Violet Seesaw Dashboard (or `stripe listen` after `stripe login` into that account). The fixed button says exactly that.
