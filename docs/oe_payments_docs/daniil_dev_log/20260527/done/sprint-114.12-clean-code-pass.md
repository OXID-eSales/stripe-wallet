# Sprint 114.12 — Clean-code pass + status/mode constants

**Module:** `extensions/stripe`
**Priority:** P3 (Clean Code hygiene)
**Findings:** C2 (long methods), C3 (else/elseif), C4 (magic status/mode/currency literals), C5 (inline `\Exception`), C6 (inconsistent null handling), C7 (TODOs + misleading PHPDoc), C8 (hardcoded Connect URLs)
**Mode:** grouped commits (constants, then long-methods, then hygiene), TDD-aware. Behavior-preserving.
**Depends on:** 114.7 (`AmountConverter` for the amount literals removed in C4). **Coordinate with:** 114.9 D7/C7 docblock overlap.
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-5** in full (≤25-line methods, no else, explicit imports, no magic literals, null safety, no stale TODOs/docs).

## 1. Why

A cluster of CLAUDE.md style-rule violations (no else, methods 15-25 lines,
explicit imports, no magic strings, null safety). Individually minor; together
they erode the "thin controller / focused service" claims the headers make.

## 2. Grouped plan

### Group 1 — constants (C4, foundational)
- **Stripe statuses:** `'requires_capture'`, `'succeeded'`, `'canceled'`, `'paid'`, `'requires_action'` → constants on `StripeStatusMapper`/`StripeDefinitions` (sites: `OrderRefundViewDataProvider:116,272-281`, `StripeWebhookProcessor:220`, `PaymentIntentSucceededHandler:124-125`).
- **Audit types:** `'capture'`, `'refund'`, `'completed'` → constants.
- **Mode/capture:** `'test'`/`'live'` + `['automatic','manual']` → a `StripeMode`/`StripeCaptureMode` enum or `StripeDefinitions` constants (sites: `ModuleConfigurationService:81,91-94,321-324`, `CheckoutSessionService:56`).
- **Currency default:** `CaptureService:124` `'EUR'` literal → named default constant (or, better, never default — fail loud if currency missing).
- **Cancellation-reason whitelist:** dedupe between `PaymentIntentHelper:187` and `IdempotencyHelper:33` into one constant set.
- **TDD:** pin each constant's value (so a rename is deliberate); migrate sites; existing tests stay green.

### Group 2 — long methods (C2)
- `StripeOrderController::createCheckoutSession()` (~76 lines): extract a JSON responder (mirror `ModuleConfiguration::respondJson`) + split validation / dispatch / session-write helpers. Each ≤ 25 lines.
- `OrderRefundViewDataProvider::getStripeTransactionHistory()` (~55 lines): extracted in 114.11 S4 — if 114.11 ran first, skip here.
- `OxidShopOrderService::createOrder()`/`validateOrderState()`: split logging out of validation.
- **TDD:** the extracted helpers get focused tests; controller/service behavior unchanged (characterization).

### Group 3 — control-flow & hygiene (C3, C5, C6, C7, C8)
- **C3 no-else:** `ReturnSessionSecurityService:120-135,161-167`, `StripePaymentStatusHandler:144-159` (also add the missing `use PaymentDetailsResponse`), `ModuleConfigurationService` `getMode`/`getCaptureMode` → guard clauses / early returns.
- **C5 explicit imports:** add `use` for `\Exception`/`\RuntimeException`/`\Throwable`/FQCN params at `ContractTokenService:47`, `WebhookController:52,61`, `StripeCaptureRequestHandler:284`, `RetryCleanupService:81`; remove dead `use CreatePaymentResponse` in `LazyStripeAdapter` (moot if 114.6 deleted it).
- **C6 null safety:** `OxidShopOrderService:158` guard `getPrice()` consistently with `:93`.
- **C7 docs/TODOs:** resolve `@TODO`s (`ModuleConfiguration:119`, `ViewConfig:122`); correct `Payment` docblock/`@example` to the real `oe_payments_stripe_` prefix (overlaps 114.9 D7).
- **C8 Connect URLs:** move the environment-specific middleware URLs + admin route out of `ModuleConfiguration:154-162` into config/constants + a small URL builder.

## 3. Global goals

- **G1.** `composer phpcs` + PHPStan level max + PHPMD all green; no new suppressions.
- **G2.** No `else`/`elseif` remaining in the cited methods; no inline `\Exception` in the cited files.
- **G3.** `grep -rEn "'requires_capture'|'succeeded'|'test'|'live'|'EUR'" src/` shows only the constant definitions (modulo legitimate non-status uses).
- **G4.** Every touched method ≤ 25 lines.
- **G5.** `./bin/pre-commit-check.sh --full` green.

## 4. Risks & rollback

- **Risk:** enum migration (`StripeMode`) touches many comparison sites — do it as its own commit with full test coverage; a typo'd comparison silently flips test/live.
- **Risk:** extracting `createCheckoutSession` helpers could change error-response shape — characterization test the JSON output first.
- **Rollback:** grouped commits; revert a group independently.

## 5. Definition of Done

- C2–C8 Fixed; cited methods within the line budget; constants centralized.
- Completion report: list of new constants/enums and the methods split.
