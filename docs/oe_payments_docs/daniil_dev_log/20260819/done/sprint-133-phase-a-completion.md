# Sprint 133 · Phase A — completion report

**Date:** 2026-08-19
**Scope delivered:** Phase A in full — Stories 0-8, findings **F1-F8** (all Critical + High).
**Branch:** `b-7.4.x-honest-failure-paths` in **both** repos (stripe & payment-base), branched from `b-7.4.x` @ v3.2.0.
**Not started:** Phase B (F9-F16) and Phase C (F17-F20) — per the sprint's own recommendation these are Sprint 134.

## Commits

**stripe** (44 files, +3869/-218):

| Commit | Story |
|---|---|
| `d0b31df` | S1 (F3) thread currency into partial-refund conversion |
| `8f606e9` | S2 (F2) key refund idempotency by request, not by payment |
| `017a066` | S3 (F8) native keys, required repository, lock reaper |
| `f998358` | S4 (F1) Radar reports honestly instead of forging a clean score |
| `a78f3d6` | S7 (F7) one currency resolver, zero hardcoded `'EUR'` |
| `cac000d` | S6 (F6) confirmPayment consults Stripe instead of always saying yes |
| `4a8df2f` | S5 (F4) webhook guards fail closed instead of warn-and-continue |
| `cbbe171` | S8 (F5) wire the return-session scorer, advisory-first |

**payment-base** (4 files, +209/-1):

| Commit | Story |
|---|---|
| `3daae7f` | S2 (F2) additive `idempotencyKey` on `RefundPaymentRequest` |
| `6fa30c7` | S4 (F1) separate the fraud audit record from the blocking policy |

Merge order per the sprint's cross-repo protocol: **payment-base first**, tag, bump the
stripe constraint, then stripe. Both branches are local only — nothing pushed, no tags cut.

## Evidence

| Gate | stripe | payment-base |
|---|---|---|
| `phpcs` (PSR-12) | clean | clean |
| `phpstan` level max | **0 errors** — identical to the v3.2.0 baseline, no new baseline entries | 0 errors |
| `phpmd` (with baseline) | clean | clean |
| Unit | 1301 tests green across 14 of 15 dirs (see caveat) | 1126 green / 6 skipped (was 1122/6) |
| Integration | **89/89 green** | — |

Every story ran RED → GREEN. The RED runs are worth recording because they reproduced
the reported defects exactly:

- **S1:** a ¥1,000 partial refund reached the adapter as `100000` — the 100× over-refund,
  confirmed, not inferred. BHD 1.234 arrived as `123`.
- **S2:** a second refund with a *different* request reference produced the *same*
  idempotency key; the by-charge path called Stripe again on an already-completed record
  (the duplicate refund) and stored `null` as its result, so replay was impossible.
- **S3:** helpers constructed without a repository silently skipped all protection; no
  Stripe call carried `idempotency_key`; a `PROCESSING` record blocked retries for 24h.
- **S4:** the fraud service had no logger to log with, and `FraudCheckResponse::error()`
  had zero callers repo-wide.
- **S5:** two existing tests asserted warn-and-continue by name.
- **S6:** `confirmPayment()` returned success for `pi_whatever` with no adapter at all.
- **S8:** the validator was never invoked.

## Deliberate behaviour changes (tests that asserted the defect)

The sprint's register listed 5. Implementation found **3 more** that it had not audited —
all rewritten with the reason in a docblock, none silently deleted:

| Test | Asserted | Story |
|---|---|---|
| `StripeRadarFraudCheckServiceTest::testPassesOnApiError` | API error ⇒ pass, score 0.0 | S4 |
| `StripeRadarFraudCheckServiceTest::testPassesWhenRiskScoreNotAvailable` | unknown ⇒ score 0.0 | S4 |
| `RefundHelperIdempotencyTest` (keys + cached replay) | payment-scoped keys; replay as fresh success | S2 |
| `PaymentIntentHelperIdempotencyTest::captureWithoutIdempotencyCallsStripeDirectly` | unprotected mode is fine | S3 |
| `RefundHelperIdempotencyTest::refundPaymentWithoutIdempotencyCallsStripeDirectly` | unprotected mode is fine | S3 |
| **(new)** `StripePaymentHandlerTest::testConfirmPaymentReturnsSuccessForContractId` | success without a provider call | S6 |
| **(new)** `WebhookControllerGuardIntegrationTest::controllerWorksWithoutGuard` | endpoint works with no guards | S5 |
| **(new)** `WebhookControllerGuardIntegrationTest::guardChainUnavailableRendersWarnsAndContinues` | warn-and-continue, documented as required | S5 |
| **(removed)** `CheckoutReturnServiceTest::testCheckoutReturnResultSecurityFailure` | tested a factory with no production caller; factory deleted in S8 | S8 |

The last three are the sharpest lesson from this sprint: CI was actively guarding the
defects. `guardChainUnavailableRendersWarnsAndContinues` even carried a docblock stating
that render() "must then CONTINUE without the guard".

## Findings discovered while implementing (not in the review)

1. **Two more hardcoded `'EUR'` sites** beyond the four reported — `CaptureService`'s
   transaction audit row (`$result->currency ?? StripeDefinitions::DEFAULT_CURRENCY`) and
   the checkout footer widget. Both fixed under S7; `grep -rn "'EUR'" src/` now returns
   only comments and the legitimate currency allowlist.
2. **`CaptureService` audit row also does `amount: $result->amountCaptured ?? 0`** — the
   same sentinel defect as F9, one line above the currency one. **Left for Phase B**
   deliberately: fixing it needs a decision on whether `Transaction` may hold a null
   amount, which is F9's scope, not F7's.
3. **`AbstractPaymentRefundService::executeRefund()` (payment-base) builds
   `RefundPaymentRequest` without a currency**, so any provider using it inherits the
   2-decimal assumption S1 just removed from the Stripe path. It has **no subclasses**
   in this workspace, so it is latent. Worth a Phase B story.
4. **The existing JPY refund test never covered the bug.**
   `RefundServiceDtoCharacterizationTest::testProcessRefundJpyPreservesZeroDecimalAmount`
   exercises the *full*-refund path, where no major→minor conversion happens at all. The
   suite looked JPY-safe while the partial path was 100× wrong — a coverage-shape lesson,
   not just a bug.
5. **The PHPMD composer script points at `tests/PhpMd/standard.xml`, which does not
   exist** (the directory holds `phpmd.xml`). `composer phpmd` therefore fails; the
   working invocation is the one in `.github/scripts/codestyle_check.sh`. Pre-existing,
   out of scope, but it means the documented command in CLAUDE.md is broken.

## Design decisions taken (worth a review pass)

- **Refund request identity = the charge's pre-refund state** (`amountRefunded`), not a
  session token. Story 0 rejected OXID's `stoken` because it is session-scoped, so two
  legitimate €10 refunds in one admin session would have collided — reproducing F2. The
  state comes free: the charge is expanded on the PaymentIntent retrieve `RefundService`
  already performs.
- **F1 splits the audit record from the blocking policy.** The service always reports
  honestly; `FraudCheckHandler` decides, via `payment.fraud_check.fail_open_on_error`
  (default **true** = unchanged business outcome). **This default needs product sign-off**
  before the branch merges; blocking every order during a Stripe outage is a business
  call, but forging audit records never was.
- **F5 is advisory by default** (`payment.return_security.enforce: false`). Rejecting a
  return happens after Stripe authorised the payment, and the existing penalty table can
  fail a legitimate customer (missing `user_ip` −20 plus a >1h 3DS detour −35 = 45, under
  the threshold of 50).
- **A degraded webhook endpoint answers 503**, not a partial-guard 200. The minimal
  hardcoded chain (HTTPS + payload size, no container, no DB) still gives precise
  rejections for obvious abuse before the 503.

## Environment caveats (pre-existing, verified against v3.2.0)

Two unit failures exist on the **untouched v3.2.0 tag** in this dev shop and are unrelated
to the sprint. Both were re-verified by checking out v3.2.0 (with a cold PHPStan/PHPUnit
cache) and re-running:

- `tests/Unit/Stripe/Controller/` cannot collect: `Class
  "OxidEsales\Payments\PayPal\Controller\PaymentController_parent" not found`.
- `Core/ViewConfigDebugTest`: same root cause via `PayPal\Core\ViewConfig_parent`.

Cause: this shop has paypal + mollie + opalreturns + stripe all extending the same OXID
core classes, and the extension chain cannot be built in the unit-test context. The
`Controller/Webhook/` and `Controller/Admin/` subdirectories run green individually, which
is how S5's controller tests were verified.

**Consequence for CI:** the `--testsuite Unit` suite cannot run as a whole in this
environment. Per-directory runs were used instead, and every directory except the two
above is green.

## Deploy notes

1. **Idempotency keys change shape.** In-flight records keyed the old way stop matching
   after deploy; worst case a retry executes once more. Deploy S2 and S3 together in a
   low-traffic window.
2. **New scheduled command:** `bin/oe-console stripe:prune-idempotency` — add to cron
   (daily). Without it, `oe_payments_idempotency` still grows; `deleteExpired()` had no
   production caller before this sprint.
3. **New parameters:** `payment.fraud_check.fail_open_on_error` (true),
   `payment.return_security.enforce` (false).
4. **`oe:cache:clear`** required — `services.yaml` changed (new command, new arguments,
   new parameters).
5. **Constructor tightenings** are breaking for any code constructing `RefundHelper`,
   `PaymentIntentHelper`, `StripeAdapter` or `StripeAdapterFactory` by hand: the
   idempotency repository and the two idempotent helpers are now required. Production
   wiring already passed them.
