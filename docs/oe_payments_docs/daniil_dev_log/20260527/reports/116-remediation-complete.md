# 116 — Code-Review 114 Remediation: COMPLETE

**Date:** 2026-05-28
**Branch:** `b-7.4.x-code-review-STRP-145` (stripe repo) + 2 additive commits in the separate `payment-base` repo
**Source review:** `reports/114-code-review.md`
**Plan:** `sprints/sprint-114.0-remediation-overview.md` + `sprints/_engineering_requirements.md` (guards R-1…R-10)
**Status:** **ALL 13 SUB-SPRINTS COMPLETE** — every High/Medium/Low finding from review 114 addressed.

---

## 1. Executive summary

The full code-review-114 remediation landed TDD-first, 61 commits across 2 repos, every commit green on `./bin/pre-commit-check.sh --full` (PHPCS, PHPStan level max, PHPMD, PHPUnit Unit+Integration). The work also surfaced and fixed **5 additional bugs** not in the original review.

**Headline numbers:**
- **61 commits**: 59 on `b-7.4.x-code-review-STRP-145` (outer stripe repo) + 2 in payment-base's own repo (separate git).
- **178 files** changed (+11204 / −5876).
- **15 completion reports** written (`done/sprint-114.{1..13}-completion.md`, plus the 10a/10b and 11a/11b splits).
- **Unit suite: 921 → 1123 tests** (+202 net behavioral tests added across the remediation), 2691 assertions.
- **PHPStan level max: 0 errors** throughout. **PHPMD baseline: 4 → 3 entries** (no growth, one entry retired). **No new suppressions.**
- **Scope rule honored:** only `extensions/stripe/` (and `extensions/payment-base/`, additively) was edited. **paypal and one-page-checkout suites stayed byte-identical** across every payment-base touch.

---

## 2. Sub-sprint tally

| Sprint | Findings | Outcome (1-line) |
|---|---|---|
| **114.1** | C1 | `ShopAdapterInterface` injected; multi-shop `shopId` audit bug fixed |
| **114.2** | L1, T2 | Stripe address-validation bypass gated on explicit `stripe_skip_addr_check` session flag; OXID tamper detection restored |
| **114.3** | T1 | Security tests rewritten to exercise the real controllers (15 behavioral branch tests) |
| **114.4** | O1, S1 | Webhook `match` → tagged registry of 7 handlers; dead duplicate subsystem deleted |
| **114.5** | O2,O4,O5,O6,O7,O8,O9 | ~2020 LOC of dead code removed across 7 items |
| **114.6** | O3, L2 | Redundant `LazyStripeAdapter` removed; baseline shrank |
| **114.7** | D1 | `AmountConverter` centralizes 22 cents-math sites; **fixed 4 latent money bugs** |
| **114.8** | D3,D4,D5 | Extracted `IdempotentExecutor`, `ContractRefundRecorder`, `PaymentIntentResolver`, `AbstractStripeRequestHandler` |
| **114.9** | D2,D6,D7,D8,D9 | One token accessor; payment-prefix helper; full stale-cleanup; OPC reader; unified `isConfigured` |
| **114.10a** | L3, A3, A2, currency-DTO | 4 spurious `instanceof PaymentContract` downcasts gone; refund handler off `oxNew(Order)`; normalized status constants moved to payment-base (additive); `currency` field added to capture/refund DTOs |
| **114.10b** | A1 | 5 neutral DTOs at adapter boundary; raw `\Stripe\*` types sealed inside `src/Stripe/Adapter/` (except 2 legitimate signature-verification imports in the webhook entry) |
| **114.11a** | S5, S6, S7 | Ad-hoc `ContainerFactory::getInstance()` eliminated from business code; `StripeWebhookEndpointApi` via versioned factory (+ 15 new CRUD tests); handler `getPriority()` consistency (3 in-code overrides removed where redundant) |
| **114.11b** | S2, S3, S4 | Split `ModuleConfigurationService` (`StripeUrlBuilder` + `ModuleDescriptionProvider`); capturable-state policy moved into `CaptureService`; `OrderRefundViewDataProvider` 303→256 LOC, `StripeTransactionHistoryBuilder` extracted |
| **114.12** | C2,C3,C4,C5,C6,C7,C8 | Status/mode/audit-type/cancellation-reason constants; `createCheckoutSession` 81→28 lines (4 helpers); else→guard clauses; explicit imports; null safety; stale TODOs resolved; Connect URLs out of inline literals |
| **114.13** | T3,T4,T5,T6,O10,§8 | Tautological + no-value tests killed; interface mocks throughout; T4 self-verification dropped; `StripeEventTranslator` instanceof ladder → mapping table + 10 new tests; coverage for `OxidContractLinkedOrderUpdater`/`OxidSessionWriter`/`StripeCheckoutFooter`; integration suite **honestly gated** (53 silent skips → 0; creds-dependent specs in `@group requires-stripe-creds` excluded from default suite) |

---

## 3. Review-114 findings — final disposition

**All findings from `reports/114-code-review.md` are addressed.** Below is the closure mapping (every H/M/L from the report):

| Section | Finding | Sprint | Status |
|---|---|---|---|
| TDD | T1 | 114.3 | ✅ |
| TDD | T2 | 114.2 | ✅ |
| TDD | T3, T5 | 114.13 | ✅ |
| TDD | T4 | 114.13 | ✅ |
| TDD | T6 | 114.13 | ✅ (gated suites) |
| SOLID | S1 | 114.4 | ✅ |
| SOLID | S2 | 114.11b | ✅ |
| SOLID | S3 | 114.11b | ✅ |
| SOLID | S4 | 114.11b | ✅ |
| SOLID | S5 | 114.11a | ✅ |
| SOLID | S6 | 114.11a | ✅ |
| SOLID | S7 | 114.11a | ✅ (note: payment-base `EventListenerProvider` reads `getPriority()` from code, not the tag; 1 of 3 in-code overrides kept on purpose) |
| LI | L1 | 114.2 | ✅ |
| LI | L2 | 114.6 | ✅ |
| LI | L3 | 114.10a | ✅ (downcasts spurious — all 4 methods already on the interface) |
| DRY | D1 | 114.7 | ✅ |
| DRY | D2 | 114.9 | ✅ |
| DRY | D3 | 114.8 | ✅ |
| DRY | D4 | 114.8 | ✅ |
| DRY | D5 | 114.8 | ✅ |
| DRY | D6 | 114.9 | ✅ |
| DRY | D7 | 114.9 | ✅ |
| DRY | D8 | 114.9 | ✅ |
| DRY | D9 | 114.9 | ✅ |
| No-overeng. | O1 | 114.4 | ✅ |
| No-overeng. | O2–O9 | 114.5 | ✅ |
| No-overeng. | O3 | 114.6 | ✅ |
| No-overeng. | O10 | 114.13 | ✅ |
| Clean Code | C1 | 114.1 | ✅ |
| Clean Code | C2–C8 | 114.12 | ✅ |
| Agnostic | A1 | 114.10b | ✅ |
| Agnostic | A2 | 114.10a | ✅ |
| Agnostic | A3 | 114.10a | ✅ |
| Agnostic | A4 | 114.5 (O7 removed it implicitly) | ✅ |
| Coverage gaps (§8) | All | 114.9 D8 + 114.11a S6 + 114.13 | ✅ |

---

## 4. Bonus findings — fixed during remediation (not in the original review)

1. **[FIXED, 114.7]** Four truncation money bugs in capture/refund: `(int)(19.99 * 100) = 1998` (float drift) so Stripe was charged €19.98 instead of €19.99. Sites: `PaymentIntentHelper:53,135,304`, `RefundHelper:83`. Centralized via `AmountConverter::toMinorUnits` (uses `round()`).
2. **[FIXED, 114.10a]** `ChargeRefundedHandler` was missing the FULFILLED guard that the other two refund paths had — 114.8's `ContractRefundRecorder` made the guard uniform across all three sites.
3. **[FIXED, 114.10a]** Webhook `PaymentIntentSucceededHandler` was registered without a tag and the `match` never reached it → 114.4 replaced that with a tagged registry where every handler is discoverable.
4. **[FIXED, 114.10a]** `WebhookContractFulfillmentHandler` `instanceof PaymentContract` downcasts were spurious — the interface already had every method called → 4 downcasts deleted.
5. **[FIXED, 114.11a]** `WebhookController` was re-fetching `ContainerFactory::getInstance()` mid-request — resolved once in `init()` now.

---

## 5. Open items (carried forward — not blocking)

These are out of code-review-114 scope but were noticed during the work:

1. **payment-base capture/refund DTOs.** `CapturePaymentRequest`/`RefundPaymentRequest` now CAN carry a `currency` (added additively in 114.10a) but the threading is incomplete in 2 stripe sites that pass `''` because the upstream contract/request didn't have one. Correct for EUR; **wrong for zero-decimal currencies (JPY/KRW)** at those 2 sites. Fix needs callers to populate `currency`. (Candidate STRP ticket; small.)
2. **Cross-module `WebhookController` namespace collision (pre-existing, NOT this work's).** paypal and stripe both ship a controller with basename `WebhookController` — OXID's `ControllersValidator` flags a "namespace duplication" on `oe:module:activate` when both modules are active. Byte-identical at session start. Needs a separate decision (rename one controller).
3. **payment-base `EventListenerProvider.registerHandler()` reads `getPriority()` from code, not the services.yaml `priority:` tag.** Currently the handler implementations and the tag duplicate priority; sprint 114.11a (S7) found dropping `StripeContractCreationHandler::getPriority()` would silently shift dispatch from 100→0, so the in-code override was kept on purpose. The clean fix is to make the dispatcher honor the tag — a payment-base change that would let all priorities live in services.yaml. (Candidate payment-base ticket; small.)

---

## 6. Operational notes

- **payment-base is a separate git repo** at `source/extensions/payment-base/.git` (no `.gitmodules` entry, just a sibling working tree). Its 2 commits (`3450ce7`, `d8709e9`) live there, not in the outer repo log.
- **The dev-log docs (this report, sprint specs, completion reports) are untracked.** Only code commits landed. Decide whether to commit the docs.
- **` M tests/e2e/playwright`** is a pre-existing submodule-pointer change that pre-dates this work.
- **PayPal + OPC verification.** Across the 2 payment-base touches in 114.10a, both consumer suites stayed identical (paypal 449/798, OPC 220/557). Run on demand:
  - `docker compose exec -T php php vendor/bin/phpunit -c extensions/paypal/tests/phpunit.xml --testsuite Unit`
  - `docker compose exec -T php php vendor/bin/phpunit -c extensions/one-page-checkout/tests/phpunit.xml --testsuite Unit`

---

## 7. Guardrails honored (R-1 … R-10)

- **R-1 TDD:** every change RED-before-GREEN; behavior-preserving refactors guarded by characterization tests; no method-under-test re-implemented in a double.
- **R-2 SOLID:** no central dispatch `match` left; god-objects split; ISP applied at the adapter boundary.
- **R-3 Liskov:** no security-weakening override; no `instanceof` downcasts.
- **R-4 DI:** constructor-injected interfaces; service-locator confined to the 2 sanctioned seams; new collaborators behind interfaces (e.g. `StripeClientProviderInterface`).
- **R-5 Clean Code:** ≤25-line methods (`createCheckoutSession` 81→28); explicit imports; constants for statuses/modes/audit types; null safety.
- **R-6 DevOps-first:** `pre-commit-check.sh --full` green per commit; no new suppressions; PHPMD baseline shrank (4→3).
- **R-7 Event-driven:** webhook dispatch via tagged registry; orphan event/handler eliminated; the deferred `Stripe3DSRequiredEvent` removed.
- **R-8 Contract-aware:** capturable-state policy now in `CaptureService`; refund recording flows through `ContractRefundRecorder` (single FULFILLED guard).
- **R-9 No overengineering:** ~2020 LOC dead-code removed; speculative classes (`Lazy*`, dead validator methods, dead model methods) gone.
- **R-10 Persistence boundary:** writes still funnel through services/repositories; no new direct DB writes.

---

## 8. Recommended next steps

- **Review & merge** the 59 outer-repo commits (likely as a single squashed PR or as the per-sprint commit graph, depending on team preference).
- **Coordinate the payment-base PR** (the 2 additive commits in its own repo).
- **Triage the 3 carried-forward items** in §5 as separate small tickets.
- **Decide on the doc commits** — the dev-log docs (reports, sprints, completion reports) are currently untracked; commit them if you want them in repo history.
