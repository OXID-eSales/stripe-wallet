# Sprint 114.10 — Provider-agnostic boundary: map Stripe SDK types to DTOs

**Module:** `extensions/stripe` (+ payment-base for the value objects)
**Priority:** P3 (structural — the largest architectural fix)
**Findings:** A1 (raw `\Stripe\*` leaks through adapter interfaces), A2 (agnostic vocabulary owned by Stripe), A3 (refund handler reaches into OXID `Order`), A4 (`'other'` literal), L3 (`instanceof PaymentContract` downcast)
**Mode:** phased, multi-commit, TDD-first. This is the headline structural sprint — sequence its phases.
**Depends on:** 114.7 (DTOs carry amounts via `AmountConverter`). **Blocks:** 114.11.
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-2.3** (narrow agnostic interfaces — no `\Stripe\*` past the adapter), **R-3.2** (add `fail()/cancel()` to the interface, drop the downcast), **R-7.4** (admin+storefront converge).

## 1. Why

The adapter is supposed to be the boundary that converts Stripe SDK objects
into provider-neutral DTOs. Today raw SDK types leak across the whole module:

- **A1** — `StripeCheckoutAdapterInterface` returns `\Stripe\Checkout\Session`, `StripePaymentIntentAdapterInterface` returns `\Stripe\PaymentIntent`, `StripeRefundAdapterInterface` returns `\Stripe\Refund`, `StripeCustomerAdapterInterface` returns `\Stripe\Customer`/`\Stripe\Charge`. Consumers — `CheckoutSessionService`, `RefundService`, `OrderRefundViewDataProvider` (`use Stripe\Charge; use Stripe\PaymentIntent;`), and even `Model/Order.php` — read `$charge->refunds->data`, `$pi->latest_charge`, coupling the service/admin/model layers to Stripe SDK v19 shape.
- **A2** — `StripeStatusMapper` declares "normalized, used across all providers" status constants *inside* the Stripe layer; other providers can't share them.
- **A3** — `StripeRefundRequestHandler:101-126` does `oxNew(Order::class)` + `$order->oxorder__oxtransid->value` to get the PI id, while capture/cancel use the agnostic `ContractRepository::getProviderOrderId`.
- **A4** — `Payment::getPaymentProvider()` returns the literal `'other'`.
- **L3** — `WebhookContractFulfillmentHandler` downcasts `instanceof PaymentContract` to call `fail()/cancel()` because `PaymentContractInterface` lacks them.

## 2. Goals

- **G1. Neutral DTOs** in payment-base (or a shared namespace): e.g. `ChargeSummary` (captured/refunded/amount in minor units + currency + status), `TransactionView`, `CheckoutSessionResult`, `RefundResult`, `CustomerResult`. They carry only primitives/enums — no `\Stripe\*`.
- **G2.** Adapter interfaces return DTOs, not SDK types. Raw `\Stripe\*` lives **only** inside `src/Stripe/Adapter/**` (mapping happens there).
- **G3.** `grep -rn "use Stripe\\\\" src/` shows matches **only** under `Adapter/` (and the SDK client factory). No `\Stripe\*` in `Service/`, `Controller/`, `Model/`, `EventSystem/` consumers.
- **G4. A2** — move normalized-status constants to payment-base; `StripeStatusMapper` keeps only Stripe→normalized mapping and consumes the shared constants.
- **G5. A3** — `StripeRefundRequestHandler` resolves the PI id via `ContractRepository`/the `PaymentIntentResolver` from 114.8; the `oxNew(Order)` + magic-field read is gone.
- **G6. A4** — `StripeDefinitions::PROVIDER_OTHER` replaces the `'other'` literal.
- **G7. L3** — add `fail(string $reason)` / `cancel(...)` to `PaymentContractInterface` (payment-base); drop the `instanceof PaymentContract` downcasts.
- **G8.** `./bin/pre-commit-check.sh --full` green across stripe AND payment-base consumers (paypal, one-page-checkout) — this touches the shared package.

## 3. Phasing (each a commit, TDD-first)

1. **DTOs + mappers (additive, no consumer change yet).** Define DTOs; add adapter mapping methods returning them alongside the existing SDK-returning ones. Tests: mapper converts a fixture SDK object → DTO with correct minor-unit amounts (via `AmountConverter`).
2. **Migrate read consumers** — `OrderRefundViewDataProvider`, `StripePanelViewDataBuilder`, `Model/Order` move to `ChargeSummary`/`TransactionView`. Tests assert the same displayed numbers (characterization).
3. **Migrate write/flow consumers** — `CheckoutSessionService`, `RefundService` consume DTOs.
4. **Flip the interfaces** — change adapter interface return types to DTOs; remove the SDK-returning duplicates. Now `grep` (G3) must be clean.
5. **A2/A4/G7** — move status constants; replace `'other'`; extend `PaymentContractInterface` + drop downcasts (coordinate with payment-base; bump its consumers).
6. **A3** — route refund handler through the agnostic resolver (depends on 114.8 `PaymentIntentResolver`).

## 4. TDD plan

- DTO mapper tests with real Stripe SDK fixtures (recorded JSON → `\Stripe\Charge::constructFrom(...)`), asserting DTO fields incl. JPY (no `/100` drift).
- Characterization tests on the admin transaction-history + refund-remaining numbers before/after each consumer migration — must match for EUR, corrected for JPY.
- `PaymentContractInterface` contract test: `fail()/cancel()` available on the interface; the downcast removal compiles under PHPStan max.

## 5. Risks & rollback

- **Risk (high):** payment-base is a shared package (`type: oxideshop-module`) consumed by paypal + one-page-checkout. Interface/constant moves there must keep those consumers green — run their suites, not just stripe's. Do NOT revert payment-base to composer-plugin (see memory).
- **Risk:** Stripe SDK v19 field access (`latest_charge`, `refunds->data`) currently spread out — centralizing in mappers is the win, but verify each field is read in exactly one place post-migration.
- **Risk:** scope creep — this is the biggest sprint. The 6-phase split lets each phase ship independently; phases 1-3 are reversible without touching interfaces.
- **Rollback:** phase commits; phase 4 (interface flip) is the point of no easy return — gate it behind phases 1-3 being merged + soaked.

## 6. Definition of Done

- G1–G8 met; `grep -rn "use Stripe\\\\" src/` confined to `Adapter/`.
- payment-base consumers (paypal, one-page-checkout) green.
- Completion report maps each consumer to its DTO and proves number-parity (EUR) + JPY correctness.
