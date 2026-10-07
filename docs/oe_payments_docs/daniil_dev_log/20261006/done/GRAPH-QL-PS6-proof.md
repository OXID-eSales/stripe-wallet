# GRAPH-QL / PS6 — Proof: the headless Stripe checkout end-to-end (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-stripe-provider-story.md](../sprints/GRAPH-QL-stripe-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What was proven

A real shopper flow with **no Twig page involved**, against the dev shop (GraphQL over `http://localhost.local/graphql/`,
Stripe test mode, hosted page reached through the tunnel `https://daniil.oxiddev.de/`):

| Playwright test (`tests/e2e/GraphQL/stripe-headless-checkout.spec.ts`, e2e submodule branch `projects/Stripe`) | Proves |
|---|---|
| start → pay on Stripe → return commits the order | `token` → `basketCreate` → `basketAddItem` → `stripeCheckoutStart(hosted)` answers contract id/token, order number and Stripe's `redirectUrl`; the browser pays 4242 on the hosted page; Stripe navigates to the client's `returnUrl?session_id=…` (intercepted, the URL need not exist); `stripeCheckoutReturn` answers `committed: true`, state `committed`/`fulfilled`, the order number |
| embedded mode answers a client secret instead of a redirect | `uiMode: embedded` → `clientSecret` + `renderMode: embedded`, no `redirectUrl`; `stripeCheckoutCancel` retires it (`cancelled: true`, state `cancelled`) |
| cancel retires an unpaid attempt; a wrong token is refused | cancel of an unpaid hosted attempt; a wrong token is a GraphQL error (`CONTRACT_TOKEN_INVALID` category); `stripeCheckoutReturn` on the cancelled contract is refused |
| core placeOrder is refused for a Stripe basket and points at the mutation | payment-base's `BeforePlaceOrder` guard: `placeOrder` on a basket paying with `oe_payments_stripe_wallet` is refused and the error names `stripeCheckoutStart` |

Result: **4 passed (21.7 s)**. Run: `cd tests/e2e/playwright/playwright && SHOP_URL=https://daniil.oxiddev.de/
HEADLESS_GRAPHQL_URL=http://localhost.local/graphql/ PW_VIDEO=off npx playwright test
tests/e2e/GraphQL/stripe-headless-checkout.spec.ts --project=chromium --reporter=line`.

Env of the spec: `SHOP_URL`, `HEADLESS_GRAPHQL_URL` (default `${SHOP_URL}graphql/`), `HEADLESS_USER_EMAIL` /
`HEADLESS_USER_PASSWORD` (default `headless.user@oxid-esales.dev` / `useruser`), `HEADLESS_PRODUCT_ID` (default
"Panorama", 20.90 EUR). The shop's origin is always an allowed return origin, so `returnUrl`/`cancelUrl` under it pass
payment-base's policy without a setting.

## What the proof found (fixed on the way)

| Found | Fix |
|---|---|
| **curl / bot user agents get an empty basket.** OXID treats them as search engines: basket total 0, no delivery set, `stripeCheckoutStart` fails with "no delivery". | The spec sends a browser-like `User-Agent`, as any real headless client does. Documented in the spec header. |
| **`playwright.user@…` lives in Switzerland** (no delivery set in the dev shop) | A second customer `headless.user@oxid-esales.dev` (Germany, group `oxidcustomer`, `oxid headless_e2e_user_000000000001`) created in the dev DB; the spec's default user. |
| **`basketSetPayment` is not needed for the provider mutation.** The client had to know the storefront's payment-method dance before it could call Stripe's own mutation. | payment-base `c4cb4a3`: `HeadlessStartRequest::$paymentId`; the Stripe mutation passes `oe_payments_stripe_wallet`. A basket set to another payment is refused (`PAYMENT_NOT_SUPPORTED`). |
| **`stripeCheckoutCancel` answered `contractState: pending`** for a contract it had just cancelled (`cancelled: true`). `PreviousCheckoutAttemptCleaner` cancels its own loaded copy; the service answered from the stale instance it held for the token check. | payment-base `9ebf3a3`: `HeadlessCheckoutService::cancel()` reloads the contract after the cleanup; unit test. |
| **Stripe's hosted page changed:** Card is an accordion item (Klarna / Link offered), no "Card" radio — `StripeCheckoutPage.fillTestCard` never found the inputs. | `StripeCheckoutPage.selectCardPaymentMethod` handles radio, accordion and card-only layouts (e2e submodule). |
| **payment-base's CI was red since S6 — module activation died on a shop without GraphQL** (`Interface NamespaceMapperInterface not found` while the validator compiles the container; Symfony reflects every service class). | payment-base `9ebf3a3` and Stripe (this story): `NamespaceMapper` (and payment-base's `PermissionProvider`) mirror the graphql-base interfaces **without implementing them**; graphql-base only iterates the tagged services. `Unit\Stripe\GraphQL\Service\OptionalGraphQlDependencyTest` pins the method parity and asserts that no service class the container reflects extends/implements anything from graphql-base or GraphQLite. |

## Scope note — the harness integration test

The sprint row also named an integration test through graphql-base's test harness ending in `stripeCheckoutReturn`
after a scripted paid session. Not written: a real `stripeCheckoutStart` needs Stripe's test API (keys CI does not
have) and a paid Checkout Session cannot be scripted without the browser; a fully mocked variant would only re-prove
what the PS4 unit tests and `SchemaContainsStripeMutationsTest` already cover (controller over a mocked headless
service; the mutations are in the schema). The Playwright spec is the end-to-end proof; it needs the e2e environment
(tunnel + Stripe test keys) and is not part of the module's CI.

## Gates

- Unit (standalone) **1624** green (new: `OptionalGraphQlDependencyTest` 3) · payment-base Unit 1557 / Integration 148 green
- PHPStan level max No errors · phpcs clean · phpmd clean (both modules)
- payment-base GitHub Actions on `b-7.4.x-GRAPH-QL`: see status (must be green before Stripe's CI runs against it —
  Stripe's workflows install payment-base from that branch for now, commit `f164517`, TEMPORARY, revert after the merge).
