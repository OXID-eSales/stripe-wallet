# pay1.oxid.dev — log errors of 2026-10-20: sources and fixes

Scope: four `OXID Logger.ERROR` entries from `/var/www/source/log/oxideshop.log` on pay1
(compose project `/var/www/oxid/oxid75`). All investigation on the server was read-only —
nothing was changed, cleared or restarted.

| # | Error | Count in log | Last seen | Severity | Owner |
|---|-------|-------------:|-----------|----------|-------|
| 1 | `Basket "wishlist" not found for user …` | 102 | 15:24:34 | Noise (expected flow logged as ERROR) | oxid-fe-core |
| 2 | `Cannot query field "paymentClientConfig" on type "Query"` | 12 (all today) | 16:23:07 | **High** — sealed client config broken for every PSP | pay1 deploy pipeline |
| 3 | `Capture audit row skipped: contract has no captured amount` | 53 | 16:23:30 | **Medium** — real data gap in audit/refund bounds | stripe + payment-base |
| 4 | `Source::__construct(): Argument #1 ($body) must be of type string, null given` | 2 | 16:35:54 | Low — 500 on a malformed request | graphql-base (upstream) |

---

## 1. `Basket "wishlist" not found` — frontend uses an error as control flow

**Source**

- Request: `query savedList($title) { customer { basket(title: $title) { … } } }` with `title = "wishlist"`.
- Sent by `oxid-fe-core/src/account/savedLists.ts` (`GET` query, function `get()`), used by
  oxid-fe-user / hydroxid. In this API `noticelist` = wish list, `wishlist` = gift registry.
- graphql-storefront `Basket/Infrastructure/Repository::customerBasketByTitle()` (line 92) throws
  `BasketForUserNotFound` when the customer has no basket with that title — i.e. for **every
  customer who never created a gift registry**.
- oxid-fe-core deliberately catches it (`/not found/i` → `null`), so the UI is correct. But
  graphql-base's `GraphQLQueryHandler::getErrorFormatter()` logs **every** GraphQL error at
  `ERROR` level. `count()` also calls `get()`, so a single account page view can log it more
  than once.
- The e2e mock in `hydroxid-project/tests/e2e/helpers/mockShop.ts:60` already models this answer —
  it is known, expected behaviour.

**Impact:** none functionally. It floods the log (102 entries) and hides real errors.

**Solutions**

1. **Recommended (frontend, small):** stop asking for a basket that may not exist. Query the list
   relation, which never throws, and pick the basket by title on the client:
   ```graphql
   query savedLists { customer { baskets { id title public items { id amount product { id title } } } } }
   ```
   `Customer.baskets` exists in graphql-storefront (`Customer/Service/RelationService::getBaskets`).
   One request then serves both `noticelist` and `wishlist`, and `count()` can reuse it.
2. Lazy-load: only fetch `wishlist` (the gift registry) when its tab is opened. This cuts the volume
   but doesn't remove the error.
3. Server-side (only if the frontend can't change): a graphql-base log formatter that writes
   `NotFound`-type (client-safe) errors at `info`/`debug`. That is a change in graphql-base or an
   override of its logger, and it would also hide genuine 404s, so treat it as a secondary option.

---

## 2. `Cannot query field "paymentClientConfig"` — stale DI container after deploy

**Source:** reproduced live — `paymentClientConfig` is **absent from the running schema**, even
though the code is deployed.

Evidence on pay1:

| Artifact | Timestamp (UTC) | Content |
|---|---|---|
| `var/cache/container/container_cache_shop_1.php` | 2026-10-19 **12:32:51** | **0** references to `PaymentClientConfig` |
| `vendor/oxid-esales/payment-base/src/GraphQL/Controller/PaymentClientConfig.php` (`@since 1.4.0`) | 2026-10-19 **15:40:17** | present |
| `var/cache/oe_graphql_base-schema/` | 2026-10-19 **17:09:51** | built from the stale container |
| `var/configuration/shops/1/modules/oe_payment_base.yaml` | — | still says `version: 1.2.3` |

The 19 Oct deploy updated `vendor/` (payment-base 1.2.x → 1.4.x) but did **not** rebuild the
Symfony DI container. The new `PaymentClientConfig` controller service, registered in payment-base
`services.yaml:296`, therefore doesn't exist in the container. graphql-base then built and cached
a schema without the field. The module configuration was not refreshed either (the yaml still
reads 1.2.3).

Caller: `oxid-fe-core/src/payments/sealedConfig.ts:10`. The frontend can't get the sealed
Stripe/PayPal/Mollie publishable config, so headless checkout payment initialisation fails.
That's why it is graded High even though there are only 12 hits (one per checkout attempt).

**Why the deploy didn't fix it (found during sprint planning):** pay1 is deployed by the `deploy` job of
`stripe/.github/workflows/playwright-ci.yml`. That job runs `make php CMD="bin/oe-console …"`, but pay1's `Makefile`
`php:` target ignores `CMD` (it is just `docker compose exec php bash`), and `bin/oe-console` doesn't exist in the
container (`vendor/bin/oe-console` does). So the workflow's module install and `o:c:c` never ran, and the 19 Oct
update was done by hand without a cache clear. → Sprint 137, Story 3.

**Solutions**

1. **Immediate fix on pay1** (needs your go-ahead; not done):
   ```bash
   cd /var/www/oxid/oxid75
   docker compose exec php vendor/bin/oe-console oe:cache:clear
   docker compose exec php vendor/bin/oe-console oe:graphql:cache-clear
   # verify:
   curl -sk -H 'Host: pay1.oxid.dev' -H 'Content-Type: application/json' \
     https://127.0.0.1:8543/graphql/ \
     -d '{"query":"{ __type(name:\"Query\"){ fields { name } } }"}' | grep -c paymentClientConfig
   ```
   Re-running `oe:module:install vendor/oxid-esales/payment-base` (then the cache clears) also
   refreshes the stale `version: 1.2.3` module config.
2. **Permanent fix (deploy pipeline):** after `composer install/update`, always run
   `oe:module:install` for updated modules, then `oe:cache:clear` and `oe:graphql:cache-clear`, then
   a smoke check that introspects `Query` for `paymentClientConfig` and fails the deploy if it's
   missing. Without that step every module release that adds a GraphQL field or service repeats
   this incident.
3. Optional: if the PHP image has opcache with `validate_timestamps=0`, also restart `php` after
   the deploy.

---

## 3. `Capture audit row skipped: contract has no captured amount` — the webhook path never sets the captured amount

**Source**

- Logged by `stripe/src/Stripe/Webhook/Handler/WebhookContractFulfillmentHandler.php:63`
  (`handlePaymentSucceeded`).
- Flow for `payment_intent.succeeded` (automatic capture — the normal storefront case):
  `PaymentIntentSucceededWebhookHandler::handle()` → `commitOpenContract(…, amount_received …)` →
  `handlePaymentSucceeded()` → `ContractFulfillmentService::fulfill()` →
  `PaymentContract::fulfill()` → reads `$contract->getCapturedAmount()`.
- `PaymentContract::fulfill()` only switches the state and sets `fulfilledAt`.
  **`setCapturedAmount()` has no production caller** in stripe or payment-base. The only code that
  writes a captured amount is the manual admin capture path (`CaptureService` /
  `StripeCaptureRequestHandler`), which records it on the order and audit row.
- Before Sprint 133 S9 (commit `6868ec4`, "unknown amount stops masquerading as zero") this
  coalesced to `0.0` and silently wrote a "captured 0.00" audit row. F9 correctly made the gap loud;
  the missing write behind it was never closed.

**Impact on pay1 (`oe_payments_contract`):**

| State | Contracts | `OXCAPTUREDAMOUNT IS NULL` |
|---|---:|---:|
| fulfilled | 141 | **87** |
| committed | 202 | 202 |

So 87 paid contracts have no capture audit row and no captured amount. Anything that bounds
refunds by captured amount (`CaptureRefundTracker::remainingRefundable`, `AbstractPaymentRefundService`
line 155: `capturedAmount === null` → refuse/limit) will treat these contracts as "nothing captured".
Check partial-refund behaviour on these orders.

Example from the log: contract `0482e07b291fec68b88e3689823b7804`, intent `pi_3USdwiD514s7wzZp1jCoIVKo`.

**Solutions**

1. **Recommended fix (TDD, stripe module):** carry the amount from the webhook into the contract
   before it's fulfilled:
   - `PaymentIntentSucceededWebhookHandler` already parses `amount_received`. Pass it through
     `WebhookContractFulfillmentHandlerInterface::handlePaymentSucceeded(string $providerOrderId, ?float $amountReceived)`.
   - In the handler, if the contract is COMMITTED and has no captured amount, call
     `$contract->setCapturedAmount($amountReceived)` and save, then `recordAudit()`.
     The `CaptureRefundTracker::setCapturedAmount()` guard accepts COMMITTED and FULFILLED (it
     rejects only DRAFT/NOT_FINISHED and cancelled/expired/failed), so it is safe to set right after
     a successful `fulfill()`. It also rejects non-positive amounts, so skip it when `amount_received` is 0.
   - Keep the error log for the case where Stripe really sends no amount.
   - Tests: a unit test that `payment_intent.succeeded` with `amount_received=1999 EUR` → contract
     captured amount `19.99` + one capture transaction row. Plus a regression test that the log
     line is **not** emitted on the happy path.
2. Alternative (provider-agnostic, payment-base): add an optional `?float $capturedAmount` to
   `ContractFulfillmentService::fulfillByProviderOrderId()` so PayPal/Mollie get the same
   guarantee. This is cleaner, but it changes the interface, so it needs a payment-base minor release.
3. **Backfill the 87 contracts:** a one-off console command that, for FULFILLED Stripe contracts
   with `OXCAPTUREDAMOUNT IS NULL`, fetches the PaymentIntent (`amount_received`), sets the captured
   amount and writes the missing capture transaction. Run it in dry-run mode first.

---

## 4. `Source::__construct(): … null given` — request without a `query` reaches the executor

**Source**

- graphql-base `Framework/RequestReader::getGraphQLRequestData()` returns
  `['query' => $data['query'] ?? null, …]`, and `GraphQLQueryHandler::executeQuery()` passes it
  unchecked to `GraphQL::executeQuery()`. webonyx's `Source` requires a string → `TypeError` → HTTP 500.
- Trigger: any hit on `/graphql/` (`widget.php?cl=graphql`) without a query: a browser GET or
  bot/scanner probe, an empty POST, or a POST with a body that isn't JSON or doesn't match its
  `Content-Type` (`getJsonData()` returns `[]` when `json_decode` fails).
- The client couldn't be identified: the apache container doesn't write access logs (stdout has no
  request lines), and the host nginx access log shows no `/graphql` hit at 16:35:5x. Two hits look
  like a manual open or a probe, not the storefront.

**Solutions**

1. Upstream fix in graphql-base (report to OXID): validate in `executeGraphQLQuery()`. If `query`
   is not a non-empty string, return a GraphQL error response
   (`{"errors":[{"message":"Missing query"}]}`, HTTP 400) instead of throwing.
2. Until then, optional local guard: the nginx vhost on pay1 can return 400 for `GET /graphql`
   without a `query` argument. This is cheap but only a partial guard (it doesn't cover an empty POST).
3. Ops: enable apache access logging (or log `$request_body` length in nginx for `/graphql`), so
   future one-off 500s can be traced to a client.

---

## Recommended order

1. Clear the container and schema caches on pay1 (#2) — minutes, restores headless payment config.
2. Add cache-clear, module-install and the schema smoke check to the deploy pipeline (#2) — prevents a repeat.
3. Stripe fix + tests for the captured amount on `payment_intent.succeeded` (#3), then backfill the 87 contracts.
4. Change oxid-fe-core `savedLists` to `customer.baskets` (#1).
5. Upstream issue for the graphql-base null-query 500 (#4).
