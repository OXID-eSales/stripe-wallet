# `bin/graph-ql-cli-test.sh` — the headless Stripe checkout from the command line

A curl + jq script that shows what the Stripe module can do through the **GraphQL Storefront**, with no Twig page
involved: open a contract (hosted redirect or embedded client secret), pay on Stripe, commit the order, cancel an
attempt, and see the two refusals that protect the flow. It is the CLI twin of the Playwright spec
`tests/e2e/GraphQL/stripe-headless-checkout.spec.ts` and of the GRAPH-QL / PS6 report.

## What "GraphQL support" means here

The Stripe module follows **Option B** of the GRAPH-QL epic: the contract-first flow is kept as it is and exposed
through three provider mutations. The core storefront `placeOrder` is **not** used for Stripe baskets; payment-base
refuses it and names the mutation to call instead.

```
client                     GraphQL Storefront            payment-base (headless)         Stripe
------                     ------------------            -----------------------         ------
token ─────────────────▶   JWT
basketCreate/AddItem ──▶   oxuserbaskets row
stripeCheckoutStart ───▶   Stripe controller ─────────▶  HeadlessCheckoutService.start
                                                         ├ basket + user from the row
                                                         ├ early order (NOT_FINISHED), contract PENDING
                                                         └ StripePaymentHandler ───────▶ Checkout Session
                        ◀─ CheckoutStartResult { contractId, contractToken, orderNumber,
                                                 redirectUrl | clientSecret, renderMode }
(shopper pays on Stripe's hosted page or in the embedded Checkout)
                                                                                   ◀─── webhook may commit first
stripeCheckoutReturn ──▶   ……………………………………………………………▶  return resolver (session_id) → commit
                        ◀─ CheckoutReturnResult { status, orderId, orderNumber, contractState }
stripeCheckoutCancel ──▶   ……………………………………………………………▶  retire the unpaid attempt (order deleted)
                        ◀─ CheckoutCancelResult { cancelled, contractId, contractState }
```

- **Mutations** (`#[Logged] #[Right('PAYMENT_CHECKOUT')]`, granted to `oxidcustomer` and `oxidnotyetordered`):

  | Mutation | Arguments | Returns |
  |---|---|---|
  | `stripeCheckoutStart` | `basketId: ID!`, `confirmTermsAndConditions: Boolean!`, `returnUrl: String!`, `cancelUrl: String!`, `uiMode: String` (`hosted` default, `embedded`) | `CheckoutStartResult!` |
  | `stripeCheckoutReturn` | `contractId: String!`, `contractToken: String!`, `checkoutSessionId: String!` | `CheckoutReturnResult!` |
  | `stripeCheckoutCancel` | `contractId: String!`, `contractToken: String!` | `CheckoutCancelResult!` |

- **Result types** are payment-base's, shared by every provider (`status` of a return is `committed`, `pending`
  while a webhook is still expected, or `failed` for a cancelled / unpaid attempt): `CheckoutStartResult { contractId contractToken
  providerName orderNumber redirectUrl clientSecret renderMode }`, `CheckoutReturnResult { status orderId
  orderNumber contractState }`, `CheckoutCancelResult { cancelled contractId contractState }`.
- **Refusals** are GraphQL errors with a stable `extensions.errorCode` (payment-base `HeadlessCheckoutException`):
  `basket_not_found`, `payment_not_supported`, `terms_not_confirmed`, `return_url_rejected` (+ `providerCode`
  such as `origin_not_allowed`), `user_not_found`, `provider_failed` (+ Stripe's code, e.g.
  `STRIPE_UI_MODE_UNSUPPORTED`), `contract_not_found`, `invalid_token`, `no_return_resolver`.
- The **contract token** is the client's proof of ownership for return and cancel; a wrong token changes nothing.
- A **second start for the same basket** retires the first attempt (one open attempt per user basket). Webhooks
  (`checkout.session.completed`, `payment_intent.succeeded`) commit a paid contract even if the browser never returns.
- `stripeCheckoutStart` names its payment itself (`oe_payments_stripe_wallet`): the client needs no
  `basketSetPayment` first. A basket explicitly set to another payment is refused with `payment_not_supported`.

## Prerequisites

- A shop with `oe_graphql_base` and `oe_graphql_storefront` active (dev shop: `oxid-esales/graphql-storefront:dev-b-7.4.x`),
  payment-base `b-7.4.x-GRAPH-QL` (Sprint 15) and this module's `b-7.4.x-GRAPH-QL` active, Stripe **test** keys configured.
- A customer in a country with a delivery set (the demo data's Germany works). The dev shop has
  `headless.user@oxid-esales.dev` / `useruser`; the Playwright user lives in Switzerland and gets no delivery.
- `curl` and `jq` on the machine you run the script from; it talks HTTP to the shop, nothing else.

## Usage

```
bin/graph-ql-cli-test.sh <command> [args]

  schema                  the Stripe mutations and their result types, as the schema exposes them
  start [hosted|embedded] login, create a basket with one product, stripeCheckoutStart (default hosted)
  pay                     = start hosted, then tells you how to pay and how to finish with 'return'
  return <checkoutSessionId> [contractId contractToken]   stripeCheckoutReturn (ids default to the last start)
  cancel [contractId contractToken]                        stripeCheckoutCancel (ids default to the last start)
  wrong-token             stripeCheckoutCancel with a bogus token: refused, nothing changes
  guard                   core placeOrder on a basket paying with Stripe: refused, names stripeCheckoutStart
  demo                    everything that needs no browser
  raw '<query>'           any GraphQL document, logged in
```

Environment (all optional):

| Variable | Default | Meaning |
|---|---|---|
| `GRAPHQL_URL` | `http://localhost.local/graphql/` | the endpoint |
| `SHOP_URL` | origin of `GRAPHQL_URL` | base of `RETURN_URL` / `CANCEL_URL`. **Must be the shop's configured URL** (or an origin listed in the setting `sPaymentBaseHeadlessReturnOrigins`), otherwise `return_url_rejected / origin_not_allowed`. In the dev shop that is the tunnel: `SHOP_URL=https://daniil.oxiddev.de/` |
| `USER_EMAIL` / `USER_PASSWORD` | `headless.user@oxid-esales.dev` / `useruser` | the customer |
| `PRODUCT_ID` | `5e6a374e212258abbfd76b6adf911772` | "Panorama", 20.90 EUR |
| `DELIVERY_METHOD_ID` | `oxidstandard` | used by `guard` only |
| `RETURN_URL` / `CANCEL_URL` | `${SHOP_URL}headless/return|cancel` | need not exist; Stripe appends `?session_id=…` |
| `STATE_FILE` | `/tmp/graph-ql-cli-test.state` | the last start's contract id / token / basket id |
| `VERBOSE` | `0` | `1` prints every raw JSON response to stderr |

## Examples

### Everything without a browser

```bash
SHOP_URL=https://daniil.oxiddev.de/ bin/graph-ql-cli-test.sh demo
```

```
Stripe mutations in the schema (logged in — GraphQLite hides #[Logged] fields from anonymous introspection)
  stripeCheckoutStart(basketId: ID, confirmTermsAndConditions: Boolean, returnUrl: String, cancelUrl: String, uiMode: String): CheckoutStartResult
  stripeCheckoutReturn(contractId: String, contractToken: String, checkoutSessionId: String): CheckoutReturnResult
  stripeCheckoutCancel(contractId: String, contractToken: String): CheckoutCancelResult
  CheckoutStartResult { contractId, contractToken, providerName, orderNumber, redirectUrl, clientSecret, renderMode }
  CheckoutReturnResult { status, orderId, orderNumber, contractState }
  CheckoutCancelResult { cancelled, contractId, contractState }

stripeCheckoutStart (uiMode: hosted)
  basket 28eb99cf…, total 30.9
{ "contractId": "b29f21e6…", "contractToken": "699e3e7b…", "providerName": "stripe", "orderNumber": "787",
  "redirectUrl": "https://checkout.stripe.com/c/pay/cs_test_…", "clientSecret": null, "renderMode": "redirect" }

stripeCheckoutCancel
{ "cancelled": true, "contractState": "cancelled" }

stripeCheckoutStart (uiMode: embedded)
{ …, "orderNumber": "788", "redirectUrl": null, "clientSecret": "cs_test_…_secret_…", "renderMode": "embedded" }

stripeCheckoutCancel with a wrong token (expected: refused)
  The contract token does not authorise contract d5254528… [invalid_token]

stripeCheckoutCancel
{ "cancelled": true, "contractState": "cancelled" }

core placeOrder on a basket that pays with Stripe (expected: refused, points at stripeCheckoutStart)
  Payment "oe_payments_stripe_wallet" is handled by the stripe checkout: call stripeCheckoutStart instead of placeOrder [-]
```

Every `start` opens a real contract and a `NOT_FINISHED` order and creates a Stripe Checkout Session in test mode;
`demo` cancels what it opened.

### A paid order

```bash
SHOP_URL=https://daniil.oxiddev.de/ bin/graph-ql-cli-test.sh pay
# open the redirectUrl in a browser, pay with 4242 4242 4242 4242, any future date, any CVC
# Stripe sends the browser to https://daniil.oxiddev.de/headless/return?session_id=cs_test_…
SHOP_URL=https://daniil.oxiddev.de/ bin/graph-ql-cli-test.sh return cs_test_…
```

```
stripeCheckoutReturn
{ "status": "committed", "orderId": "…", "orderNumber": "789", "contractState": "fulfilled" }
```

The order is then a normal paid order in the admin (Stripe tab shows the PaymentIntent). If the webhook arrived
first, `return` answers the already committed contract — the call is idempotent.

### Your own queries

```bash
bin/graph-ql-cli-test.sh raw '{ baskets(owner: "headless.user@oxid-esales.dev") { id title } }'
bin/graph-ql-cli-test.sh raw 'mutation { stripeCheckoutStart(basketId: "…", confirmTermsAndConditions: false, returnUrl: "…", cancelUrl: "…") { contractId } }'
#   → "… terms and conditions …" [terms_not_confirmed]
```

Or plain curl, which is all the script does:

```bash
TOKEN=$(curl -s http://localhost.local/graphql/ -A 'Mozilla/5.0 cli' -H 'Content-Type: application/json' \
  --data '{"query":"{ token(username: \"headless.user@oxid-esales.dev\", password: \"useruser\") }"}' | jq -r .data.token)
curl -s http://localhost.local/graphql/ -A 'Mozilla/5.0 cli' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  --data '{"query":"mutation { stripeCheckoutStart(basketId: \"<id>\", confirmTermsAndConditions: true, returnUrl: \"https://daniil.oxiddev.de/r\", cancelUrl: \"https://daniil.oxiddev.de/c\") { redirectUrl } }"}'
```

## Gotchas

- **User agent.** OXID disables the basket for user agents it takes for search engines; curl's default is one, and the
  symptom is `total 0` and "no delivery". The script sends a browser-like `User-Agent`.
- **Introspection.** The storefront's `basketSetDeliveryMethod`, `basketSetPayment` and the Stripe mutations are
  `#[Logged]`, so anonymous introspection does not list them. `schema` logs in first.
- **Return origin.** `returnUrl` / `cancelUrl` must be under the shop's own URL or an origin in
  `sPaymentBaseHeadlessReturnOrigins` — set `SHOP_URL` to the configured shop URL, not to `localhost.local`,
  when the two differ.
- **placeOrder guard** fires only after the storefront's own checks: without a delivery method the core answers
  "Delivery set must be selected!" first. `guard` sets delivery method and payment, then calls `placeOrder`.
- **Embedded mode** gives a `clientSecret` for Stripe's embedded Checkout (`stripe.initEmbeddedCheckout`); the CLI
  can only open and cancel it. `uiMode: custom` (Payment Element) is not supported yet (`provider_failed /
  STRIPE_UI_MODE_UNSUPPORTED`).

## Where the pieces live

- Stripe: `src/Stripe/GraphQL/Controller/StripeCheckout.php` (mutations), `GraphQL/Exception/StripeCheckoutError.php`
  (error → `extensions.errorCode`), `GraphQL/Service/NamespaceMapper.php`, `PaymentHandler/StripePaymentHandler.php`
  (headless path, `metadata.headless`), webhooks committing via `ContractCommitServiceInterface`.
- payment-base: `src/Checkout/Headless/HeadlessCheckoutService.php`, `src/GraphQL/DataType/Checkout*Result.php`,
  `src/GraphQL/Subscriber/RefusePlaceOrderForContractFirstPayments.php`, `src/Checkout/ReturnUrl/*`.
- Reports: `docs/oe_payments_docs/daniil_dev_log/20261006/` (PS1–PS6) and payment-base `docs/dev_log/20261006/`
  (Sprint 15); the analysis behind Option B: payment-base
  `docs/dev_log/20261002/reports/graphql-placed-order-and-payments.md`.
