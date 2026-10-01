# Research — Stripe for a headless OXID shop (GraphQL Storefront)

**Date:** 2026-10-01 · **Repo:** `extensions/stripe` (v3.3.0, `oe_payments_stripe_wallet`) + `extensions/payment-base`
**Author:** Daniil · **Type:** feasibility / options report, no code changed
**Inputs:** OXID GraphQL docs v13.0 ("Place an Order", "Third Party Payments", "Events", "Best Practices"),
`graphql-storefront` `b-7.4.x` sources (`Basket/Service/PlaceOrder.php`, `Basket/Infrastructure/Basket.php`,
`Basket/Controller/Basket.php`), a read-through of the Stripe module and payment-base, Stripe integration guidance
(Checkout Sessions, `ui_mode`, fulfilment via webhooks).

---

## 0. Short answer

**Yes, Stripe can serve a headless OXID shop, but not with the module as it is today.** The parts that talk to
Stripe (Checkout Session creation, HMAC-signed return validation, webhooks, capture/refund) are already stateless.
What is not stateless is the OXID side of our contract-first flow:

1. the **early `oxorder` is built from the PHP session basket** (`payment-base/src/Adapter/OxidShopOrderService.php:196-263`), not from the `BasketSnapshot`;
2. the **only path that moves a contract PENDING → COMMITTED is the browser return** (`cl=order&fnc=checkoutSuccess`); webhooks only *fulfil* a contract that is already COMMITTED;
3. the "Place Order" entry point is an OXID controller action guarded by the **session challenge (stoken)** and it reads request params and session flags (`stripe_skip_addr_check`, `stripe_agb_confirmed`).

The GraphQL Storefront has **no session** (JWT only), owns the basket as an `oxuserbaskets` row, and expects a payment
module to add its own queries/mutations plus a `BeforePlaceOrder` subscriber. So the work is a thin GraphQL layer
plus three seams in payment-base/Stripe: a basket-by-id order service, a session-free "context" for the flags we
currently keep in `$_SESSION`, and a webhook-driven commit as a safety net. The Stripe-facing code stays as is.

Nothing GraphQL exists yet: `graphql-storefront` is **not installed** in this dev shop (only `graphql-base`), the
Stripe module has no GraphQL code or dependency, and the `one-page-checkout` module's
`Controller/GraphQL/OnePageController.php` is an unwired stub.

---

## 1. What a headless OXID checkout looks like (from the docs)

### 1.1 Standard flow (`consuming/PlaceOrder`)

```
token(username, password)                       → JWT (Authorization: Bearer …)
basketCreate(title, public)                     → basket id
basketAddItem(basketId, productId, amount)
basketDeliveryMethods(basketId) → basketSetDeliveryMethod(basketId, deliveryMethodId)
basketPayments(basketId)        → basketSetPayment(basketId, paymentId)
placeOrder(basketId, confirmTermsAndConditions, remark) → { id, orderNumber }
```

Facts that matter for us:

- "GraphQL part of the OXID eShop does not use a session." Identification is the JWT; the basket is persisted
  (`oxuserbaskets` + `oxuserbasketitems`), the client keeps the basket id.
- `placeOrder` → `PlaceOrder::placeOrder()` dispatches **`BeforePlaceOrder(basketId)`** first, then validates
  AGB (`blConfirmAGB` ⇒ `confirmTermsAndConditions` must be `true`), orderable items, delivery method and payment
  availability, then `BasketInfrastructure::placeOrder()` which **builds an `oxBasket` from the user basket and calls
  `oxOrder::finalizeOrder($basketModel, $userModel)`**, then dispatches `BeforeBasketRemoveOnPlaceOrder` and deletes
  the user basket unless a subscriber keeps it.
- Delivery address goes in via `$_POST['sDeliveryAddressMD5']` set by the infrastructure itself, so core's address
  validation passes without our `stripe_skip_addr_check` hack.
- "Only a user with a shop account will be able to create a basket" — guest checkout exists only as the
  **anonymous token** (`query { token }` without credentials → random userid, group `oxidanonymous`) and the module
  must grant that group rights through `PermissionProviderInterface`. "If such an anonymous user loses their JWT token,
  they will lose the basket."

### 1.2 Third-party payments contract (`thirdpartypayments/*`)

- **Standard checkout:** between `basketSetPayment` and `placeOrder` the client calls a module-specific
  `3rdPartyStandardApprovalProcess` query. The module "store[s] module specific information in some suitable place
  (additional fields for oxuserbaskets table in case of PayPal)". In `BeforePlaceOrder` the module "will use the earlier
  stored additional fields … together with information it will fetch from the [provider] API, to prepare the basket".
- **Express checkout:** anonymous token → basket → `3rdPartyExpressApprovalProcess` → `placeOrder`; the module fills
  delivery address/method from provider data in `BeforePlaceOrder`. Permissions needed for `oxidanonymous`:
  `CREATE_BASKET, ADD_PRODUCT_TO_BASKET, REMOVE_BASKET_PRODUCT, ADD_VOUCHER, REMOVE_VOUCHER, PLACE_ORDER,
  3RDPARTY_EXPRESS_APPROVAL`.
- **Best practices:** pull business logic out of controllers into model/service classes so Twig and GraphQL share it;
  "there is no session to come back to in the next request" — persist what must survive; keep GraphQL an **optional**
  dependency (separate services file, loaded only when the GraphQL modules are active); hook availability logic into
  `DeliverySetList`.
- Relevant events: `BeforeBasketPayments(basketId)`, `BeforeBasketDeliveryMethods`, `BeforePlaceOrder(basketId)`,
  `BeforeBasketRemoveOnPlaceOrder`, `BeforeAuthorization`, `BeforeTokenCreation`.

The reference implementation named by the docs is the OXID PayPal module. The copy in `extensions/paypal` on this
machine has **no GraphQL code**, and the `b-7.4.x` `paypal-module` repo has no `src/GraphQL/` either, so there is no
7.4 reference to copy from; the pattern above is what we have.

---

## 2. What the Stripe module does today, classified for headless

| Piece | Where | Session-free? |
|---|---|---|
| "Place Order" entry (`createCheckoutSession`) | `StripeOrderController::createCheckoutSession()` | **No** — 403 on failed `checkSessionChallenge()` (`:181`), user from session basket, writes `stripe_checkout_session_id`, `stripe_contract_id`, `stripe_skip_addr_check` |
| Contract creation + `BasketSnapshot` | `StripeContractCreationHandler` → payment-base `ContractService::createContract($userId, $basket, …)` | Takes any `Basket` object; snapshot persisted in `oe_payments_contract.OXBASKETDATA` |
| Early order (NOT_FINISHED, draws `OXORDERNR`) | payment-base `EarlyOrderCreationHandler` → `OxidShopOrderService::createOrder()` | **No** — `Registry::getSession()->getBasket()`, throws `basket_not_found`; core `finalizeOrder` checks `sess_challenge`; `Model\Order::validateDeliveryAddress()` needs the session skip flag |
| Stripe Checkout Session create | `CheckoutSessionService::createSession()` → `CheckoutSessionHelper` | Yes — built from the snapshot; `mode=payment`, `metadata{contract_id, shop_id, order_id, order_number}`, `payment_intent_data{capture_method}`; no `payment_method_types` (Dashboard decides) |
| Embedded vs redirect | `IframeCheckoutSettingsInterface`; `ui_mode=embedded`+`return_url` or `success_url`/`cancel_url` | Yes; URLs point at `index.php?cl=order&fnc=checkoutSuccess…` and carry `force_sid`; overridable via `StripeSuccessUrlBuildEvent` / `StripeCancelUrlBuildEvent` |
| Return validation | `StripeReturnResolver` → `CheckoutReturnService::validateReturn(sessionId, contractId, contractToken)` | **Yes** — HMAC `contract_token` (`ContractTokenService`) + Stripe API retrieve. Only the surrounding `CheckoutReturnResponder` (session cleanup, `writeSessChallenge`, `thankyou` render) is session/Twig-bound |
| PENDING → READY_TO_COMMIT → COMMITTED | `PaymentAuthorizedEvent` from the return → `ContractCommitmentHandler` | Driven **only** by the return leg |
| Webhooks | `cl=StripeWebhookController` → `StripeWebhookProcessor`, handlers for `payment_intent.succeeded/payment_failed/canceled`, `charge.refunded`, `charge.dispute.created`, `checkout.session.completed/expired` | **Yes**, fully. But success handlers *skip* unless `isCommitted()` (`PaymentIntentSucceededWebhookHandler.php:94`, `CheckoutSessionCompletedWebhookHandler.php:81`). `checkout.session.completed` and `charge.dispute.created` are missing from `WebhookEventCatalog` |
| OPC handler | `PaymentHandler\StripePaymentHandler::processPayment(PaymentContextInterface)` → `PaymentHandlerResult{contractId, clientSecret, redirectUrl, renderMode, sessionId}` | Closest to an API shape, but `createEarlyOrderAndTransition()` still does `Registry::getSession()->getBasket()` and sets session vars |
| Retry / stale cleanup | `RetryCleanupService`, `OpenCheckoutAttemptRegistry` (session key `oepb_open_checkout_contract_id`) | Partly — `cleanupForUser($userId)` exists as a session-free fallback |
| Admin capture / refund / cancel-auth / OXPAID reconciliation | `OrderActionDispatcher`, `CaptureService`, `RefundService`, `OxpaidReconciliationService` | Yes — untouched by headless |
| Legacy PaymentIntent + Payment Element path | `executeStripePayment()` / `stripeReturn()`, `PaymentIntentHelper::createPaymentIntent()`, `confirmCardPayment` in JS | Dead code; nothing sets `stripe_payment_intent_id` |

**Headless-specific hazard found while reading:** if a shopper pays in Stripe but never reaches `checkoutSuccess`
(SPA tab closed, mobile app killed), the contract stays PENDING with a paid Checkout Session. `cleanupStaleContracts()`
later deletes the NOT_FINISHED order before it consults `CheckoutInFlightGuard`, which returns null for paid sessions,
so the contract is cancelled → **money taken, no order**. In the Twig shop the return is near-certain; in a headless
client it is not. This is from code reading, not reproduced (see
`20260827/reports/not-finished-order-cleanup-analysis.md`). Any headless variant needs the webhook to be able to commit.

---

## 3. Two architectural fits, and which one to take

### Option A — "approval then placeOrder" (the OXID/PayPal pattern)

```
basketSetPayment(basketId, "oe_payments_stripe_wallet")
stripeApprovalProcess(basketId, returnUrl, cancelUrl)   ← module query: creates Checkout Session
                                                           (capture_method=manual) from the oxuserbasket,
                                                           stores cs_id/pi_id on oxuserbaskets (or oe_payments_contract)
shopper pays / authorises in Stripe (hosted, embedded or ui_mode=custom)
placeOrder(basketId, confirmTermsAndConditions)          ← core mutation; our BeforePlaceOrder subscriber
                                                           verifies the PaymentIntent is `requires_capture` for this
                                                           basket & amount, finalizeOrder runs, then capture
```

Pros: 100 % aligned with the documented OXID contract, uses core `placeOrder`, no early order, no `sess_challenge`.
Cons: **inverts our contract-first architecture.** The order number would no longer exist before the redirect, the
`oe_payments_contract` lifecycle (DRAFT → NOT_FINISHED → PENDING → …) would be bypassed or doubled, the admin Stripe
tab, reconciliation and capture-mode semantics assume a contract that owns the order from the start. Two checkout
models in one module is exactly the duplication the OXID best-practice page warns against.

### Option B — keep the contract-first model, expose it as mutations (recommended)

```
basketSetPayment(basketId, "oe_payments_stripe_wallet")
stripeCheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl, uiMode)
      → { contractId, checkoutSessionId, url, clientSecret, renderMode, orderNumber }
      = today's createCheckoutSession() minus session: contract(DRAFT) → early order(NOT_FINISHED) → PENDING
        → Stripe Checkout Session, basket loaded by id from oxuserbaskets, user from the JWT
shopper pays in Stripe; Stripe sends the browser/app to returnUrl?session_id=…&contract_id=…&contract_token=…
stripeCheckoutReturn(contractId, contractToken, checkoutSessionId)
      → { status, orderId, orderNumber }
      = today's checkoutSuccess(): validateReturn() (already stateless) → PaymentAuthorizedEvent → COMMITTED → FULFILLED
stripeCheckoutCancel(contractId, contractToken)              = today's checkoutCancel()
order(orderId) / orders                                       core storefront queries for the thank-you page
```

Core `placeOrder` is **not** called for Stripe baskets; a `BeforePlaceOrder` subscriber throws a clear error if a
client tries (payment id is Stripe), and `BeforeBasketRemoveOnPlaceOrder` is irrelevant because we delete/convert the
user basket ourselves on commit. `basketPayments` stays untouched (our `DeliverySetList`/payment filters already
decide availability by country/currency through `BeforeBasketPayments`-independent core code).

Pros: one checkout model, the whole event chain, admin tab, capture/refund, reconciliation and webhooks work unchanged;
the OPC `StripePaymentHandler` already proves the "API returns `{contractId, clientSecret, redirectUrl, renderMode}`"
shape; `uiMode` can be `hosted`, `embedded` or **`custom`** (Payment Element driven by a Checkout Session client
secret — Stripe's current recommendation for fully custom UIs and the natural fit for an SPA or mobile app).
Cons: deviates from the letter of the OXID docs (no `placeOrder`), so the storefront client must know the Stripe
flow; needs the seams in §4.

**Recommendation: Option B.** It reuses ~everything and is the only one that keeps a single contract lifecycle.

---

## 4. Work items for Option B (what actually has to change)

Ordered by dependency. Items 1–3 are in **payment-base**, the rest in **Stripe**.

1. **Basket source abstraction for the early order.** `OxidShopOrderService::createOrder()` must accept a basket
   provider instead of `Registry::getSession()->getBasket()`: keep the session provider for Twig, add a
   `UserBasketProvider` that loads `oxuserbaskets` by id, checks ownership against the JWT user, and builds the
   `oxBasket` the same way `graphql-storefront`'s `Basket::placeOrder()` does (so prices, vouchers, delivery set and
   payment match what `basketPayments` showed). Also set `sDeliveryAddressMD5` the way the storefront does instead of
   the `stripe_skip_addr_check` session flag.
2. **Checkout context object instead of `$_SESSION` flags.** `SessionAdapterInterface` already exists
   (`OxidSessionAdapter`). Add a request-scoped/contract-backed implementation that stores
   `stripe_contract_id`, `stripe_checkout_session_id`, `skip_addr_check`, AGB consent and the
   `oepb_open_checkout_contract_id` retry marker in `oe_payments_sessions` or contract metadata keyed by contract id.
   `ControllerRequestHelper` becomes one consumer of it, the GraphQL resolver another.
3. **`sess_challenge` / ORDEREXISTS guard.** Core `finalizeOrder` uses the session challenge to detect duplicate
   orders. Headless needs the equivalent via contract id + `oe_payments_idempotency` (we already have the table);
   `OxidSessionWriter::writeSessChallenge()` becomes a no-op in the GraphQL path.
4. **GraphQL layer in Stripe (optional dependency).** `src/Stripe/GraphQL/{Controller,DataType,Service,Permission}`:
   the three mutations above, the `BeforePlaceOrder` guard subscriber, a `PermissionProviderInterface` granting
   `STRIPE_CHECKOUT` to `oxidcustomer` (and `oxidanonymous` if guest checkout is wanted). Wire through a separate
   `services_graphql.yaml` imported only when `oe_graphql_storefront` is active, per the OXID best practice; add
   `oxid-esales/graphql-storefront` to `require-dev` only.
5. **Return/cancel URLs.** `stripeCheckoutStart` passes the client's `returnUrl`/`cancelUrl`; listeners on
   `StripeSuccessUrlBuildEvent` / `StripeCancelUrlBuildEvent` (same mechanism OPC uses) inject them and drop
   `force_sid`. Validate them against an allow-list of storefront origins — this is an open-redirect surface.
6. **Webhook-driven commit (safety net, needed for headless, good for Twig too).** Let
   `checkout.session.completed` with `payment_status=paid` (and `payment_intent.succeeded`) raise
   `PaymentAuthorizedEvent` for a PENDING contract whose amount matches, so PENDING → COMMITTED → FULFILLED happens
   without the return leg; `stripeCheckoutReturn` then becomes idempotent ("already committed" is a success).
   Add `checkout.session.completed` to `WebhookEventCatalog`. Fix the stale-cleanup ordering so a paid session is
   never cancelled (§2 hazard).
7. **Guest/anonymous checkout.** If required: accept the anonymous JWT, let Stripe Checkout collect email + address
   (`customer_creation`, `shipping_address_collection`), and in the return/commit path create or update the `oxuser`
   and order addresses from `customer_details` — the headless analogue of PayPal express filling the basket in
   `BeforePlaceOrder`. Not needed for a first PoC with registered users.
8. **Client assets.** Nothing from `assets/js` ships to a headless client; document the contract instead
   (`stripe.initEmbeddedCheckout({clientSecret})`, `redirect(url)`, or `stripe.initCheckout({clientSecret})` for
   `ui_mode=custom`; Stripe mobile SDKs accept the same Checkout Session client secret).
9. **Thank-you data.** Expose `orderNumber`, `contractState`, `paymentStatus` on the return mutation result so the
   client does not need a second query; core `order(id)` covers the rest.
10. **Tests.** Integration tests against `graphql-storefront` fixtures (`oxuserbaskets` → `stripeCheckoutStart` →
    fake Stripe return → `stripeCheckoutReturn`); webhook-commit test with a mocked paid `checkout.session.completed`;
    Playwright spec driving the mutations directly (no Twig) and the embedded sheet in a bare HTML page.

Admin, capture, refund, reconciliation, Connect/OAuth: **no changes.**

Prerequisite for the dev shop: `composer require oxid-esales/graphql-storefront:dev-b-7.4.x` (only `graphql-base`
is installed; the 2026-04-22 PayPal dev log notes the `reflection-docblock` pin fight when installing graphql-base,
expect the same for storefront) and activate `oe_graphql_base` + `oe_graphql_storefront`.

---

## 5. Risks and open questions

- **Price/basket parity.** The early order and the Stripe amount must come from the same `oxBasket` the storefront
  calculated for `basketPayments`; building it twice (storefront infrastructure vs our provider) is the main
  correctness risk. Prefer reusing `graphql-storefront`'s `BasketInfrastructure`/`BasketRelationService` to build it.
- **User basket deletion.** Core deletes the user basket in `placeOrder`; we must do the same on commit (and *not* on
  cancel, so the shopper can retry) — mirror `BeforeBasketRemoveOnPlaceOrder` semantics.
- **Anonymous JWT lifetime vs Stripe session lifetime.** Checkout Sessions live 24 h; if the anonymous token expires
  first the return mutation must still work — it can, because `contract_token` is the credential, not the JWT.
- **Open redirect** via client-supplied return URLs (item 5).
- **Two frontends, one module.** Every future Twig fix in `StripeOrderController` must land in the shared service,
  not the controller — exactly the OXID "extract a PaymentManager" advice. The OPC `StripePaymentHandler` already
  needs this refactor for its own session reads.
- **Docs mismatch:** payment-base `README.md` lists `src/GraphQL/ # Headless API support` but the directory does not
  exist; fix the README or make it true with item 1–3.
- Out of scope but adjacent: payment-base has an unused MCP/ACP agentic-commerce layer (`payment-base/src/Mcp/`,
  docs in `src/Mcp/docs/`). It is the only API-shaped checkout design in the codebase and shares the "basket by id,
  no session" requirement; items 1–3 would serve it too.

---

## 6. Suggested phasing

| Phase | Scope | Outcome |
|---|---|---|
| 0 | Install/activate `graphql-storefront` in the dev shop; run the documented `placeOrder` flow with `oxidpayadvance` | Baseline that headless core works on 7.4 |
| 1 | payment-base items 1–3 behind interfaces, Twig behaviour unchanged (regression: full e2e suite) | Session dependency removed from the order service |
| 2 | Stripe `stripeCheckoutStart` / `stripeCheckoutReturn` / `stripeCheckoutCancel`, registered users, hosted + embedded modes, `BeforePlaceOrder` guard | First headless Stripe payment end-to-end |
| 3 | Webhook-driven commit + stale-cleanup fix, `WebhookEventCatalog` update | Safe against lost return legs (benefits Twig too) |
| 4 | `ui_mode=custom`, anonymous/guest checkout, docs for storefront developers | Full headless parity |

---

## 7. Sources

- https://docs.oxid-esales.com/interfaces/graphql/en/latest/consuming/PlaceOrder.html
- https://docs.oxid-esales.com/interfaces/graphql/en/latest/thirdpartypayments/{introduction,standard_checkout,express_checkout,best_practices}.html
- https://docs.oxid-esales.com/interfaces/graphql/en/latest/events/{BeforePlaceOrder,BeforeBasketPayments}.html
- `OXID-eSales/graphql-storefront@b-7.4.x`: `src/Basket/Service/PlaceOrder.php`, `src/Basket/Infrastructure/Basket.php`, `src/Basket/Controller/Basket.php`
- Module code: `src/Stripe/Controller/StripeOrderController.php`, `src/Stripe/Controller/ControllerRequestHelper.php`,
  `src/Stripe/Service/CheckoutSessionService.php`, `src/Stripe/Webhook/Handler/*`, `src/Stripe/Service/WebhookEventCatalog.php`,
  `src/Stripe/PaymentHandler/StripePaymentHandler.php`; payment-base `src/Adapter/OxidShopOrderService.php`,
  `src/EventSystem/Handler/EarlyOrderCreationHandler.php`, `src/Controller/CheckoutReturnResponder.php`, `services.yaml:92-101`
- Stripe guidance: Checkout Sessions preferred over raw PaymentIntents; `ui_mode: custom` for Payment Element;
  never `payment_method_types`; fulfil from `checkout.session.completed` / `async_payment_succeeded`, not the success page.
