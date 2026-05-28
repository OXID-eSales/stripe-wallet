# 115 — Code-Review 114 Remediation: Progress Report

**Date:** 2026-05-27
**Branch:** `b-7.4.x-code-review-STRP-145`
**Source review:** `reports/114-code-review.md`
**Plan:** `sprints/sprint-114.0-remediation-overview.md` (+ `sprints/_engineering_requirements.md`, guards R-1…R-10)
**Status:** **9 of 13 sub-sprints complete** (P0 + P1 + P2). P3 remaining.

---

## 1. Executive summary

The two latent bugs and the dead/duplicate-code cluster from review 114 are **fixed and committed**. Along the way the work also uncovered and fixed **4 additional money bugs** not in the original review. Everything landed TDD-first, each commit green on `./bin/pre-commit-check.sh --full` (PHPCS, PHPStan level max, PHPMD, PHPUnit Unit+Integration).

- **Commits:** 32 on `b-7.4.x-code-review-STRP-145` (incl. per-item + completion-report commits).
- **Net code:** 60 files changed, +1670 / −1772 (≈ **−100 net**; ~2200 LOC of dead code removed, partly offset by new focused collaborators + tests).
- **Test suite:** last full gate **1142 tests / 2743 assertions**, all green. PHPMD baseline **4 → 3**. No new static-analysis suppressions.
- **Scope rule (session):** edits confined to `extensions/stripe` (and `extensions/payment-base` for the remaining DTO sprint). No other module/core touched.

---

## 2. Done — P0 (correctness / security)

| Sprint | Commit(s) | Findings | Outcome |
|---|---|---|---|
| **114.1** shopId multi-shop bug | `6d4c0e1` | C1 | `ShopAdapterInterface` injected into `CaptureService` + 2 webhook handlers; `shopId: 1` literal gone; audit rows now use the real shop id |
| **114.2** address-validation bypass | `b73e918` | L1, T2 | Blanket Stripe bypass replaced by explicit `stripe_skip_addr_check` session flag, set before order-creation dispatch & cleared on cleanup; OXID tamper-detection restored outside the Stripe flow; 3 real behavioral tests (no more `markTestIncomplete`) |
| **114.3** security tests → real SUT | `ca5d2b9` | T1 | Re-implemented testable-subclass doubles removed; 8 `checkoutSuccess` branch tests + 7 webhook-render tests now drive the real controllers; verified by break-a-branch spot-check |

## 3. Done — P1 (dead-code / OCP / dedup)

| Sprint | Commit(s) | Findings | Outcome |
|---|---|---|---|
| **114.4** webhook dispatch | `1446076`, `4a0c0b9` | O1, S1 | Hardcoded `match($event->type)` (7 branches) → tagged `stripe.webhook_handler` registry (7 `final` handlers + `AbstractStripeWebhookHandler` + stripe-local `StripeWebhookOutcome`/interface); dead `PaymentIntentSucceededHandler`/`ChargeRefundedHandler` deleted; characterization tests guard all 7 types; payment-base untouched |
| **114.5** dead-code sweep | `74b1a5a` … `e23ee13` (8) | O2,O4,O5,O6,O7,O8,O9 | ~2020 LOC removed: dead `StripeCaptureService`/`StripeRefundService`, 4 unused `ConfigurationValidator` methods, orphan `Stripe3DSRequiredEvent`, 3 dead `StripeStatusMapper` methods, 5 speculative `Payment` methods, dead `getSessionDetails()`/`getShopBaseUrl()`, dead `QUICK_RETURN_MAX` const |
| **114.6** remove LazyStripeAdapter | `b23f3de` | O3, L2 | 195 LOC removed (orphaned after 114.5); PHPMD baseline shrank |

## 4. Done — P2 (DRY / centralization)

| Sprint | Commit(s) | Findings | Outcome |
|---|---|---|---|
| **114.7** AmountConverter | `e7853c7`,`eaa30b5`,`80403af`,`d359bf4` | D1 | Currency-aware `src/Stripe/Core/AmountConverter.php` (static, 0/2/3-decimal tables) replaces ~22 hand-coded `*100`/`/100` sites; `CENTS_PER_UNIT` folded in. **Fixed 4 latent money bugs** (see §6) |
| **114.8** DRY request handlers | `4f7bf58`,`53205e1`,`d7dc306`,`e3a43e0`,`6c86714` | D3,D4,D5 | Extracted `IdempotentExecutor` (idempotency wrapper), `ContractRefundRecorder` (single FULFILLED-guarded refund write — closed a guard divergence), `PaymentIntentResolver` (PI-id chain), `AbstractStripeRequestHandler` (shared `logEvent`/exception plumbing); 3 event handlers now extend it; log action strings preserved |
| **114.9** config/session helpers | `b9d2a37`,`cea7e01`,`5b3b4e4`,`13c25f4`,`c027ecb`,`fa315f4` | D2,D6,D7,D8,D9 | One token accessor; `StripeDefinitions::PAYMENT_PREFIX`+`isStripePaymentMethod()` (6 sites unified); stale-cleanup consolidated into `ControllerRequestHelper` with the full 5-key clear set (incl. `stripe_skip_addr_check`); `OpcModalSessionReader` extracted; `isConfigured()` unified |

---

## 5. Findings status (review 114 → sprint)

| Status | Findings |
|---|---|
| ✅ **Fixed** | C1, L1, L2, T1, T2, S1, O1–O9, D1–D9 (all High DRY/No-overeng + both P0 bugs + OCP) |
| ⏳ **Remaining** | A1, A2, A3, A4, L3 → **114.10** · S2, S3, S4, S5, S6, S7 → **114.11** · C2, C3, C4, C5, C6, C7, C8 → **114.12** · T3, T4, T5, T6, O10, §8 coverage gaps → **114.13** |
| ↪️ **Partially done** | C7 (the `Payment` prefix docblock corrected in 114.9; remaining TODO/docblocks in 114.12) · §8 (OPC-handler coverage gap closed by 114.9 D8; `StripeWebhookEndpointApi` gap will close with 114.11 S6) |

---

## 6. Extra issues surfaced during remediation (not in review 114)

1. **[FIXED in 114.7] Four truncation money bugs.** `(int)($amount * 100)` truncated float drift — `(int)(19.99 * 100)` = `1998`, so Stripe was charged €19.98 instead of €19.99 on create/authorize/capture/refund (`PaymentIntentHelper` + `RefundHelper`). Now `round()` via `AmountConverter`.
2. **[OPEN — candidate follow-up] payment-base capture/refund DTOs carry no currency.** `CapturePaymentRequest`/`RefundPaymentRequest` lack a `currency` field, so `AmountConverter` uses a 2-decimal fallback at those 2 sites — correct for EUR, **wrong for zero-decimal currencies** (JPY/KRW). Fix needs an additive payment-base DTO change (now permitted). Good candidate to fold into **114.10**.
3. **[OPEN — out of scope, pre-existing] Cross-module controller-name collision.** `paypal` and `stripe` both ship a controller with basename `WebhookController`; OXID's `ControllersValidator` flags a "namespace duplication" on `oe:module:activate` when both modules are active. Byte-identical at session start — **not caused by this work** and not a review-114 finding. Needs a separate decision (rename one controller / cross-module coordination).

---

## 7. Remaining work — P3 (4 sprints)

| Sprint | Findings | Scope & notes | Risk |
|---|---|---|---|
| **114.10** Provider-agnostic DTO boundary | A1,A2,A3,A4,L3 | Stop raw `\Stripe\*` types leaking past the adapter (return neutral DTOs); move normalized status constants into payment-base; add `fail()/cancel()` to `PaymentContractInterface` (drop the `instanceof PaymentContract` downcast); route the refund handler's PI-id via the agnostic resolver. **The big one** — touches payment-base. Could also fix the §6.2 currency-DTO gap. | **High** — must keep payment-base **additive** so paypal/OPC need zero edits (session rule). Phased: DTOs → migrate read consumers → migrate write consumers → flip interfaces. |
| **114.11** SRP / DIP cleanups | S2,S3,S4,S5,S6,S7 | Split `ModuleConfigurationService` god-object; move capturable-state policy from `StripeCaptureRequestHandler` into `CaptureService`; split `OrderRefundViewDataProvider`; kill scattered `ContainerFactory::getInstance()`; `StripeWebhookEndpointApi` via the versioned client factory (+ closes its coverage gap); unify handler `getPriority()`. | Medium — behavior-preserving refactors; depends on 114.10 DTOs for S4. |
| **114.12** Clean-code pass | C2,C3,C4,C5,C6,C7,C8 | Long-method splits; remove `else`/`elseif`; status/mode/currency **constants** (a `StripeMode` enum); explicit exception imports; null-safety; resolve TODOs/misleading docblocks; move hardcoded Connect URLs to config. | Low–Medium. |
| **114.13** Coverage gaps + test hygiene | T3,T4,T5,T6,O10,§8 | Real tests for the untested classes (`StripeWebhookEndpointApi`, `StripeEventTranslator`, `OxidContractLinkedOrderUpdater`, `OxidSessionWriter`, `StripeCheckoutFooter`); fix tautological/no-value tests; honest integration-suite gating (no silent 53-test skip); do the deferred O10 `StripeEventTranslator` map refactor. | Low (test-only, + small seams). |

**Suggested order:** 114.10 → 114.11 → 114.12 → 114.13 (114.11 S4 depends on 114.10 DTOs; 114.12 constants benefit later tests; 114.13 last so it tests final shapes).

---

## 8. Guardrails honored throughout

- **TDD:** every change RED→GREEN; refactors/deletions guarded by characterization tests; no method-under-test re-implemented in a double.
- **DevOps-first:** `pre-commit-check.sh --full` green per commit; PHPStan level max 0 errors; PHPMD baseline never grown (4→3); **no new suppressions**.
- **Event-driven / contract-aware:** webhook dispatch now additive via tagged handlers; refund/capture decisions remain contract-state-driven; refund writes funnel through `ContractRefundRecorder` → repository.
- **Persistence boundary (R-10):** no new direct DB writes introduced; writes stay in services/repositories.
- **Scope (session rule):** only `extensions/stripe` edited so far; `extensions/payment-base` reserved for 114.10 (additive only); nothing else touched.

---

## 9. Housekeeping notes

- The dev-log docs (this report, sprint specs, completion reports in `done/`) are currently **untracked** — only code was committed. Decide whether to commit the docs.
- ` M tests/e2e/playwright` is a **pre-existing** submodule-pointer change, unrelated to this work.
- Completion reports per sprint live in `docs/oe_payments_docs/daniil_dev_log/20260527/done/sprint-114.{1..9}-completion.md`.
