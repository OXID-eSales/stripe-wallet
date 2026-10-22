# Stripe Payment Module - Headless Storefronts

## Using Stripe from an app or a custom frontend

**Module:** `oxid-esales/stripe-wallet` ≥ 3.4 · **Requires:** `payment-base` ≥ 1.3, the OXID
GraphQL modules (`graphql-base`, `graphql-storefront`) installed in the shop

Read this if your shop is used by something other than the standard OXID theme: a separate web
storefront, a mobile app, a kiosk, a partner site. The Stripe module lets such a frontend take
payments through the shop's GraphQL API while **the shop keeps full control** of orders, Stripe
accounts and money.

---

## Table of Contents

1. [What you get](#what-you-get)
2. [What the shopper sees](#what-the-shopper-sees)
3. [Setup checklist](#setup-checklist)
4. [What stays in the shop](#what-stays-in-the-shop)
5. [Orders, capture and refunds](#orders-capture-and-refunds)
6. [Troubleshooting](#troubleshooting)
7. [Limits](#limits)

---

## What you get

- Every order a headless frontend takes is a normal shop order with the Stripe payment
  `oe_payments_stripe_wallet`: same admin panel, same capture/refund, same webhook processing as
  orders from the OXID theme.
- The frontend never receives a Stripe secret. It receives only the **publishable** key, and only
  over an encrypted exchange bound to the shopper's session — nothing to configure or to copy into
  the frontend.
- The frontend cannot send shoppers to arbitrary pages after payment: return pages must be on an
  origin **you** allow-list.
- The order is finished by Stripe's webhook, so a shopper who closes the app after paying still
  gets the order.

---

## What the shopper sees

Two presentations, chosen by the frontend per checkout:

| Mode | Shopper experience | Needs |
| --- | --- | --- |
| **Embedded** ("inline") | Stripe's payment form inside the frontend's own checkout page (Stripe Embedded Checkout) | the publishable key, delivered automatically (see below) |
| **Hosted** | a redirect to Stripe's checkout page, then back to the frontend | nothing extra |

If the automatic key delivery is not available (for example the frontend runs an old browser),
a well-built frontend falls back to the hosted page by itself.

---

## Setup checklist

1. **Install and configure the module** as in
   [Installation & Configuration](01-installation-configuration.md): Stripe account connected,
   test/live mode, **publishable key** of the mode in use (`sStripeTestPk` / `sStripeLivePk` —
   this is what the frontend gets), capture mode, **webhook** registered and reachable over HTTPS.
2. **Install the GraphQL modules** (`oxid-esales/graphql-base`, `oxid-esales/graphql-storefront`)
   and activate them. Without them the Stripe module still works for the theme, but no API exists.
3. **Clear the GraphQL schema cache** after activating or updating the Stripe module (or any module that
   adds API calls). On the server, in the shop's directory:

   ```
   vendor/bin/oe-console oe:graphql:cache-clear
   ```

   (`bin/oe-console` in the Docker SDK.) The API's schema is cached on disk and OXID never clears it by itself,
   so without this step an updated shop can keep answering with the old API.
4. **Allow the frontend's origin.** Admin → Modules → *Payment Base* → settings group
   *Headless checkout (GraphQL, apps)* → **Allowed return origins for headless clients**
   (`sPaymentBaseHeadlessReturnOrigins`). Enter every origin your frontends run on, comma- or
   newline-separated, e.g.

   ```
   https://shop.example.com, https://app.example.com, http://localhost:5173
   ```

   The shop's own URL is always allowed; empty means shop only. An origin is scheme + host +
   port, so `https://app.example.com:8443` and `https://app.example.com` are two entries.
5. **Allow the frontend to call the API** (CORS for `/graphql/`): a web-server setting, ask your
   hosting partner if the frontend runs on another domain.
6. **Give the frontend team the shop URL** and nothing else. Keys are delivered by the shop; the
   Stripe secret key, OAuth token and webhook secret never leave the module settings.
7. **Test with Stripe test mode first:** the frontend pays with a test card, the order appears in
   Admin → Orders with the Stripe payment, the Stripe Dashboard shows the test payment, the
   webhook delivered. Then switch the module to live.

---

## What stays in the shop

| In the shop (you) | In the frontend (the developers) |
| --- | --- |
| Stripe account, secret key, webhook secret, capture mode | which mode to present (embedded / hosted) |
| which origins may receive shoppers back | the look of the checkout page |
| the order, its number, status, capture, refund | showing the order number and status to the shopper |
| the publishable key, handed over sealed per session | asking for it when the payment step opens |

---

## Orders, capture and refunds

Nothing changes: see [Order Management](01-installation-configuration.md#order-management) and
[Refunds](01-installation-configuration.md#refunds). With **manual** capture a headless order
is created as authorized and is paid when you capture it, exactly like a theme order. Cancelled
or abandoned attempts leave no finished order: the frontend retires them and the early order is
stornoed.

---

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Frontend reports `return_url_rejected` / `origin_not_allowed` | the frontend's origin is not allow-listed | add it to *Allowed return origins*, clear the cache |
| Frontend shows Stripe's **hosted** page although embedded was expected | the publishable key for the current mode is empty, or not a `pk_…` key; or the GraphQL key query is missing on this shop (old payment-base) | set `sStripeTestPk` / `sStripeLivePk`; update payment-base and re-activate the modules |
| Order stays "pending" for the frontend | the webhook has not reached the shop yet (or is not registered); the frontend then settles on its own on the next check | register the webhook, check the Stripe Dashboard → Webhooks for delivery errors; the shop must be reachable over HTTPS |
| `Cannot query field "stripeCheckoutStart"` | GraphQL modules not installed/activated, or the Stripe module activated before them | install and activate `graphql-base` + `graphql-storefront`, then re-activate the Stripe module |
| `Cannot query field "…"` for a call the module documents (`stripeCheckoutStart`, `paymentClientConfig`, …) although the module is active | the GraphQL schema cache still holds the schema from before the activation or update | run `vendor/bin/oe-console oe:graphql:cache-clear` (`bin/oe-console` in the Docker SDK) and repeat the call |
| Guests cannot pay | the headless checkout currently needs a logged-in customer | the frontend registers/logs in the shopper first (free registration is enough) |

---

## Limits

- Guest checkout is not available over the API yet (customers only).
- Only Stripe's hosted page and Embedded Checkout are offered; custom Stripe Elements are not.
