# GRAPH-QL / PS4 — `stripeCheckoutStart` / `stripeCheckoutReturn` / `stripeCheckoutCancel` (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-stripe-provider-story.md](../sprints/GRAPH-QL-stripe-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## The mutations (Option B — core `placeOrder` is not used for Stripe baskets)

```graphql
mutation { stripeCheckoutStart(basketId: "…", confirmTermsAndConditions: true,
                               returnUrl: "https://app.example.com/return", cancelUrl: "https://app.example.com/cancel",
                               uiMode: "hosted") { contractId contractToken providerName orderNumber redirectUrl clientSecret renderMode } }
# shopper pays; Stripe sends them to returnUrl?session_id=cs_…
mutation { stripeCheckoutReturn(contractId: "…", contractToken: "…", checkoutSessionId: "cs_…") { status orderId orderNumber contractState } }
mutation { stripeCheckoutCancel(contractId: "…", contractToken: "…") { cancelled contractId contractState } }
```

All three: `#[Logged]`, `#[Right('PAYMENT_CHECKOUT')]` (payment-base's permission provider grants it to `oxidcustomer`,
`oxidnotyetordered`, `oxidadmin`).

## What changed

| Piece | Job |
|---|---|
| `GraphQL\Controller\StripeCheckout` | the JWT user (`Authentication::getUser()->id()`) is the buyer; `start` → `HeadlessStartRequest`; `return` → `providerParams{checkoutSessionId, contract_token}` where `contract_token` is a Stripe token minted here (`ContractTokenService`) because `StripeReturnResolver` demands one and payment-base already verified its headless token; `cancel` → delegation. Results are payment-base's `Checkout*Result` types |
| `GraphQL\Exception\StripeCheckoutError` | graphql-base `Error`: client-safe, request-error category, `extensions{errorCode, providerCode}` from `HeadlessCheckoutException` |
| `GraphQL\Service\NamespaceMapper` (`graphql_namespace_mapper`) | controller namespace only — the types are payment-base's |
| `services.yaml` | controller `public`, neither autowired nor autoconfigured, `$authentication: '@?…Authentication'` (optional reference) — Stripe boots without graphql-base. **Lesson:** the first attempt appended the block after the file's `parameters:` section; Symfony then treated the controller as a parameter and the whole container failed to dump. The block sits under `services:` now |
| `tests/bootstrap-unit.php`, `tests/PhpStan/phpstan-bootstrap.php` | stubs for GraphQLite attributes / `ID`, graphql-base `Authentication`, `DataType\User`, `Exception\Error` (+ `isClientSafe()`), `ErrorCategories`, `NamespaceMapperInterface` |

## Red → green

- `Unit\Stripe\GraphQL\Controller\StripeCheckoutTest` (7): start arguments + JWT user, hosted default without cancel URL,
  refusal → client-safe error with the stable code, return passes session id + fresh Stripe token, return without a
  session id, cancel, invalid token → error
- `Unit\Stripe\GraphQL\Service\NamespaceMapperTest` (1)
- `Integration\Stripe\GraphQL\SchemaContainsStripeMutationsTest` (2): the controller resolves from the real container;
  GraphQLite builds a valid schema over the controller + payment-base's types with the three mutations, the documented
  argument order and the `CheckoutStartResult` fields

## Gates

- Unit (standalone) **1615** green · Integration (`--testsuite Integration`) **102** green, 1 skip
- PHPStan level max No errors · phpcs clean · phpmd clean

## Dev-shop finding (not Stripe's)

GraphQLite's default composer-classmap class finder reflects every class under a namespace prefix; in this shop that
re-enters OXID's module-chain autoloader for the `payment` controller, whose chain cannot be built
(`OxidEsales\Payments\Mollie\Controller\PaymentController_parent` not found — the Mollie module's checkout is mid-change
on its own branch). The same break stops Stripe's `tests/phpunit.xml` Unit suite and one OPC test (noted in payment-base's
S8 report). The schema proof therefore uses a fixed class list as GraphQLite's finder. The real GraphQL endpoint in this
dev shop is affected by the same chain break until the Mollie module is activated cleanly — PS6 depends on that.

## Follow-up (2026-10-06, CI)

`Integration\Stripe\GraphQL\SchemaContainsStripeMutationsTest` killed the whole Integration job on the bare CI shop
(`Interface "Kcs\ClassFinder\Finder\FinderInterface" not found`, exit 255): the class-finder stand-in was a named
class at file level, loaded before `setUp()` could skip. It is an anonymous class built after the guard now. Same
lesson as the `NamespaceMapper` glue (PS6 report): nothing loaded on a shop without graphql-base may implement its
interfaces at declaration time.
