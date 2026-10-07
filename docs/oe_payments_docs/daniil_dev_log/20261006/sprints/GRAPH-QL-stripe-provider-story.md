# GRAPH-QL — Stripe provider story (P-Stripe) — 2026-10-06

**Branch:** `b-7.4.x-GRAPH-QL` (stripe). **Requires** payment-base `b-7.4.x-GRAPH-QL` (Sprint 15, S1–S8 done — merged into
`b-7.4.x` before this lands; Stripe's CI installs payment-base from `b-7.4.x`). Checklist and seams:
payment-base `docs/dev_log/20261006/done/sprint-15-S8-gates-consumers-handover.md` and `…S6-headless-checkout-graphql-glue.md`.
**Requirements:** payment-base `docs/dev_log/20260903/sprints/_engeneering_requirements.md` apply (TDD-first, DevOps-first,
proven through the client, additive, agnostic where it can be).

## Ask

Stripe becomes usable from the GraphQL Storefront and from agents, through payment-base's headless checkout
(`HeadlessCheckoutServiceInterface`, `ContractCommitServiceInterface`, `AbstractAcpCheckoutService`): the three mutations
`stripeCheckoutStart / stripeCheckoutReturn / stripeCheckoutCancel`, a handler that works without a PHP session, webhooks
that commit a paid contract instead of waiting for the browser, and a cleanup that never cancels a paid session. The
Twig and OPC checkouts stay byte-identical.

## Stories

| # | Story | Red first | Green | Proof |
|---|---|---|---|---|
| PS1 | **Headless-ready handler.** `StripePaymentHandler implements ContractFirstPaymentHandlerInterface`; with `metadata.headless` it skips the in-flight reuse, builds the early order with `basketId` and `sessionId` from the context (no `Registry::getSession()` writes), stamps `basket_id` on the contract, and creates the Checkout Session with the client's `returnUrl` (+ `session_id={CHECKOUT_SESSION_ID}`) and `cancelUrl`; `uiMode` `hosted` → redirect, `embedded` → `ui_mode=embedded`, anything else refused (`STRIPE_UI_MODE_UNSUPPORTED`) before anything is created. Session reads/writes behind protected seams | `StripePaymentHandlerTest`: headless path never touches the session seams, OPC path still does; URLs, embedded flag, result `renderMode`, `basket_id`; unsupported uiMode | handler | unit; existing OPC tests unchanged |
| PS2 | **Wiring.** `- { name: oe.payment.return_resolver, provider: stripe }` on `StripeReturnResolver`; `$openAttemptFinder` on `EarlyOrderCreationHandler`; `checkout.session.completed` in `WebhookEventCatalog` | `WebhookEventCatalogTest` | services.yaml, catalog | container compiles (integration smoke) |
| PS3 | **Webhooks commit.** `payment_intent.succeeded` and `checkout.session.completed`: a contract that is PENDING (not committed) and paid → `ContractCommitServiceInterface::commit(PaymentConfirmation{stripe, pi, cs/pi, amount, currency, requiresCapture, source webhook})`, then the existing fulfilment; refused/pending outcomes logged, never thrown. `RetryCleanupService::cancelContractAndDeleteOrder()`: ask the in-flight guard **before** deleting the order, and a session Stripe reports as `paid` is committed (same service) instead of cancelled | handler tests with a scripted commit service; cleanup test: paid session → commit, no delete | handlers, cleanup, guard gets `paidSession()` | unit + webhook integration test with a fake paid event |
| PS4 | **GraphQL mutations.** `src/Stripe/GraphQL/Controller/StripeCheckout.php` (`#[Logged] #[Right('PAYMENT_CHECKOUT')]`): `stripeCheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl, uiMode)`, `stripeCheckoutReturn(contractId, contractToken, checkoutSessionId)`, `stripeCheckoutCancel(contractId, contractToken)` over `HeadlessCheckoutServiceInterface`, returning payment-base's `Checkout*Result`; `GraphQL\Service\NamespaceMapper`; services neither autowired nor autoconfigured (graphql-base optional); stubs in `tests/bootstrap-unit.php` + PhpStan bootstrap | `StripeCheckoutTest` over a mocked headless service + authentication; `NamespaceMapperTest` | controller, mapper, yaml | unit; integration: schema contains the mutations (graphql-base `SchemaFactory`) |
| PS5 | **ACP.** `Mcp\StripeAcpCheckoutService extends AbstractAcpCheckoutService` — `paymentId()`, `providerName()`, `completePayment()` charging the delegated token through the Stripe adapter, then `commitPaid()`; wired with the four headless collaborators | unit over a mocked adapter | service, yaml | unit |
| PS7 | **The order ends with the webhook.** Manual capture: `payment_intent.amount_capturable_updated` (`requires_capture`) commits the open contract with `requiresCapture` through payment-base's commit service; catalog + handler + CLI docs on how Stripe reaches the shop | `PaymentIntentAmountCapturableUpdatedCommitTest` | handler, catalog, abstract `requiresCapture` | order 794 committed by the replayed real event, no return leg |
| PS6 | **Proof.** Integration test through graphql-base's test harness: `token` → `basketCreate` → `basketSetPayment(oe_payments_stripe_wallet)` → `stripeCheckoutStart` (Stripe test API) → `stripeCheckoutReturn` after a scripted paid session; Playwright spec driving the mutations against the tunnelled shop and paying in Stripe's hosted test UI, if the e2e environment is available | — | — | the headless payment end-to-end |

## Decisions

- No new return-URL handling in Stripe: `returnUrl`/`cancelUrl` reach the handler already validated by payment-base's policy.
- `uiMode=custom` (Payment Element on a Checkout Session client secret) is phase 4 of the epic — refused for now.
- The module-local `SessionAdapterInterface` / `SessionWriterInterface` / `ContractServiceInterface` / dispatcher bindings
  stay in this story (payment-base defines the same ids; removing Stripe's is clean-up, done when both branches are merged).

## Done (2026-10-06, stripe `b-7.4.x-GRAPH-QL`)

PS1–PS7 delivered; reports in `../done/GRAPH-QL-PS*.md`, status in `../status.md`. The proof is the Playwright spec
`tests/e2e/GraphQL/stripe-headless-checkout.spec.ts` (e2e submodule, `projects/Stripe`): 4/4 through the GraphQL
mutations and Stripe's hosted test page. Found on the way and fixed: `cancel` answered the stale state (payment-base),
bot user agents get an empty basket (spec sends a browser UA), Stripe's Card accordion (page object), and — the one
that mattered for every shop — **module activation failed without graphql-base** because the glue implemented its
interfaces; the glue now mirrors them (payment-base `9ebf3a3`, Stripe PS6). Deferred: the harness integration test
(needs Stripe's API and a paid session; reason in the PS6 report), `uiMode=custom`, the MCP transport, and the
module-local session bindings clean-up after both branches merge. CI pin `f164517` is TEMPORARY.
