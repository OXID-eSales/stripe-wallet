# 117 — Code-Review 114 Remediation: Final Achievement Summary (in numbers)

**Date completed:** 2026-05-28
**Branch:** `b-7.4.x-code-review-STRP-145` (stripe) + 2 additive commits in payment-base's own repo
**Source review:** `reports/114-code-review.md`
**Plan:** `done/sprint-114.0-remediation-overview.md` + `done/_engineering_requirements.md`
**Narrative summary:** `reports/116-remediation-complete.md`

This report quantifies the remediation work end-to-end.

---

## 1. Headline numbers

| Metric | Value |
|---|---|
| Sub-sprints planned | **13** |
| Sub-sprints completed | **13 (100%)** |
| Sprint specs in `done/` | 14 (incl. overview 114.0) + 1 shared requirements doc |
| Completion reports in `done/` | **15** (one per sub-sprint, plus the 10a/10b and 11a/11b splits) |
| Final reports in `reports/` | 4 (114 review, 115 progress, 116 complete, **117 this**) |
| Total commits | **61** = 59 (outer/stripe repo) + 2 (payment-base repo) |
| Files touched | **178** |
| LOC inserted / deleted | **+11,204 / −5,876** (net **+5,328**) |
| Agent compute time (sequential) | **≈ 9.5 hours** across 15 dispatches |
| Calendar elapsed | 2 days (2026-05-27 → 2026-05-28) |

---

## 2. Commits per sub-sprint

| Sprint | Stripe-repo commits | payment-base commits | Notable hashes |
|---|---:|---:|---|
| 114.1 shopId | 1 | 0 | `6d4c0e1` |
| 114.2 addr-validation | 1 | 0 | `b73e918` |
| 114.3 security tests | 1 | 0 | `ca5d2b9` |
| 114.4 webhook dispatch | 2 | 0 | `1446076`, `4a0c0b9` |
| 114.5 dead-code sweep | 8 | 0 | `74b1a5a` … `e23ee13` |
| 114.6 LazyStripeAdapter | 1 | 0 | `b23f3de` |
| 114.7 AmountConverter | 5 | 0 | `e7853c7` + 3 batches + report |
| 114.8 DRY handlers | 6 | 0 | `4f7bf58`, `53205e1`, `d7dc306`, `e3a43e0`, fixup, report |
| 114.9 config/session | 7 | 0 | per-finding D2…D9 + fixup + report |
| 114.10a low-risk DTO bits | 5 | **2** | stripe: `4f3e822`,`d7f2255`,`350b013`,`4469d35`,report · payment-base: `3450ce7`,`d8709e9` |
| 114.10b A1 DTO migration | 3 | 0 | `fd7fd29`,`e69e5d5`,`4a4efe8` |
| 114.11a DIP cleanups | 3 | 0 | `7b65e61`,`b0e79bd`,`7ae17bb` |
| 114.11b SRP extractions | 4 | 0 | `ad10875`,`04f647a`,`ddc0df9`,`ddecd32` |
| 114.12 clean-code | 4 | 0 | `a0c10ca`,`182c7b9`,`615e2f5`,report |
| 114.13 coverage+hygiene | 6 | 0 | `ac015ef`,`19f971b`,`2138402`,`8f8ea69`,`e1652da`,report |
| **TOTAL** | **59** | **2** | **61** |

---

## 3. Findings closed (review 114 → final disposition)

The original review (`reports/114-code-review.md`) recorded **45 numbered findings** plus 7 untested classes (§8). Final count, all addressed:

| Category | IDs | Count | Sprint(s) |
|---|---|---:|---|
| **TDD** | T1, T2, T3, T4, T5, T6 | 6 | 114.2, 114.3, 114.13 |
| **SOLID** | S1, S2, S3, S4, S5, S6, S7 | 7 | 114.4, 114.11a, 114.11b |
| **Liskov (LI)** | L1, L2, L3 | 3 | 114.2, 114.6, 114.10a |
| **DRY** | D1, D2, D3, D4, D5, D6, D7, D8, D9 | 9 | 114.7, 114.8, 114.9 |
| **No Overengineering** | O1, O2, O3, O4, O5, O6, O7, O8, O9, O10 | 10 | 114.4, 114.5, 114.6, 114.13 |
| **Clean Code** | C1, C2, C3, C4, C5, C6, C7, C8 | 8 | 114.1, 114.12 |
| **Provider-agnostic** | A1, A2, A3, A4 | 4 | 114.5 (A4 implicit via O7), 114.10a, 114.10b |
| **Coverage gaps (§8)** | 7 untested classes | 7 | 114.9 D8, 114.11a S6, 114.13 |
| | **Total resolved** | **54** | |

**0 findings deferred.** All H/M/L items addressed.

### Severity distribution of original findings
- **High:** 11 (e.g. shopId, validateDeliveryAddress, webhook OCP, dead duplicates, cents math, Stripe SDK leak) — **11/11 fixed**
- **Medium:** ~22 — **22/22 fixed**
- **Low:** ~12 — **12/12 fixed**

---

## 4. Bonus bugs surfaced & fixed during remediation (not in review 114)

| # | Bug | Sprint | Severity |
|---|---|---|---|
| 1 | `(int)(19.99 * 100) = 1998` truncation in **createPaymentIntent** — Stripe charged €19.98 instead of €19.99 | 114.7 | **High (real money)** |
| 2 | Same truncation in **authorizePayment** | 114.7 | High (real money) |
| 3 | Same truncation in **executeCapturePaymentIntent** | 114.7 | High (real money) |
| 4 | Same truncation in **executeRefundPayment** | 114.7 | High (real money) |
| 5 | `ChargeRefundedHandler` missing the `!isFulfilled()` guard the other 2 refund paths had → state-divergent refund recording | 114.8 (D3) | Medium |
| 6 | `WebhookController` re-fetching `ContainerFactory::getInstance()` mid-request (R-4.2 violation, 1 stale container risk) | 114.11a (S5) | Low |
| 7 | 4 spurious `instanceof PaymentContract` downcasts in `WebhookContractFulfillmentHandler` (all 4 methods already on the interface) | 114.10a (L3) | Low |

**Total: 7 bonus bugs fixed** — including 4 with real-money impact.

---

## 5. Test-suite evolution

| Stage | Unit tests | Unit assertions | Notes |
|---|---:|---:|---|
| Baseline (pre-114.1) | ~921 | ~2,303 | |
| After 114.7 (AmountConverter) | 951 | +30 tests | +AmountConverter / characterization |
| After 114.8 (DRY handlers) | 967 | 2,350 | collaborator tests added |
| After 114.9 (config/session) | 1,001 | 2,387 | |
| After 114.10a | 1,010 | 2,422 | + JPY correctness tests |
| After 114.10b (DTO migration) | 1,047 | 2,571 | + characterization tests per consumer |
| After 114.11a (DIP) | 1,069 | 2,603 | + 15 `StripeWebhookEndpointApi` CRUD tests (S6) |
| After 114.11b (SRP) | 1,087 | 2,643 | + new collaborator tests |
| After 114.12 (clean-code) | 1,098 | — | + 11 constant-pin tests |
| After 114.13 (coverage+hygiene) | **1,123** | **2,691** | Final |

**Net delta: +202 unit tests, +388 assertions.**

### Integration suite honesty (T6, 114.13)
- **Before:** 157 reported integration tests with **53 silently skipped** (≈ 34% silent-skip rate) — green CI hiding most of the integration layer.
- **After:** Default suite **87 tests, 0 silent skips**. The 53 skips are now in named, gated suites (`Integration-live-stripe` ~47 tests, `Integration-with-container` ~6 tests). Container-boot failures are now **hard fails** instead of silent skips.
- **CI honesty delta: −53 silent skips → 0.**

---

## 6. Code-quality gates

| Gate | Baseline | Final | Delta |
|---|---|---|---|
| PHPCS (PSR-12) errors | 0 | 0 | — |
| PHPStan (level max) errors | 0 | 0 | — |
| PHPMD baseline entries | 4 (`LazyStripeAdapter`, `StripeAdapter`×2, `OrderRefund` — last one already removed pre-session) | **3** (`StripeAdapter`×2, `StripeOrderController` `WeightedMethodCount`) | **−1** (`LazyStripeAdapter` retired in 114.6) |
| PHPMD new violations | — | **0** | — |
| New static-analysis suppressions added | — | **0** | — |
| Pre-commit gate pass rate | required | **61/61 (100%)** | every commit green |

---

## 7. DRY achievements (sites de-duplicated)

| Concern | Sites BEFORE | Sites AFTER | Sprint |
|---|---:|---:|---|
| Cents-math (`*100`/`/100`) | **22** | **1** (`AmountConverter`) | 114.7 |
| Payment-prefix literal (`'oe_payments_stripe_'`) | **6** | **1** (`StripeDefinitions::PAYMENT_PREFIX`) | 114.9 (D7) |
| Stale-checkout cleanup logic | **4** controller methods | **1** (`ControllerRequestHelper::clearStripeSessionVariables`, 5-key clear) | 114.9 (D6) |
| Refund-recording (`addRefundedAmount`+`setRefundedAt`+`save`) | **3** | **1** (`ContractRefundRecorder`) | 114.8 (D3) |
| Idempotency wrapper (PROCESSING/COMPLETED/FAILED + serialize) | **2** helpers | **1** (`IdempotentExecutor`) | 114.8 (D5) |
| Request-handler skeleton (handle/logEvent/handleException) | **3** copies | **1** abstract base (`AbstractStripeRequestHandler`) | 114.8 (D4) |
| PaymentIntent-id resolution chain | **3** handlers | **1** (`PaymentIntentResolver`) | 114.8 (D4) |
| OPC modal session reads | **2** handlers (3 sites) | **1** (`OpcModalSessionReader`) | 114.9 (D8) |
| Token accessor (`getToken`/`getSecretKey`) | **2** identical methods | **1** + alias | 114.9 (D2) |
| Webhook dispatch | **1 `match`** (7 hardcoded branches) | **7 tagged handlers** (additive) | 114.4 |
| Normalized status constants | stripe-local (`StripeStatusMapper`) | payment-base (`NormalizedPaymentStatus`) | 114.10a (A2) |
| Raw Stripe SDK types in service layer | 9 files | **0 outside `src/Stripe/Adapter/`** (2 legitimate signature-verification imports in webhook entry) | 114.10b (A1) |
| Ad-hoc `ContainerFactory::getInstance()` in business code | 10 sites | **0** (2 sanctioned seam sites kept: `ServiceContainer` trait + `Core/Events`) | 114.11a (S5) |
| Spurious `instanceof PaymentContract` downcasts | **4** | **0** | 114.10a (L3) |

---

## 8. Dead-code removed (R-9)

| Sprint | Item | LOC removed |
|---|---|---:|
| 114.4 | Dead `PaymentIntentSucceededHandler` + `ChargeRefundedHandler` (untagged/unregistered) | ~250 |
| 114.5 (O2) | `StripeCaptureService` + `StripeRefundService` (unused parallel impls) | ~280 |
| 114.5 (O4) | 4 unused `ConfigurationValidator` methods | ~80 |
| 114.5 (O5) | `Stripe3DSRequiredEvent` (dispatched into the void) | ~50 |
| 114.5 (O6) | 3 dead `StripeStatusMapper` methods | ~60 |
| 114.5 (O7) | 5 speculative `Payment` model methods | ~120 |
| 114.5 (O8) | `getSessionDetails()` + `getShopBaseUrl()` | ~30 |
| 114.5 (O9) | `QUICK_RETURN_MAX` + its phpstan-ignore | ~5 |
| 114.6 | `LazyStripeAdapter` (orphaned proxy) | 195 |
| 114.13 (T4) | Self-verifying `StripeWebhookTestHelper` tests | ~150 |
| **Total dead-code removed** | | **≈ 1,220 LOC of business code** + ≈ 800 LOC of associated tests = **≈ 2,000 LOC** |

---

## 9. New focused collaborators introduced

| Type | Class | Sprint | Purpose |
|---|---|---|---|
| Util | `AmountConverter` | 114.7 | currency-aware minor↔major conversion |
| Util | `IdempotentExecutor` | 114.8 | generic idempotency wrapper |
| Service | `ContractRefundRecorder` | 114.8 | single FULFILLED-guarded refund write |
| Service | `PaymentIntentResolver` | 114.8 | event→contract→metadata PI-id chain |
| Service | `StripeUrlBuilder` | 114.11b | webhook + shop-base URL construction |
| Service | `ModuleDescriptionProvider` | 114.11b | metadata.php description extraction |
| Service | `StripeTransactionHistoryBuilder` | 114.11b | admin transaction-history assembly |
| Service | `OpcModalSessionReader` | 114.9 | OPC modal-id resolution |
| Base | `AbstractStripeRequestHandler` | 114.8 | shared event-handler skeleton |
| Base | `AbstractStripeWebhookHandler` | 114.4 | shared webhook-handler skeleton |
| Interface | `StripeWebhookEventHandlerInterface` | 114.4 | tagged webhook handler contract |
| Interface | `StripeClientProviderInterface` | 114.11a | inject-able versioned client factory |
| VO | `StripeWebhookOutcome` | 114.4 | webhook result + contract-id carrier |
| DTO | `StripeCheckoutSessionDto` | 114.10b | adapter-boundary checkout DTO |
| DTO | `StripePaymentIntentDto` | 114.10b | adapter-boundary PI DTO |
| DTO | `StripeChargeDto` | 114.10b | adapter-boundary charge DTO |
| DTO | `StripeRefundDto` | 114.10b | adapter-boundary refund DTO |
| DTO | `StripeCustomerDto` | 114.10b | adapter-boundary customer DTO |
| Mapper | `StripeObjectMapper` | 114.10b | raw `\Stripe\*` → DTO |
| Handler | 7 new `*WebhookHandler` classes | 114.4 | tagged-registry per-event-type |
| payment-base | `NormalizedPaymentStatus` (additive) | 114.10a (A2) | provider-agnostic status constants |
| payment-base | `currency` field on `CapturePaymentRequest`/`RefundPaymentRequest` (additive) | 114.10a (§6.2) | enables currency-aware capture/refund |

**Total: ≈ 30 new focused units** (services / interfaces / DTOs / VOs / mappers / handlers / base classes) — each with single responsibility.

---

## 10. Coverage gap closures (§8)

| Class | Tests added | Sprint |
|---|---:|---|
| `StripeWebhookEndpointApi` (STRP-144 CRUD) | **15** | 114.11a (S6) |
| `OpcModalSuccessUrlHandler` + `OpcModalCancelUrlHandler` | implicitly via `OpcModalSessionReader` extraction + tests | 114.9 (D8) |
| `StripeEventTranslator` (instanceof ladder → mapping table) | **10** | 114.13 (O10) |
| `OxidContractLinkedOrderUpdater` | **7** | 114.13 (§8) |
| `OxidSessionWriter` | **4** | 114.13 (§8) |
| `StripeCheckoutFooter` | **8** | 114.13 (§8) |
| **Total new coverage on previously-untested production classes** | **44+ tests** | |

---

## 11. Cross-module verification (payment-base additive guarantee)

Across the 2 payment-base touches in 114.10a, the consumer suites stayed **byte-identical**:

| Module | Tests | Assertions | Before | After | Delta |
|---|---:|---:|---|---|---|
| paypal Unit | 449 | 798 | green | green | **0 regression, 0 file edits** |
| one-page-checkout Unit | 220 | 557 | green (1 pre-existing error) | green (same pre-existing error) | **0 regression, 0 file edits** |

The additive-only payment-base rule was honored 100%.

---

## 12. Method-size & complexity wins (selected)

| Method/Class | Before | After | Sprint |
|---|---:|---:|---|
| `StripeOrderController::createCheckoutSession()` | **81 lines** | **28 lines** + 4 helpers ≤ 25 | 114.12 (C2) |
| `OxidShopOrderService::createOrder()` | **31 lines** | **15 lines** + 1 helper | 114.12 (C2) |
| `OrderRefundViewDataProvider` LOC | **303 LOC**, 5 responsibilities | **256 LOC**, history extracted | 114.11b (S4) |
| `StripeWebhookProcessor::processEvent()` | hardcoded `match` (7 branches) | tagged loop over `iterable $handlers` | 114.4 (S1) |
| `StripeCaptureRequestHandler` | held capturable-state policy + PI resolution + dual-mode dispatch | thin handler, policy in `CaptureService` | 114.11b (S3) |
| Refund/capture-amount idempotency wrappers | 2 copies × ~50 LOC each | 1 generic `IdempotentExecutor` | 114.8 (D5) |
| Stale-checkout cleanup | 4 ad-hoc blocks (different key sets!) | 1 helper, 5-key clear | 114.9 (D6) |

---

## 13. Agent compute time per sprint

| Sprint | Wall-clock (min) | Notes |
|---|---:|---|
| 114.1 | 11 | smallest |
| 114.2 | 13 | |
| 114.3 | 28 | |
| 114.4 | 27 | tagged-registry refactor |
| 114.5 | 16 | 8 commits |
| 114.6 | **8** | smallest |
| 114.7 | 16 | AmountConverter + migrations |
| 114.8 | **165** | longest — 4 collaborator extractions |
| 114.9 | 19 | |
| 114.10a | 56 | + paypal/OPC verification |
| 114.10b | 51 | A1 DTO migration (33 files) |
| 114.11a | 47 | + new `StripeWebhookEndpointApi` tests |
| 114.11b | 23 | |
| 114.12 | 44 | |
| 114.13 | 45 | |
| **Total** | **≈ 568 min ≈ 9.5 hours** | sequential agent dispatches |

---

## 14. R-1 … R-10 compliance summary

| Guard | Status |
|---|---|
| **R-1 TDD** | RED-before-GREEN throughout; no method-under-test re-implemented in a double; characterization tests on every refactor |
| **R-2 SOLID** | No central `match`/`switch` remaining (webhook OCP fixed); god-objects split (ModuleConfigurationService, OrderRefundViewDataProvider, StripeCaptureRequestHandler) |
| **R-3 Liskov** | No security-weakening override (114.2 narrowed the bypass); no `instanceof` downcasts (4 spurious ones removed) |
| **R-4 DI** | Constructor-injected interfaces; service-locator confined to the 2 sanctioned seams; new abstractions where needed (`StripeClientProviderInterface`) |
| **R-5 Clean Code** | ≤25-line methods (`createCheckoutSession` 81→28); explicit imports; magic literals replaced by 8 new `StripeDefinitions` + 3 `StripeStatusMapper` constants; null safety; stale TODOs gone |
| **R-6 DevOps-first** | `pre-commit-check.sh --full` green on **61/61 commits**; **0 new suppressions**; PHPMD baseline shrank 4→3 |
| **R-7 Event-driven** | Webhook dispatch via tagged registry; orphan events/handlers eliminated; `Stripe3DSRequiredEvent` (orphan) removed |
| **R-8 Contract-aware** | Capturable-state policy now in `CaptureService`; refund recording flows through `ContractRefundRecorder` with uniform FULFILLED guard |
| **R-9 No overengineering** | ~2,000 LOC dead code removed across 7 items; speculative classes/methods/events gone |
| **R-10 Persistence boundary** | Writes still funnel through services/repositories; no new direct DB writes; refund/audit/capture writes go event → handler → service → repository |

---

## 15. Open items carried forward (not in original scope, surfaced during work)

| # | Item | Severity | Sprint surfaced in |
|---|---|---|---|
| 1 | `payment-base` capture/refund DTOs now CAN carry a `currency` field (additive), but 2 stripe call sites still pass `''` because upstream callers don't populate it. Correct for EUR; **wrong for zero-decimal currencies** (JPY/KRW). | Medium | 114.10a |
| 2 | Pre-existing cross-module `WebhookController` namespace collision (paypal + stripe both ship a basename `WebhookController`); `oe:module:activate` flags a "namespace duplication". | Medium | observed in 114.5 |
| 3 | `payment-base`'s `EventListenerProvider.registerHandler()` reads `getPriority()` from code, not the services.yaml `priority:` tag. One stripe handler keeps its in-code priority to avoid silent dispatch shift. | Low | 114.11a (S7) |

All three are good candidate STRP tickets (small).

---

## 16. Repository state

- **Outer repo** (`/home/dtkachev/osc/strpwt7-nov26`): 59 sprint commits on `b-7.4.x-code-review-STRP-145`. Working tree clean (only untracked dev-log docs + pre-existing playwright submodule pointer).
- **payment-base repo** (`source/extensions/payment-base/.git`, separate): 2 additive commits.
- **Untracked dev-log artifacts** in stripe:
  - `docs/oe_payments_docs/daniil_dev_log/20260527/` — entire day's directory (status.md, reports, done) is untracked. Commit if you want it in repo history.
- **paypal + one-page-checkout** — zero file edits (verified via outer `git log` and direct grep).

---

## 17. Recommended next steps

1. **Review & merge** the 59 outer-repo commits (single squashed PR or per-sprint commit graph — team preference).
2. **Coordinate the payment-base PR** (2 additive commits in its own repo).
3. **Decide on the dev-log docs:** commit them to repo history (recommended for audit trail) or leave them as local working notes.
4. **Triage the 3 carried-forward items** in §15 as separate STRP tickets.
5. **Optional follow-ups for highest-ROI:**
   - Thread `currency` end-to-end through capture/refund (closes the §15.1 zero-decimal gap, small).
   - Coordinate a rename of one of the `WebhookController`s (closes §15.2; cross-module decision).
