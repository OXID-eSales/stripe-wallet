# Proof — subsprint 139d and the stripe parts of 138b, 138d, 139a, 140b

**Date:** 2026-10-22 · **Repo:** stripe, branch `feature/graphql-comply`, CI green at `ba66286` (7.4 https://github.com/OXID-eSales/stripe-wallet/actions/runs/39756345019, 7.5 …/39756344997, 7.6 …/39756344891, secret scan …/39756344758) · **Spec 139d:** [`../done/sprint-139d-docs-vocabulary.md`](../done/sprint-139d-docs-vocabulary.md)

## Commits

| SHA       | What                                                                                                       |
| --------- | ---------------------------------------------------------------------------------------------------------- |
| `0e9971b` | 138b: `StripeClientConfigProvider` implements `AllowListedClientConfigProviderInterface` → `['publishableKey']` |
| `8b3482f` | 138d D2: fixed handler/result messages, Stripe text at debug with the contract id, `StripeCheckoutError` fixed sentence |
| `3652b90` | 139a A2/A5: merchant step + troubleshooting row, developer §1/§11, CLI script clears the cache first, CI line |
| `3b3fea0` | 139d: §13 third-party-payments models + basket removal, §14 versions, README link fixes                     |
| `320948d` | 140b: `#[Logged]` removed, `guest: GuestCheckoutInput`, factories, anonymous-access tests, CLI `guest` command |
| `2730ff5`, `0faa211`, `ba66286` | CI: TEMPORARY payment-base pin (`feature/graphql-comply`, alias ` as 1.3.x-dev`), guarded cache-clear, test autoload |

## 139d

§13 "How this relates to the OXID third-party-payments models": table (standard `3rdPartyStandardApprovalProcess` + `placeOrder`, express, own workflow = ours), why `placeOrder` is refused (`RefusePlaceOrderForContractFirstPayments` on `BeforePlaceOrder`, dispatched first by the storefront's `Basket\Service\PlaceOrder`), basket removal (`ContractCommittedEvent` → `UserBasketRemovalHandler`; `BeforeBasketRemoveOnPlaceOrder` is dispatched only in `Basket::placeOrder()`, never on our path; verified by grep over the storefront). §14 "Versions": graphql-base 12.0.2 / storefront 4.2.0 tested; what to check before 13 / 5. Every claim cites its class. Link check by script over the three README/guide files; two dead links in `docs/for_merchant/README.md` fixed. CHANGELOG entry.

## Stripe parts of the other subsprints

- **138d D2:** the leak was in `StripePaymentHandler` (provider text in the result message, which payment-base copies into the GraphQL error). Messages are fixed now; Stripe's text goes to `debug` with `contractId`/`providerMessage`; the error line carries the exception class and contract id. `testMessage_ProviderFailure_ContainsNoProviderText` + 5 more. **D4 run** on the SDK shop (hosted start, rejected origin, unsupported ui mode): on the message part of the new ERROR lines `cs_` 0, `session_id` 0, `uid=` 0, `contract_id` 0, `https://` 1 (the origin only, by D1 design). **Finding:** on the whole line each pattern hits once: graphql-base appends the exception with its trace and the container has `zend.exception_ignore_args=0`, so trace args contain the full query text (return URLs, and on return/cancel the contract token). Host fix: `zend.exception_ignore_args=On` (production default); note for pay1. Warning-level lines in `CheckoutReturnService::validateReturn` still carry `session_id`/`contract_id` (follow-up).
- **139a A2/A5:** `bin/graph-ql-cli-test.sh` clears the cache before its first query (`CACHE_CLEAR_CMD`, `SKIP_CACHE_CLEAR=1`); CI step guarded with `bin/oe-console list --raw | grep` because the CI shop does not activate graphql-base (no-op there). In this SDK the console is `bin/oe-console`; docs say `vendor/bin/oe-console` and mention the SDK path.
- **140b:** anonymous-access tests run a real GraphQLite schema (`tests/Integration/Stripe/GraphQL/StripeCheckoutAnonymousAccessTest.php`, 7 failures with `#[Logged]` restored). Input types `GuestCheckoutInput`/`GuestAddressInput` built by `#[Factory]` methods in `GuestCheckoutInputFactory` directly into payment-base VOs; VO refusals are rethrown as `StripeCheckoutError` inside the factories (they fire during argument resolution, before the controller's try). No `paymentId` argument: the controller hard-codes `oe_payments_stripe_wallet` and payment-base uses it when the basket row has none (the anonymous case). **Live:** anonymous token → `basketCreate` → `basketAddItem` → `stripeCheckoutStart` with guest details → contract `98d101dfd26fb8e9ce167bd70f590786`, order 1314, redirect to `checkout.stripe.com`; `oxuser` row passwordless, `OXRIGHTS=user`, no `OXREGISTER`; cancel by a different anonymous token succeeded, wrong token → `invalid_token`. Order 1314 has `OXDELCITY = OXBILLCITY` (Freiburg): the 140a delivery-address gap confirmed. No paid guest order yet (belongs with 140c).

## Proof

| Suite        | Before | After |
| ------------ | ------ | ----- |
| Unit         | 1687   | 1701  |
| Integration  | 103    | 118   |

38 pre-existing unit errors ("config.inc.php not found" in shop-bound tests) unchanged. PHPCS and PHPStan (level max, no baseline change) green. Pre-existing red in the local gate, untouched: PHPMD `StripeOrderController` TooManyMethods (27), and the gate's PHPUnit step autoload of `StubControllerRequestHelper`.

## Open

- Input types: stripe `GuestCheckoutInput`, mollie `MollieGuest…`, paypal `PayPalGuest…` — three copies; one shared input in payment-base is the DRY consolidation (planned next, before 140b closes).
- `composer.json` `payment-base >=v1.3` → `>=1.5` when released; CI pin back to `b-7.4.x` after the merge.
- Test guest users and baskets remain in the local SDK database.
