# Sprint 133 · Phase B — completion report

**Date:** 2026-08-19
**Scope delivered:** Phase B in full — Stories 9-16, findings **F9-F16** (all Medium).
**Branch:** `b-7.4.x-honest-failure-paths` in both repos (continues Phase A).
**Remaining:** Phase C (F17-F20, Low) — structural/cosmetic, untouched.

## Commits

**stripe:** `6868ec4` S9 · `e03a4f4` S10-S12 · `aa7409e` S13-S14 · `2500d13` S15 · `9cd704d` S16
**payment-base:** `67a6531` S15 · `d91413e` S16

## Evidence

| Gate | stripe | payment-base |
|---|---|---|
| `phpcs` | clean (src + tests) | clean |
| `phpstan` level max | **0 errors** | 0 errors |
| Unit | 920 green across 13 of 15 dirs (2 pre-existing env failures, see Phase A report) | **1128** green / 6 skipped (was 1122) |
| Integration | **89/89 green** | — |
| `services.yaml` | parses via Symfony's own YAML parser (140 services, 5 parameters) | — |

Every story went RED → GREEN. Notable RED evidence:

- **S12:** the failing run printed the literal contradiction from the review —
  `SUCCESS: Order=order_unchanged PaymentIntent=pi_unchanged NO_CONTRACT` — for an
  order whose contract had just been found and processed.
- **S9:** an absent `amount_refunded` arrived as `0.0` and was recorded as a real
  refund of nothing.
- **S15:** a `PaymentContractInterface` test double was rejected outright by the
  cleanup service, proving the concrete-class narrowing silently excluded
  implementations that satisfy every method it calls.

## Deliberate behaviour changes

| Test | Asserted before | Story |
|---|---|---|
| `WebhookContractFulfillmentHandlerAuditTest::testHandlePaymentSucceededWritesCaptureAuditRow…` | only the row *type*, so it silently accepted the fabricated 0.00 amount | S9 |
| `StripeCustomerServiceTest::testCreatesNewCustomerWhenExistingIsStale` | a generic `\Exception` means "stale" — the mechanism that let any error repoint the mapping | S10 |
| `OxpaidReconciliationServiceTest` dry-run assertion | extended to assert `success === false` | S12 |

## Findings corrected during implementation

The review was wrong or overstated in four places. Recording them so the report
is not trusted beyond its evidence:

1. **F16 was overstated.** The review said "~10 handlers … not one log line is
   written". In fact **6 of 9** stripe handlers already logged their event-type
   mismatch — including every handler that advances the contract state machine.
   The genuinely silent ones were payment-base's `FraudCheckHandler` (the real
   risk: it fulfils the fraud condition) and three OPC URL/cleanup handlers.
2. **F16's watchdog was unnecessary.** `ContractRepository::findStaleNotFinished()`
   already includes the `pending` state, so a contract stalled in PENDING is
   already cancelled by the existing 30-minute sweep after every webhook. What
   was actually missing: that finder — and two sibling finders — **caught the DB
   exception and returned `[]`**, which is indistinguishable from "nothing
   stale", so a failing query turned the sweep into a permanent silent no-op.
   That is now logged. This is a *better* fix than the watchdog the sprint
   specified, and it was only visible by reading the implementation.
3. **F15 was smaller than assumed.** The sprint proposed a new
   `ContractStateTransitionsInterface`. But `cancel()`, `getState()`,
   `getOrderId()`, `fail()`, `expire()` and `transitionToPending()` were **already**
   on `PaymentContractInterface` — only `transitionToNotFinished()` was missing.
   So `RetryCleanupService` needed no narrowing at all, and completing the
   existing interface (one additive method, sibling of one already there) removed
   the DIP break at its root without inventing an abstraction. The comment in
   `StripePaymentHandler` claiming the narrow abstract surface was "intentional"
   was simply inaccurate.
4. **F14 had four more sites than reported.** Besides the two
   `is_numeric($shopId) ? … : 1` defaults, four audit-row sites did
   `(int) $shopAdapter->getShopId()`, which yields **0** — not a shop at all —
   for any non-numeric value, and would silently truncate `'12abc'` to shop 12.

## Deviation from the sprint's own DoD (S13)

The sprint said to "scope the legacy fallback to the mode it was stored under".
That is not knowable — the legacy setting is mode-agnostic by construction — and
scoping it to test-only would **break live shops currently running on a manually
pasted live secret**. Implemented instead: the fallback is refused only when the
*other* mode already has its own per-mode secret, i.e. when observable state
proves the shop has auto-registered since and the legacy value belongs to an
earlier era. That closes the documented test→live hazard, logs the refusal with
the setting name to configure, and regresses nobody.

## Deferred, with reasons

- **`CaptureService`'s `amount: $result->amountCaptured ?? 0`** was flagged in the
  Phase A report and is now an explicit guard (S9), but `Transaction::$amount`
  remains a non-nullable float. Representing "amount unknown" in the transaction
  model itself is a schema question, not a fallback fix.
- **`AbstractPaymentRefundService::executeRefund()` (payment-base) still builds
  `RefundPaymentRequest` without a currency**, so any provider using it inherits
  the 2-decimal assumption S1 removed from the Stripe path. It has no subclasses
  in this workspace, so it stays latent — worth a payment-base story.
- **Phase C (F17-F20)** untouched: the 776-line controller extraction, activation
  event cleanup, the empty publishable key, and the `isTestMode()` naming clash.

## Deploy notes (additional to Phase A)

1. **`oe:cache:clear` required** — `services.yaml` gained explicit logger
   arguments for `ModuleConfigurationService`, `RetryCleanupService`,
   `WebhookContractFulfillmentHandler` and the three OPC handlers.
2. **Behaviour change worth announcing:** a `charge.refunded` webhook whose
   `amount_refunded` cannot be read now returns 500 so Stripe retries, instead of
   silently recording a 0.00 refund.
3. **Behaviour change for multishop:** an unresolvable shop id now throws instead
   of quietly using shop 1 or shop 0. Any install relying on that accident will
   surface a loud error — which is the point.
4. **`OxpaidReconciliationService` now requires its file logger** (already wired).
