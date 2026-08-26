# 2026-08-26 — Stripe

## Done

- [Checkout return and container fixes](reports/01-checkout-return-and-container-fixes.md)
  — checkout was dead (`RetryCleanupService` private but fetched by id), then refused
  a **paid** return (`contract_mismatch`: the session pointer names only the last of
  several contracts a checkout creates). Both fixed; the return path now logs which of
  six checks refused. Includes why Mollie never hit either. Commits `9189b6f`,
  `bfac6ca`, `cbc174c`, `800dc9f`.

- [One checkout, one Stripe session](reports/02-duplicate-checkout-sessions.md) — the OPC
  handler prepared a new contract + early order + Stripe session on every accordion step
  while the stale-checkout cleanup cancelled whatever was in flight. Both now consult
  `CheckoutInFlightGuard`; five sessions per walkthrough down to two. Commits `745a193`,
  `9235f8c`.

## Open

- The last two sessions are one per checkout UI (OPC accordion, classic order page).
  Collapsing them is a decision about which UI owns the session.
- Stripe does not distinguish a pending return from a failed one; Mollie does.
- The stripe unit suite cannot be built in this shop (OXID class-chain recursion
  under PHPUnit) — run tests per file.
