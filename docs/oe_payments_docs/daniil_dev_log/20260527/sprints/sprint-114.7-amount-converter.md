# Sprint 114.7 — `AmountConverter`: centralize cents math (incl. JPY/KRW)

**Module:** `extensions/stripe`
**Priority:** P2 (DRY + latent correctness bug for zero-decimal currencies)
**Findings:** D1
**Mode:** introduce 1 value object/service, route ~28 call sites, TDD-first. Multi-commit (introduce + migrate in batches).
**Depends on:** none. **Blocks:** 114.10 (DTOs reuse it), 114.12 (clean-code constants).
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-5.4** (no magic `100`), **R-9.3** (one converter, not 28 copies), **R-1.4** (EUR parity + JPY correction characterization tests).

## 1. Why

Major↔minor unit conversion (`* 100` / `/ 100`) is hand-coded at ~28 sites
across 14 files (verified):

`Adapter/Helper/PaymentIntentHelper.php`, `Adapter/Helper/RefundHelper.php`,
`Service/RefundService.php`, `Service/CheckoutSessionService.php`,
`Service/CheckoutReturnService.php`, `Service/Result/CheckoutReturnResult.php`,
`Controller/Admin/OrderRefundViewDataProvider.php`, `Model/Order.php`,
`Admin/StripePanelViewDataBuilder.php`,
`WebhookHandler/{ChargeRefunded,PaymentIntentSucceeded}Handler.php`,
`Webhook/StripeWebhookEventParser.php`, `Service/Result/SecurityValidationResult.php`.

Two problems:
1. **DRY** — one conversion rule reimplemented 28×; inconsistent (`round` vs truncate).
2. **Correctness** — hardcoded `100` is wrong for zero-decimal currencies (JPY, KRW, …) and three-decimal ones (BHD, KWD). Stripe expects the smallest currency unit; for JPY `¥1000` is `amount = 1000`, not `100000`.

Only `StripeChargeAmountResolver` uses a `CENTS_PER_UNIT` constant today — the model to generalize.

## 2. Goals

- **G1.** A single currency-aware converter:
  ```php
  final class AmountConverter {
      public function toMinorUnits(float $major, string $currency): int;
      public function toMajorUnits(int $minor, string $currency): float;
      public function decimalsFor(string $currency): int; // 2 default, 0 for JPY/KRW/…, 3 for BHD/KWD/…
  }
  ```
- **G2.** Zero-decimal and three-decimal currency tables encoded (per Stripe's documented lists).
- **G3.** All ~28 sites route through the converter; `grep -rEn "\* 100|/ 100" src/` returns only the converter (and any legitimate non-money use).
- **G4.** Rounding is consistent and explicit (`toMinorUnits` uses `round()` half-up; documented).
- **G5.** DI-injected where the site is a service; for value objects/handlers that can't easily inject, expose a small static factory or inject via constructor.
- **G6.** `./bin/pre-commit-check.sh --full` green; PHPStan level max clean.

## 3. TDD plan (RED first)

`AmountConverterTest` data-provider driven:
1. `19.99 EUR → 1999`, `1999 EUR → 19.99` (2 decimals).
2. `1000 JPY → 1000`, `1000 JPY ← 1000` (0 decimals) — the bug fix.
3. `1.234 BHD → 1234` (3 decimals).
4. Rounding edge: `0.1 + 0.2`-style float → correct minor units (no `0.30000001` drift).
5. Unknown currency → defaults to 2 decimals (documented fallback) + logs.
6. Per migrated site: a characterization test asserting the value is unchanged for EUR (so the refactor is provably behavior-preserving for the common case) and corrected for JPY.

## 4. Implementation steps

1. Build `AmountConverter` + tests (RED→GREEN). Decide home namespace — `Adapter/` (it's a Stripe-wire concern) or a shared `Service/`. Prefer `Adapter/` since "minor units" is a provider-wire detail.
2. Register in `services.yaml`.
3. Migrate in batches (one commit per batch, each with its characterization tests), in this order to limit blast radius:
   - Batch A — adapter helpers (`PaymentIntentHelper`, `RefundHelper`, `StripeWebhookEventParser`).
   - Batch B — services (`RefundService`, `CheckoutSessionService`, `CheckoutReturnService`, `Result/*`).
   - Batch C — admin/view/model (`OrderRefundViewDataProvider`, `StripePanelViewDataBuilder`, `Model/Order`).
   - Batch D — webhook handlers (coordinate with 114.4/114.8 if those refactored the handlers).
4. Fold `StripeChargeAmountResolver::CENTS_PER_UNIT` into the converter (or have the resolver consume it) to keep one source of truth.

## 5. Risks & rollback

- **Risk:** Stripe currency-exponent table drift. Source it from Stripe's published zero/three-decimal lists; add a unit test pinning the membership so future edits are deliberate.
- **Risk:** changing a truncating site to rounding alters a stored amount by 1 minor unit. The per-site characterization tests catch this; document any intentional change.
- **Risk:** view/model sites can't inject easily — provide a constructor seam (the testable-subclass pattern already used for `Order`).
- **Rollback:** per-batch commits; revert a batch without touching the converter.

## 6. Definition of Done

- G1–G6 met; `grep -rEn "\* 100|/ 100" src/` clean except the converter.
- JPY/KRW correctness proven by tests; EUR behavior proven unchanged.
- Completion report lists the 28 migrated sites with before/after.
