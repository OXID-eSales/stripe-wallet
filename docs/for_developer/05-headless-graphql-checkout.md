# Headless Checkout over GraphQL

**Audience:** developers building a frontend that is not the OXID Twig theme — a Vue/React/Next
storefront, a native app, a kiosk — and anyone extending the headless layer.
**Module:** `oe_payments_stripe_wallet` ≥ 3.4 on `payment-base` ≥ 1.3, with `graphql-base` and
`graphql-storefront` installed in the shop.

The module exposes the complete Stripe payment as three GraphQL mutations plus one query for the
public key. A client never talks to the shop's Twig controllers and never sees a Stripe secret.

---

## Table of Contents

1. [What "headless" means here](#1-what-headless-means-here)
2. [Where the code lives](#2-where-the-code-lives)
3. [The API](#3-the-api)
4. [A complete checkout, call by call](#4-a-complete-checkout-call-by-call)
5. [The publishable key: sealed exchange](#5-the-publishable-key-sealed-exchange)
6. [Who finishes the order: the webhook](#6-who-finishes-the-order-the-webhook)
7. [Security model](#7-security-model)
8. [Errors](#8-errors)
9. [Building another frontend](#9-building-another-frontend)
10. [Adding another provider](#10-adding-another-provider)
11. [Reference implementation and tools](#11-reference-implementation-and-tools)
12. [Known gaps](#12-known-gaps)

---

## 1. What "headless" means here

The OXID checkout normally ends in `placeOrder`, which creates the order *before* the payment.
Stripe runs the other way round (contract first, see [Module Principles](01-module-principles.md)):
"Place order" creates a **payment contract**, the shopper pays, and the order is committed only when
the money is confirmed. The headless layer makes that lifecycle callable from outside the Twig
frontend:

```
client                          GraphQL (graphql-base + storefront)          Stripe
  │  stripeCheckoutStart ──────►  payment-base HeadlessCheckoutService
  │                                 └─ StripePaymentHandler ──────────────►  Checkout Session
  │  ◄── contractId, contractToken, clientSecret | redirectUrl
  │  (shopper pays in Stripe's Embedded Checkout or on checkout.stripe.com)
  │                                                       webhook ◄────────  checkout.session.completed
  │                                                       └─ contract COMMITTED, order finished
  │  stripeCheckoutReturn ─────►  reports committed | pending | failed
```

Everything provider-neutral (basket → contract → early order → token → return → cancel) is in
**payment-base** (`src/Checkout/Headless/`). The Stripe module adds the Stripe session, the
return resolver and the GraphQL controller. The same `StripePaymentHandler` serves the Twig/OPC
checkout, so headless and classic orders are identical in the admin and in Stripe.

GraphQL is **optional** for both modules: the controllers, namespace mappers and permission
providers are wired with `autowire: false` and `@?` references, so the modules activate on a shop
without `graphql-base`; the mutations simply do not exist there.

---

## 2. Where the code lives

| Concern | File |
| --- | --- |
| The three mutations | `src/Stripe/GraphQL/Controller/StripeCheckout.php` |
| Client-safe GraphQL error | `src/Stripe/GraphQL/Exception/StripeCheckoutError.php` |
| GraphQLite namespace registration | `src/Stripe/GraphQL/Service/NamespaceMapper.php` (controllers only; result types belong to payment-base) |
| Stripe session (hosted / embedded) | `src/Stripe/PaymentHandler/StripePaymentHandler.php` (`headlessUrls()`), `src/Stripe/Service/CheckoutSessionService.php` |
| Return (session id → contract) | `src/Stripe/Resolver/StripeReturnResolver.php` (tag `oe.payment.return_resolver`, `provider: stripe`) |
| Public key for clients | `src/Stripe/ClientConfig/StripeClientConfigProvider.php` (tag `payment_base.client_config_provider`) |
| Provider-neutral service | payment-base `src/Checkout/Headless/HeadlessCheckoutService.php`, `HeadlessStartRequest.php`, `HeadlessCheckoutException.php` |
| Result types | payment-base `src/GraphQL/DataType/CheckoutStartResult.php`, `CheckoutReturnResult.php`, `CheckoutCancelResult.php`, `SealedClientConfig.php` |
| Rights | payment-base `src/GraphQL/Service/PermissionProvider.php` |
| Return-URL allow-list | payment-base `src/Checkout/ReturnUrl/AllowListReturnUrlPolicy.php`, setting `sPaymentBaseHeadlessReturnOrigins` |
| Sealed key exchange | payment-base `src/ClientConfig/` (`ClientConfigSealer`, `ClientConfigGuard`, registry), `src/GraphQL/Controller/PaymentClientConfig.php` |
| `placeOrder` guard | payment-base `src/GraphQL/Subscriber/RefusePlaceOrderForContractFirstPayments.php` |

---

## 3. The API

All mutations need a bearer token of a **customer** (`#[Logged]`) with the right
`PAYMENT_CHECKOUT` (groups `oxidcustomer`, `oxidnotyetordered`, `oxidadmin`). Send it as
`Authorization: Bearer <jwt>`; get it from graphql-base's `token(username, password)` query.

### `stripeCheckoutStart`

```graphql
mutation {
  stripeCheckoutStart(
    basketId: ID!
    confirmTermsAndConditions: Boolean!
    returnUrl: String!
    cancelUrl: String          # optional; defaults to returnUrl
    uiMode: String = "hosted"  # "hosted" | "embedded"
  ) {
    contractId        # String!
    contractToken     # String!  — keep it; return and cancel need it
    providerName      # String!  "stripe"
    orderNumber       # String   — the early order's number
    redirectUrl       # String   — hosted: the checkout.stripe.com URL
    clientSecret      # String   — embedded: for Stripe.js initEmbeddedCheckout
    renderMode        # String!  "redirect" | "embedded"
  }
}
```

- The basket is a storefront basket (`basketCreate` / `basketAddItem` / `basketSetDeliveryMethod`).
  Do **not** call `basketSetPayment` or `placeOrder`: the mutation sets the payment id
  `oe_payments_stripe_wallet` itself, and `placeOrder` on a Stripe basket is refused
  ("Payment … is handled by the stripe checkout: call stripeCheckoutStart instead of placeOrder").
- `confirmTermsAndConditions` must be `true` when the shop requires consent (`blConfirmAGB`),
  otherwise `terms_not_confirmed`.
- `returnUrl` (and `cancelUrl`, if given) must be absolute `http(s)` URLs on the shop's own origin
  or on an origin listed in `sPaymentBaseHeadlessReturnOrigins`; otherwise `return_url_rejected`.
- **hosted:** a Stripe Checkout Session with `success_url` = `returnUrl` plus
  `session_id={CHECKOUT_SESSION_ID}` and `cancel_url` = `cancelUrl`. Send the shopper to
  `redirectUrl`.
- **embedded:** the session is created with `ui_mode: embedded` and `return_url`; mount Stripe's
  Embedded Checkout with `clientSecret`. In this mode Stripe has no cancel URL (the two are
  mutually exclusive at Stripe); your own UI handles "cancel" by calling `stripeCheckoutCancel`.
- The capture mode follows the module setting `sStripeCaptureMode` (`automatic` or `manual`).

### `stripeCheckoutReturn`

```graphql
mutation {
  stripeCheckoutReturn(
    contractId: String!
    contractToken: String!
    checkoutSessionId: String   # the session_id Stripe put on the return URL
  ) {
    status          # "committed" | "pending" | "failed"
    orderId         # String — only when committed
    orderNumber     # String
    contractState   # String — draft … committed, fulfilled, cancelled, expired, failed
  }
}
```

Idempotent: call it as often as you like. If a webhook already committed the contract it only
reports; otherwise the Stripe return resolver reads the Checkout Session and commits
(`ready_to_commit`, or `authorized` with manual capture). Without `checkoutSessionId` the resolver
cannot settle and the answer is the current contract state (normally `pending`), so pass the
`session_id` whenever you have it and **poll** a `pending` answer (the reference client polls up
to 5 × 2 s before showing "pending").

### `stripeCheckoutCancel`

```graphql
mutation {
  stripeCheckoutCancel(contractId: String!, contractToken: String!) {
    cancelled       # Boolean!
    contractId      # String!
    contractState   # String!
  }
}
```

Retires an attempt the shopper abandoned: the early (NOT_FINISHED) order is stornoed and the
contract cancelled unless money was already taken. Cancelling twice changes nothing. A cancelled
attempt's basket is finished with — start the next attempt with a **new basket**.

### `paymentClientConfig` (query)

```graphql
query { paymentClientConfig(provider: "stripe", clientPublicKey: String!) { v alg serverPublicKey salt iv issuedAt ciphertext } }
```

Right `PAYMENT_CLIENT_CONFIG`, **no** `#[Logged]`: an anonymous token is enough, so the key can
be fetched before login. See section 5.

---

## 4. A complete checkout, call by call

The order the reference storefront uses (all against `POST <shop>/graphql/`, JSON
`{ query, variables }`, header `Accept-Language` for the shop's answer language):

```
 1. query  token                      → anonymous JWT (no credentials) …
 2. query  token(username, password)  → customer JWT          (checkout needs a customer)
 3. query  customerInvoiceAddress     (+ mutation customerInvoiceAddressSet if none)
 4. mutation basketCreate(title, public: false)
 5. mutation basketAddItem(basketId, productId, amount)   × lines
 6. query  basketDeliveryMethods → mutation basketSetDeliveryMethod
 7. query  basketPayments                                   → contains oe_payments_stripe_wallet
 8. query  paymentClientConfig(provider: "stripe", clientPublicKey) → unseal → publishableKey
 9. load   https://js.stripe.com/v3/
10. mutation stripeCheckoutStart(uiMode: "embedded", returnUrl, cancelUrl, confirmTermsAndConditions: true)
11. Stripe(publishableKey, { locale }).initEmbeddedCheckout({ clientSecret }).mount(el)
12. the shopper pays; Stripe sends the browser to returnUrl?session_id=cs_…
13. mutation stripeCheckoutReturn(contractId, contractToken, checkoutSessionId)  (poll while pending)
14. committed → show orderNumber, drop the local cart and the attempt
```

Keep `contractId` / `contractToken` / `orderNumber` in client storage that survives the redirect
(the reference app uses `sessionStorage`). For the **hosted** variant replace 8–11 with
`stripeCheckoutStart(uiMode: "hosted")` and `location.assign(redirectUrl)`; 12–14 are the same.

Rules to respect:

- **One order per attempt.** payment-base's `IdempotentAttemptGuard` keys on
  `order_create:<userId>:<basketId>` for 2 minutes; a second start for the same basket inside
  the window is refused, after it the first attempt is retired. Build a fresh basket for every
  attempt (cancel the old one first) and you never meet this.
- **A committed contract deletes its basket** (`UserBasketRemovalHandler`). The next purchase
  starts with `basketCreate`.
- Leaving the inline UI (other method chosen, terms unticked) → `stripeCheckoutCancel`, then a
  new basket.

---

## 5. The publishable key: sealed exchange

A client needs Stripe's **publishable** key (`pk_…`) to run Stripe.js. It is not configured in
the frontend; the shop holds it (`sStripeTestPk` / `sStripeLivePk`) and hands it over sealed for
one browser:

1. The client makes an ephemeral ECDH **P-256** key pair and sends the public key as the raw
   65-byte uncompressed point, base64url without padding.
2. The shop makes its own ephemeral P-256 key, derives the shared secret, then
   `key = HKDF-SHA256(secret, salt = 32 random bytes, info = aad)` with
   `aad = "oxid-pcc|v1|stripe|<issuedAt>|<tokenid claim of the bearer JWT, or empty>"`.
3. It encrypts `{"publishableKey":"pk_…"}` with AES-256-GCM (12-byte IV, aad as additional data,
   the 16-byte tag appended) and answers the envelope
   `{ v: 1, alg: "ECDH-P256+HKDF-SHA256+A256GCM", serverPublicKey, salt, iv, issuedAt, ciphertext }`.
4. The client derives the same key and decrypts; it refuses an envelope whose `issuedAt` is more
   than 120 s from its clock (replay), or that was sealed for another provider or session.

What this buys: the key is in no repository, build or static file, and is only ciphertext on the
wire and in logs. What it cannot buy: the visitor's browser holds the key after decryption, as any
Stripe.js integration does. The `ClientConfigGuard` refuses to publish anything secret-shaped
(`sk_`, `rk_`, `whsec_`, names containing "secret"), so a provider cannot leak a secret by mistake.
If the exchange fails, a client should fall back to `uiMode: "hosted"`, never to a hard-coded key.
Browser-side reference: `oxid-fe-core/src/payments/sealedConfig.ts` (WebCrypto only, with a shared
test vector in `tests/fixtures/sealed-config-vector.json` that the PHP sealer reproduces).

---

## 6. Who finishes the order: the webhook

The order is **committed by Stripe's webhook**, not by the redirect:

| Event | Handler | Effect |
| --- | --- | --- |
| `checkout.session.completed` with `payment_status = paid` | `CheckoutSessionCompletedWebhookHandler` | contract COMMITTED, order finished |
| `payment_intent.amount_capturable_updated` (manual capture) | `PaymentIntentAmountCapturableUpdatedWebhookHandler` | committed with `requiresCapture`: order exists, paid after the merchant captures |

`stripeCheckoutReturn` is the client's *view* on that: it reports a commit that happened, or
commits itself when the webhook has not arrived yet. A client therefore never depends on the
webhook's timing, and a shopper who closes the tab still gets the order. Details:
[Webhook processing](../architecture/04-webhook-processing.md); the endpoint is
`index.php?cl=StripeWebhookController` and must be HTTPS.

---

## 7. Security model

- **Authentication:** graphql-base JWT. The checkout mutations require a logged-in customer; the
  key query accepts a guest token. Rights come from payment-base's `PermissionProvider`
  (`PAYMENT_CHECKOUT`, `PAYMENT_CLIENT_CONFIG`).
- **Contract token:** `stripeCheckoutStart` answers a random 128-bit `contractToken`
  (`bin2hex(random_bytes(16))`, stored in the contract metadata). `return` and `cancel` compare
  it with `hash_equals`; a mismatch is `invalid_token`. Nobody can settle or cancel someone else's
  attempt with just the contract id.
- **Return-URL allow-list:** the shop only redirects to its own origin or to
  `sPaymentBaseHeadlessReturnOrigins` (scheme + host + port; no credentials in the URL). A frontend
  on a new domain or port must be listed first — a dev server on an unlisted port fails with
  `return_url_rejected` / `origin_not_allowed`.
- **No secrets to clients:** only the publishable key, sealed (section 5). Secret keys, OAuth
  tokens and webhook secrets stay in module settings.
- **CORS:** `/graphql/` must allow the frontend's origin like any other API; that is shop
  configuration, not part of this module.

---

## 8. Errors

GraphQL errors carry `extensions.errorCode` (and `extensions.providerCode` where Stripe added
detail). Map by code, not by message:

| `errorCode` | Meaning |
| --- | --- |
| `basket_not_found` | unknown or foreign basket |
| `payment_not_supported` | basket set to another payment, or no contract-first handler |
| `terms_not_confirmed` | `confirmTermsAndConditions` false while the shop requires consent |
| `return_url_rejected` | `providerCode`: `not_absolute`, `scheme_not_allowed`, `origin_not_allowed`, `credentials_not_allowed` |
| `user_not_found` | the JWT's user does not exist |
| `provider_failed` | Stripe refused; the message is the fixed sentence "Stripe could not start the checkout" (`StripeCheckoutError`), `providerCode`: `STRIPE_UI_MODE_UNSUPPORTED`, `STRIPE_SESSION_FAILED`, `STRIPE_PAYMENT_FAILED` |
| `contract_not_found` | unknown contract id on return/cancel |
| `invalid_token` | wrong `contractToken` (also graphql-base's code for a bad bearer token) |
| `no_return_resolver` | provider has no return resolver (not the case for Stripe) |

Only `hosted` and `embedded` are accepted as `uiMode`; anything else is
`STRIPE_UI_MODE_UNSUPPORTED`.

graphql-base writes every error message to the shop log, so a message never carries Stripe's text (session
ids, URLs) or a client URL: `StripePaymentHandler` writes Stripe's text to the PSR logger at `debug` level with the
contract id, and `return_url_rejected` names the origin only. Look in the debug log for the reason of a
`provider_failed`.

---

## 9. Building another frontend

What any frontend needs, in order of dependency:

1. **A GraphQL client** that sends `Authorization: Bearer` and `Accept-Language`, and treats
   `invalid_token` / "The access token is invalid" as "drop the token and re-authenticate" (a token
   survives a shop redeploy in client storage, the shop refuses it, and nothing works until it is
   dropped).
2. **The storefront basket flow** (section 4, steps 3–7). Nothing Stripe-specific.
3. **The three mutations** with the naming convention `<provider>CheckoutStart / Return / Cancel`
   and the shared result types. The reference client generates the documents from the prefix
   (`oxid-fe-core/src/plugins/payment.ts`, `createProviderCheckoutApi('stripe', …)`), so a second
   provider is a one-line registration; copy that idea rather than hand-writing per-provider
   queries.
4. **The sealed key exchange** (section 5) if you want Stripe's UI *inside* your page; skip it and
   use `uiMode: "hosted"` if a redirect to checkout.stripe.com is acceptable (native apps: open the
   `redirectUrl` in a secure web view and intercept the return URL).
5. **Two routes** that survive the redirect: return (`?session_id=…` → `stripeCheckoutReturn`,
   poll while pending) and cancel (`stripeCheckoutCancel`, new basket).
6. **Operations:** the frontend's origin in `sPaymentBaseHeadlessReturnOrigins`, CORS for
   `/graphql/`, the Stripe webhook pointing at the shop.

Everything the shopper does *after* paying (order list, downloads, address book) is plain
graphql-storefront.

---

## 10. Adding another provider

payment-base keeps the provider-neutral half; a provider module contributes:

- a payment handler tagged `oe.payment.handler` that understands the headless metadata
  (`headless`, `uiMode`, `basketId`) and the `PaymentContext` return/cancel URLs;
- a return resolver tagged `oe.payment.return_resolver` with `provider: <id>`;
- a GraphQL controller with `<provider>CheckoutStart/Return/Cancel` over
  `HeadlessCheckoutServiceInterface` (copy `StripeCheckout.php`; keep the result types from
  payment-base) and a namespace mapper for its controllers;
- a `ClientConfigProviderInterface` implementation tagged `payment_base.client_config_provider`
  that publishes only public values (the guard refuses secret-shaped ones);
- `autowire: false` + `@?` wiring so the module activates without GraphQL.

The complete provider-side checklist is in payment-base:
[`docs/graphql-headless-checkout.md`](https://github.com/OXID-eSales/payment-base/blob/b-7.4.x/docs/graphql-headless-checkout.md).

---

## 11. Reference implementation and tools

- **Command line:** `SHOP_URL=https://<shop>/ bin/graph-ql-cli-test.sh demo` runs a whole
  checkout with curl; every command and example output in
  [`bin/graph-ql-cli-test.md`](../../bin/graph-ql-cli-test.md). (Its argument table lists
  `cancelUrl` and `checkoutSessionId` as required; in the code both are optional.)
- **Browser:** `oxid-fe-user` (Vue 3) with `oxid-fe-core` — `plugins/payment-stripe/` (the inline
  Embedded Checkout component and the hosted fallback), `plugins/checkout/` (return and cancel
  routes, attempt storage), `docs/payments.md`, `docs/payments-keys.md`, ADR-004 (why keys come
  from the backend). Its Playwright suite completes real sandbox payments against the mutations.

---

## 12. Known gaps

- **No guest checkout:** `#[Logged]` on the mutations; anonymous tokens only read the key.
- `uiMode: "custom"` (Stripe Elements) is not implemented.
- Stripe appends only `session_id` to the return URL (not `contract_id`); keep the contract
  identifiers on the client side.
