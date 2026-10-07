# GRAPH-QL / PS3 — Webhooks commit a paid contract; cleanup asks Stripe first (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-stripe-provider-story.md](../sprints/GRAPH-QL-stripe-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## The problem

Until now the only path from PENDING to COMMITTED was the browser return leg; `payment_intent.succeeded` and
`checkout.session.completed` skipped a contract that was "not in COMMITTED state" and waited. And
`RetryCleanupService::cancelContractAndDeleteOrder()` deleted the NOT_FINISHED order **before** consulting the in-flight
guard, which answers null for a paid session — so a shopper who paid but never reached the return leg lost the order
(money taken, no order; the hazard from the 2026-10-01 feasibility report).

## What changed

| Piece | Change |
|---|---|
| `AbstractStripeWebhookHandler` | optional 5th ctor arg `ContractCommitServiceInterface`; `commitOpenContract(contract, authorizationId, providerOrderId, amount, currency, extra)` — open contract + amount ⇒ `PaymentConfirmation{stripe, …, source: webhook}` through payment-base's commit service; settled ⇒ continue, pending ⇒ `skipped`, refused ⇒ `failure('commit_refused', …)`; no service / no contract / settled / no amount ⇒ continue as before |
| `PaymentIntentSucceededWebhookHandler` | finds the contract by intent id, else by metadata `contract_id`; commits from `amount_received` (fallback `amount`) + `currency` **before** the existing fulfilment |
| `CheckoutSessionCompletedWebhookHandler` | after the provider-id swap: `payment_status = paid` ⇒ commit from `amount_total` + `currency`, session id as provider order id, `checkoutSessionId` in the context; then fulfilment as before |
| `CheckoutInFlightGuard::sessionOf(contract)` | public: the contract's Stripe session whatever its payment status (null when Stripe cannot answer) |
| `RetryCleanupService` | optional 5th ctor arg `ContractCommitServiceInterface`; **order of operations fixed**: (1) a session Stripe reports as `paid` ⇒ commit (`source: cleanup`) — or, without a commit service, error-logged and left alone — never cancelled; (2) a usable unpaid session ⇒ kept; (3) only then delete the order and cancel |
| `services.yaml` | the two handlers and the cleanup get `$contractCommit` |

## Red → green

- `PaymentIntentSucceededCommitTest` (5): PENDING + paid intent ⇒ committed then fulfilled; found via metadata only ⇒ committed; already committed ⇒ not again; refused ⇒ `failure` and no fulfilment; no commit service ⇒ old `skipped`
- `CheckoutSessionCompletedCommitTest` (4): paid ⇒ committed + fulfilled with `cs_1`/`pi_1`; unpaid ⇒ nothing committed; settled ⇒ not again; refused ⇒ failure
- `RetryCleanupServicePaidSessionTest` (4): paid ⇒ committed, no delete, not cancelled, returns false; usable unpaid ⇒ kept, no delete; no usable session ⇒ retired as before; paid without a commit service ⇒ left alone
- Existing `PaymentIntentSucceededWebhookHandlerTest`, `CheckoutSessionCompletedWebhookHandlerTest`, `RetryCleanupServiceTest` unchanged and green

## Gates

- Unit (standalone) **1607** green (8 warnings / 22 deprecations pre-existing) · Integration (shop PHPUnit) **100** green, 1 skip (container compiles with the new arguments)
- PHPStan level max No errors · phpcs clean · phpmd clean

## Notes

- `requiresCapture` is `false` in both webhook commits: `payment_intent.succeeded` means captured, and a Checkout
  Session with `payment_status = paid` is captured. Manual-capture authorizations arrive as
  `payment_intent.amount_capturable_updated`, which this module does not subscribe to — the return leg handles them
  as before (follow-up if a headless manual-capture flow is wanted).
- The commit's refusal (`amount_mismatch`, `contract_cancelled`) is a webhook *failure*, so Stripe retries and the
  merchant sees it in the webhook log; it is never swallowed.
