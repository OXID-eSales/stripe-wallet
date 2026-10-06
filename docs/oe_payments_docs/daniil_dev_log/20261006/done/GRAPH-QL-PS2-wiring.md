# GRAPH-QL / PS2 — Wiring for the headless checkout (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-stripe-provider-story.md](../sprints/GRAPH-QL-stripe-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

| Change | Where | Why |
|---|---|---|
| `- { name: oe.payment.return_resolver, provider: stripe }` | `services.yaml`, `StripeReturnResolver` | payment-base's `ReturnResolverRegistry` hands the resolver to `CheckoutReturnResponder` for `stripeCheckoutReturn`, exactly as `StripeOrderController` does for the Twig return |
| `$openAttemptFinder: '@…Repository\OpenAttemptFinderInterface'` | `services.yaml`, `EarlyOrderCreationHandler` | a headless start with nothing in the open-attempt registry retires the NOT_FINISHED/PENDING contract for the same user basket (payment-base S3, interface split in S8) |
| `checkout.session.completed` | `WebhookEventCatalog` | the endpoint registrar subscribes to it; PS3 commits a paid session from this event |

## Red → green

`WebhookEventCatalogTest::testCatalogIncludesCheckoutSessionCompleted` (red → green); registrar tests unchanged.

## Proof

- Container compiles with the new tag attributes: Stripe Integration **100** green after `oe:cache:clear`.
- payment-base `HeadlessWiringTest` now runs all three cases in this shop (the contract-first guard case no longer
  skips): Stripe is the first provider payment-base's registry sees.

## Left in place on purpose

Stripe's own definitions of `SessionAdapterInterface`, `SessionWriterInterface`, `ContractServiceInterface`,
`EventListenerProviderInterface`, `EventDispatcherInterface`, `CheckoutReturnResponder` (without the scope argument).
payment-base defines the same ids with the same classes; Stripe's win while both exist. Removing them is a clean-up
for the merge, not a behaviour change — except `CheckoutReturnResponder`: Stripe's definition lacks `$scope`, so
`sess_challenge` is also written on a headless return (harmless: no session reads it). PS4 passes the responder from
payment-base's definition where it matters.
