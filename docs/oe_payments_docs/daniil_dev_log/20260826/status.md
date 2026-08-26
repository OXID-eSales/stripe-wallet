# 2026-08-26 — Stripe

## Done

- [Checkout return and container fixes](reports/01-checkout-return-and-container-fixes.md)
  — checkout was dead (`RetryCleanupService` private but fetched by id), then refused
  a **paid** return (`contract_mismatch`: the session pointer names only the last of
  several contracts a checkout creates). Both fixed; the return path now logs which of
  six checks refused. Includes why Mollie never hit either. Commits `9189b6f`,
  `bfac6ca`, `cbc174c`, `800dc9f`.

## Open

- Duplicate checkout sessions per order-page load: `createCheckoutSession()` and
  `StripePaymentHandler` both create them, each with a contract and an early order.
  No longer able to cost a payment, still wasteful. Needs a sprint.
- Stripe does not distinguish a pending return from a failed one; Mollie does.
- The stripe unit suite cannot be built in this shop (OXID class-chain recursion
  under PHPUnit) — run tests per file.
