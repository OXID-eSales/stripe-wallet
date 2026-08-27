# 2026-08-26 — Stripe

**Day summary: [reports/03-day-summary.md](reports/03-day-summary.md)** — three latent defects exposed by a container recompile, plus one template trap.
CI green on 7.4 and 7.5.

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

- [One embedded sheet per page](reports/04-embedded-single-mount.md) — the order page's
  host called `stripe.initEmbeddedCheckout()` directly, bypassing the OPC-132 registry the
  footer widget serialises through; collisions showed the shopper Stripe's own
  `IntegrationError` text. **Corrected 2026-08-27** (report §8): sharing the *creation*
  put the sheet in the footer's zero-height container and left the order page unpayable,
  so ownership was reversed — the order page mounts its own sheet and the footer stands
  down there. Commits `a88bfd4`, `68f70b1`, `169bc95`.

## Open

- The last two sessions are one per checkout UI (OPC accordion, classic order page).
  Collapsing them is a decision about which UI owns the session.
- Stripe does not distinguish a pending return from a failed one; Mollie does.
- ~~Two PSP widgets on one order page~~ — **checked, not a defect.** Each provider's
  widget renders only for its own order (Stripe order: 0 Mollie hosts, 1 Stripe sheet;
  Mollie order: 1 Mollie host, 0 Stripe sheets), and Mollie's own single-mount spec
  passes. See report 04 §6.
- The stripe unit suite cannot be built in this shop (OXID class-chain recursion
  under PHPUnit) — run tests per file.
