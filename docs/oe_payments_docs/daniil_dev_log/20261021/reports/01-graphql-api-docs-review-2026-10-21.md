# Review against the OXID GraphQL API documentation (13.0) — stripe, mollie, payment-base, oxid-fe-*

**Date:** 2026-10-21 · **Source read:** `~/osc/strpwt7-nov26/oxapi-documentation`, branch `13.0-en`, all 50 pages
(specification, requests, authorization, filtering/sorting, caching, exceptions, modules, architecture,
development, consuming, third-party payments, troubleshooting) · **Examined:** stripe `b-7.4.x` (`f8113eb`,
v3.5.0-rc1), mollie `b-7.4.x`, payment-base `b-7.4.x`, oxid-fe-core `main` (0.9.2), oxid-fe-user `main`
(0.6.1), hydroxid-project `main`, the SDK shop (`graphql-base`/`-storefront` `dev-b-7.4.x`) and pay1's
`composer.json`.

## Verdict in one paragraph

Our headless implementation follows the documented model: JWT bearer auth, the `token` query sent
without a token, mutation/query naming, `ClientAware` errors with categories, final DataType facades
(Pattern A, no GraphQL interfaces), no hard dependency on the GraphQL modules, a module-owned
checkout workflow instead of `placeOrder` (explicitly allowed by the third-party-payments chapter).
Three things need updating because the documentation defines them differently or we never covered
them: the **schema cache** (`oe:graphql:cache-clear` is nowhere in our docs or ops steps),
**language/shop selection** (we rely on `Accept-Language`, the API contract is `?lang=`/`?shp=`),
and the **anonymous (guest) checkout**, for which the documentation describes the exact mechanism we
have been calling "phase 4". Refresh tokens are documented and unused by our frontends.

## Findings

| # | Topic (doc page) | What the documentation says | What we have | Status |
| --- | --- | --- | --- | --- |
| 1 | Schema cache (`caching.rst`) | GraphQLite's schema is cached on the file system and **not cleared automatically**; run `vendor/bin/oe-console oe:graphql:cache-clear` after adding/changing controllers or types. | Not mentioned in `docs/for_merchant/02-headless-storefronts.md`, `docs/for_developer/05-headless-graphql-checkout.md`, payment-base `docs/graphql-headless-checkout.md`, `bin/graph-ql-cli-test.md`, nor in the pay1 update steps given yesterday. A module update that adds `paymentClientConfig` + a re-activation **without** a cache clear can leave the old schema in place — a plausible second cause for yesterday's `Cannot query field "paymentClientConfig"` on pay1. | **Update needed** |
| 2 | Language and shop (`requests.rst`) | "Shop and language … will be taken from the environment after the OXID eShop resolved your hostname and parameters … give the `shp` and `lang` parameters in the query string: `/graphql/?shp=2&lang=1`." No mention of `Accept-Language`. | `oxid-fe-core` sends `Accept-Language: <locale>` and nothing else. It works because the shop core's `Language::detectLanguageByBrowser()` reads `HTTP_ACCEPT_LANGUAGE` (first two letters) when no `lang` parameter is present — shop auto-detection, not the API contract, and it cannot address a subshop. | **Update needed** |
| 3 | Rewrite rule (`install.rst`, `troubleshooting.rst`) | `RewriteRule ^graphql/?$ widget.php?cl=graphql&skipSession=1 [QSA,NC,L]` — `QSA` or `?lang=` is swallowed; `skipSession` or a PHP session may be created. | SDK shop `.htaccess` has exactly this rule. pay1's vhost not verified from here. | OK (verify pay1) |
| 4 | Anonymous token / guest checkout (`thirdpartypayments/express_checkout.rst`, `authorization.rst`) | `token` without credentials gives an anonymous JWT in the virtual group `oxidanonymous`; "the rights to manipulate a basket can be given to that virtual user group" by a module's own `PermissionProvider`; the storefront itself grants basket rights only to `oxidcustomer`, `oxidnotyetordered`, `oxidadmin`. | payment-base grants `oxidanonymous` only `PAYMENT_CLIENT_CONFIG`; the checkout mutations carry `#[Logged]` (false for anonymous users); oxid-fe-user's checkout routes require login. Guest checkout is the documented express pattern, not an unknown. | **Recommendation** (feature) |
| 5 | Refresh tokens (`authorization.rst`) | `login(username, password) { accessToken refreshToken }` + HttpOnly fingerprint cookie, `refresh(refreshToken, fingerprintHash)`; lifetimes in graphql-base settings; short access tokens recommended. | `oxid-fe-core` uses `token` only; a session ends at access-token expiry ("session expired" UI). The `AuthService` docblock already names this as planned. | **Recommendation** |
| 6 | `token` must not carry a token (`specification.rst`) | "the `token` query must not have a token sent with the HTTP request" | `AuthService` sends `token` with `omitToken: true` (sprint 14). | OK |
| 7 | Naming (`specification.rst`) | queries: nouns, no `get`; list queries: `filter/sort/limit/offset`; mutations: object + verb. | `stripeCheckoutStart/Return/Cancel`, `mollieCheckoutStart…`, `paypalCheckoutStart…`, query `paymentClientConfig`. | OK |
| 8 | Errors (`exceptions/`) | `ClientAware` + `ErrorCategories` (`permissionerror`, `requesterror`…); base exceptions `InvalidToken`, `NotFound`, … | `StripeCheckoutError` / `MollieCheckoutError` / `ContractFirstPaymentCheckout` extend graphql-base `Error`, category `REQUESTERROR`; we add `extensions.errorCode` / `providerCode` (our extension, not in the docs — fine, documented in our guide). | OK |
| 9 | `@HideIfUnauthorized` (`authorization.rst`) | must not be used | not used anywhere | OK |
| 10 | Rights mapping (`authorization.rst`) | `PermissionProvider` + tag `graphql_permission_provider` | payment-base and modules do exactly this, mirroring the interface without implementing it (optional dependency). | OK |
| 11 | Architecture (`architecture/`) | hexagonal, DataTypes as final facades, Pattern A | result types in payment-base are `final`, no GraphQL interfaces | OK |
| 12 | Third-party payments (`thirdpartypayments/`) | standard (`3rdPartyApproval` + `placeOrder` with `BeforePlaceOrder`) or express; "a 3rd party payment module could come with its completely own implementation of checkout workflow"; no hard GraphQL dependency; no session reliance | ours is the "own workflow": contract-first `<provider>CheckoutStart/Return/Cancel`, `placeOrder` refused for those baskets via the `BeforePlaceOrder` subscriber; `@?` services; headless metadata instead of session | OK — add a short mapping of our terms to the documented ones in `05-headless-graphql-checkout.md` |
| 13 | Basket removal (`consuming/PlaceOrder.rst`, `events/BeforeBasketRemoveOnPlaceOrder`) | the ordered basket is removed on order creation unless a module prevents it | our `UserBasketRemovalHandler` removes the basket on contract commit — same semantics for the contract-first path | OK (state it in the dev guide) |
| 14 | Extending types (`development/extending-types.rst`) | add fields to foreign types with `@ExtendType` (register in the namespace mapper's type mapping + DI by FQCN) | not used. Opportunity: expose the payment contract on the storefront `Order` type (`order { paymentContract { state provider } }`) so headless order lists can show payment state without a second API | **Recommendation** |
| 15 | Integration tests (`development/overview.rst`) | graphql-base `TestCase` / `TokenTestCase` (`phpunit -c vendor/oxid-esales/graphql-base/tests/phpunit.xml`) | our GraphQL tests are hand-rolled (schema tests, controller tests with fakes); 0 uses of `TokenTestCase` | optional |
| 16 | CORS (`troubleshooting.rst`) | Apache config for cross-origin frontends | our frontends reach `/graphql/` same-origin through nginx on pay1 and the SDK (no CORS needed); hydroxid's docs mention CORS for other setups | OK |
| 17 | Versions (`install.rst`) | docs target `graphql-base ^13`, `graphql-storefront ~5.0`, shop ≥ 7.5.1 (7.5.0 compilation); migrations after install/update; activate `oe_graphql_base` first | shops run `dev-b-7.4.x` of both (12.x / 4.x line) on the 7.5 compilation; nothing of ours is pinned to 13/5 | note — check breaking changes before moving to 13/5 |

## Updates to make (in priority order)

1. **Schema cache clear — docs + ops** (stripe, payment-base, mollie, oxid-fe-user):
   - stripe `docs/for_merchant/02-headless-storefronts.md`: setup checklist step 2 and the troubleshooting
     row for `Cannot query field …` get `vendor/bin/oe-console oe:graphql:cache-clear` after activating
     or updating any module with GraphQL controllers (payment-base, stripe, mollie).
   - stripe `docs/for_developer/05-headless-graphql-checkout.md` §1 and §11; `bin/graph-ql-cli-test.md`
     setup; payment-base `docs/graphql-headless-checkout.md`; mollie's headless docs if present.
   - pay1 update procedure (composer update → re-activate modules → **cache-clear**); record it in
     hydroxid's `docs/ci.md` pay1 section.
   - CI: fresh containers have no cache, so nothing to change; optional safety step after activation.
2. **Language/shop selection in `oxid-fe-core`** (core → user → hydroxid): `GraphqlClient` appends
   `?lang=<id>` (and `?shp=<id>` when configured) to the endpoint, mapping the storefront locale to the
   shop's language id via a small instance setting (e.g. `shop.language_ids: { de: 0, en: 1 }`, default
   for the demo shop), keeping `Accept-Language` for shops that auto-detect. Pin with the existing
   `shop-language.spec.ts`. Reason: the documented contract, subshop support, and independence from the
   shop's auto-detection setting.
3. **Guest checkout** (payment-base + the three modules + oxid-fe-user) — the documented express
   pattern: a `PermissionProvider` entry for `oxidanonymous` with the storefront basket rights
   (`CREATE_BASKET`, `VIEW_BASKET`, `ADD_PRODUCT_TO_BASKET`, `REMOVE_BASKET_PRODUCT`, `ADD_VOUCHER`,
   `REMOVE_VOUCHER`) plus `PAYMENT_CHECKOUT`; drop `#[Logged]` from `<provider>CheckoutStart/Return/Cancel`
   (keep `#[Right]`); `HeadlessCheckoutService::start()` must create or match the shop user for an
   anonymous token (today `user_not_found`); the frontend's checkout routes stop requiring login and ask
   for e-mail + addresses instead. A sprint of its own.
4. **Refresh tokens in `oxid-fe-core`**: `login` → `accessToken` + `refreshToken`, `refresh` before
   expiry (`exp` claim), fingerprint cookie works same-origin; shorter access-token lifetime in
   graphql-base settings afterwards.
5. **`@ExtendType` on `Order`** in payment-base: `paymentContract { state provider orderNumber }`
   for order lists in headless frontends (today the frontend only sees OXID's order fields).
6. **Docs wording**: in `05-headless-graphql-checkout.md` add one paragraph mapping our flow to the
   documentation's "standard / express / own workflow" terms and the basket-removal semantics (#13).

## Verified as compliant (no change)

Items 3 (SDK), 6, 7, 8, 9, 10, 11, 12, 13, 16 above.

## Not verified

- pay1's Apache/nginx rewrite for `/graphql/` (`QSA`, `skipSession=1`) — needs a look on the host.
- Whether yesterday's missing `paymentClientConfig` on pay1 was the module version or a stale schema
  cache; after the next update on pay1 run the cache clear before concluding.
- Breaking changes between graphql-base 12 → 13 / storefront 4 → 5; not read (the local docs clone is the
  13.0 text only).
