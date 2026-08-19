# Sprint 133 — Delivery report: honest failure paths

**Date:** 2026-08-19
**Sprint:** [133 — Honest failure paths](../sprints/sprint-133-honest-failure-paths.md)
**Source review:** [01 — SOLID violations & misleading fallbacks](01-solid-violations-and-misleading-fallbacks.md)
**Branch (both repos):** `b-7.4.x-honest-failure-paths`, from `b-7.4.x` @ v3.2.0
**Status:** Phases A + B delivered (F1–F16). Phase C (F17–F20) not started. CI green on 7.4 and 7.5. Nothing merged; two policy defaults await product sign-off.

---

## 1. What this sprint was about

One rule, installed across sixteen findings:

> A fallback may substitute a value only when it can **verify** the substitution is
> correct, or when it is **provably the safe direction**. Everywhere else — money,
> currency, shop id, fraud verdicts, payment dates — the honest answer is an
> exception or an explicit "unknown" the type system can carry.

Every Critical and High finding was the same defect wearing different clothes: *an
error path returning a value indistinguishable from a real one.* A Stripe outage
produced contracts stamped "fraud check passed, score 0.00". A partial refund on a
zero-decimal currency sent 100× the intended amount. A container hiccup silently
switched off every pre-authentication protection on a public webhook endpoint.

## 2. Delivered

| # | Finding | Sev | Story | Commit |
|---|---|---|---|---|
| F1 | Radar fails open and forges a clean score | Critical | S4 | `f998358` + `6fa30c7` |
| F2 | Two contradictory refund-idempotency semantics | Critical | S2 | `8f606e9` + `3daae7f` |
| F3 | Partial refund assumes 2 decimals (100× on JPY) | Critical | S1 | `d0b31df` |
| F4 | Webhook guards fail open silently | High | S5 | `4a8df2f` |
| F5 | Return-security control wired, tested, never called | High | S8 | `cbbe171` |
| F6 | `confirmPayment()` hardcoded success | High | S6 | `cac000d` |
| F7 | Hardcoded `'EUR'` (4 reported, 6 actual) | High | S7 | `a78f3d6` |
| F8 | Optional / non-native idempotency, 24 h stuck locks | High | S3 | `017a066` |
| F9 | `0.0` used as "unknown amount" | Medium | S9 | `6868ec4` |
| F10 | Any error read as "customer missing" | Medium | S10 | `e03a4f4` |
| F11 | Fabricated payment dates | Medium | S11 | `e03a4f4` |
| F12 | False reconciliation audit labels | Medium | S12 | `e03a4f4` |
| F13 | Mode-agnostic webhook-secret fallback | Medium | S13 | `aa7409e` |
| F14 | `shopId` silently 1 (or 0) | Medium | S14 | `aa7409e` |
| F15 | Same invariant, opposite reactions | Medium | S15 | `2500d13` + `67a6531` |
| F16 | Fail-silent handlers | Medium | S16 | `9cd704d` + `d91413e` |
| F17–F20 | God controller · activation cleanup · empty publishable key · `isTestMode()` clash | Low | — | **not started** |

Plus one unplanned item: **CI was broken for everyone** and had to be fixed to
validate any of this (§6).

**Volume:** stripe 69 files, +5097/−283 (33 src, 27 tests, 6 docs, CI, `services.yaml`);
payment-base 6 files, +309/−2. 21 commits total.

## 3. Evidence

| Gate | stripe | payment-base |
|---|---|---|
| `phpcs` (PSR-12) | clean | clean |
| `phpstan` level max | **0 errors**, identical to the v3.2.0 baseline, no new baseline entries | 0 errors |
| `phpmd` (with baseline) | clean | clean |
| Unit (CI, full suite) | **1499 tests / 3915 assertions** | **1128 tests** / 6 skipped (was 1122) |
| Integration | **89/89 local · 80/80 CI**, both PHP matrices | — |
| Container compile | `install_shop_with_module` green — module activates with the new required constructor args | — |

CI: [7.4 run 32268997628](https://github.com/OXID-eSales/stripe-wallet/actions/runs/32268997628) ·
[7.5 run 32268997691](https://github.com/OXID-eSales/stripe-wallet/actions/runs/32268997691).

## 4. What the RED runs proved

Every story ran RED → GREEN, so the defects were **reproduced, not inferred**:

- **F3** — a ¥1,000 partial refund reached the adapter as `100000`; BHD 1.234 as `123`.
- **F2** — a second refund with a *different* request reference produced the *same*
  idempotency key; the by-charge path called Stripe again on an already-completed
  record and stored `null` as its result, so replay was impossible either way.
- **F8** — helpers built without a repository silently skipped all protection; no
  Stripe call carried `idempotency_key`; a `PROCESSING` record blocked retries for 24 h.
- **F1** — the fraud service had no logger to log with, and
  `FraudCheckResponse::error()` had zero callers repo-wide.
- **F6** — `confirmPayment('pi_whatever')` returned success with no adapter at all.
- **F12** — the failing assertion printed the literal contradiction:
  `SUCCESS: Order=order_unchanged PaymentIntent=pi_unchanged NO_CONTRACT`.
- **F15** — a `PaymentContractInterface` test double was rejected outright, proving
  the concrete-class narrowing excluded implementations satisfying every method used.

## 5. CI was guarding the defects

Nine tests asserted the broken behaviour as correct. All were rewritten with the
reason recorded in a docblock; none were quietly deleted.

| Test | Asserted | Story |
|---|---|---|
| `StripeRadarFraudCheckServiceTest::testPassesOnApiError` | API error ⇒ pass, score 0.0 | S4 |
| `…::testPassesWhenRiskScoreNotAvailable` | unknown ⇒ score 0.0 | S4 |
| `RefundHelperIdempotencyTest` (keys, cached replay) | payment-scoped keys; replay as fresh success | S2 |
| `PaymentIntentHelperIdempotencyTest::captureWithoutIdempotency…` | unprotected mode is fine | S3 |
| `RefundHelperIdempotencyTest::refundPaymentWithoutIdempotency…` | unprotected mode is fine | S3 |
| `StripePaymentHandlerTest::testConfirmPaymentReturnsSuccessForContractId` | success without a provider call | S6 |
| `WebhookControllerGuardIntegrationTest::controllerWorksWithoutGuard` | endpoint works with no guards | S5 |
| `…::guardChainUnavailableRendersWarnsAndContinues` | warn-and-continue, **documented as required** | S5 |
| `WebhookContractFulfillmentHandlerAuditTest::…WritesCaptureAuditRow…` | only the row *type*, silently accepting a 0.00 amount | S9 |
| *(removed)* `CheckoutReturnServiceTest::testCheckoutReturnResultSecurityFailure` | a factory with no production caller | S8 |

The sharpest example: a test whose docblock stated that render() *"must then
CONTINUE without the guard (warn-and-continue)"* — the security defect written down
as a requirement and protected by a green suite.

## 6. CI fix (unplanned, blocking)

The branch's first CI run failed, and investigation showed **two independent
breaks, neither a test failure**:

1. **Mainline had been red for 8 days.** The workflow aliases the payment-base path
   repository `as v1.0.0`, but this module's `composer.json` moved to
   `payment-base: ^1.1` when payment-base v1.1.0 shipped on 2026-08-11 — the same
   day CI went red. Composer could not resolve the module at shop level. The alias
   is now a single `PAYMENT_BASE_ALIAS` env var (it had drifted precisely because
   the value was repeated at four call sites).
2. **Any non-default branch failed.** `composer require <module>:*` resolves only
   for the repository's default branch, which composer exempts from the stability
   filter; feature branches died on `does not match your minimum-stability`.
   Requiring `:*@dev` sets the flag explicitly.

Commit `4959da8`. **`b-7.4.x` itself is still red** until this lands there — a
cherry-pick would unblock everyone, but pushing to the mainline branch was left to
a human.

## 7. Where the review was wrong

The review is not to be trusted beyond its evidence. Implementation corrected it in
five places:

1. **F16 was overstated.** "~10 handlers … not one log line" — in fact **6 of 9**
   stripe handlers already logged the mismatch, including *every* handler that
   advances the contract state machine. The real gaps were payment-base's
   `FraudCheckHandler` and three OPC URL/cleanup handlers.
2. **F16's watchdog was unnecessary, and hid a better fix.**
   `findStaleNotFinished()` already includes the `pending` state, so stalled
   contracts are already swept. What was actually missing: that finder **and two
   siblings** caught the DB exception and returned `[]` — indistinguishable from
   "nothing stale" — so a failing query turned the post-webhook sweep into a
   permanent silent no-op.
3. **F15 was smaller than assumed.** The sprint proposed a new
   `ContractStateTransitionsInterface`; but `cancel()`, `getState()`,
   `getOrderId()`, `fail()` and `transitionToPending()` were **already** on
   `PaymentContractInterface`. Only `transitionToNotFinished()` was missing, so one
   additive method removed the DIP break at its root with no new abstraction. The
   code comment calling the narrow surface "intentional" was inaccurate.
4. **F14 had four more sites.** Besides the two `… : 1` defaults, four audit-row
   sites did `(int) getShopId()`, which yields **0** — not a shop — and would
   truncate `'12abc'` to shop 12.
5. **F7 had two more sites** than the four reported: `CaptureService`'s transaction
   audit row and the checkout footer widget.

One coverage-shape lesson worth keeping: the suite *looked* JPY-safe. A green
`testProcessRefundJpyPreservesZeroDecimalAmount` existed — but it exercised the
**full**-refund path, where no major→minor conversion happens at all, so it could
never have caught the 100× partial-refund bug.

## 8. Open decisions (needed before merge)

1. **F1 — fail-open policy.** `payment.fraud_check.fail_open_on_error` defaults to
   **true**, preserving today's business outcome: an order proceeds when Radar
   cannot be reached, but the contract now records `screened: false` instead of a
   forged pass. Blocking every order during a Stripe outage is a business call;
   forging audit records never was. **Product sign-off required.**
2. **F5 — enforcement off.** `payment.return_security.enforce` defaults to
   **false**. Rejecting a return happens *after* Stripe authorised the payment, so
   a false positive strands a paying customer with no order — and the existing
   penalty table can fail a legitimate one (missing `user_ip` −20 plus a >1 h 3DS
   detour −35 = 45, under the threshold of 50).
3. **S13 deviates from the sprint's own DoD.** It said to scope the legacy webhook
   secret "to the mode it was stored under" — not knowable, and a test-only scope
   would break live shops running on a manually pasted live secret. Implemented
   instead: refuse the legacy fallback only when the *other* mode already has its
   own per-mode secret, i.e. when observable state proves the shop auto-registered
   since. Closes the test→live hazard, regresses nobody.

## 9. Deferred, with reasons

- **Phase C (F17–F20)** — 776-line controller extraction, activation-event cleanup,
  empty publishable key, `isTestMode()` naming clash. Structural; no money at stake.
- **`Transaction::$amount` cannot express "unknown".** S9 made the fabricated 0.00
  an explicit guard, but representing an unknown amount in the model is a schema
  question, not a fallback fix.
- **`AbstractPaymentRefundService::executeRefund()` (payment-base)** builds
  `RefundPaymentRequest` without a currency, so any provider using it inherits the
  2-decimal assumption S1 removed from the Stripe path. No subclasses in this
  workspace, so it is latent — worth a payment-base story.
- **`composer phpmd` is broken** (points at `tests/PhpMd/standard.xml`, which does
  not exist). Pre-existing; the working invocation lives in
  `.github/scripts/codestyle_check.sh`. The command documented in CLAUDE.md fails.

## 10. Deploy runbook

1. **Merge order:** payment-base first, tag, bump the stripe constraint, then
   stripe. Then revert `PAYMENT_BASE_BRANCH` to `'b-7.4.x'` (a `REVERT` note marks
   the line).
2. **Deploy S2 + S3 together, low-traffic window.** Idempotency keys change shape;
   in-flight records stop matching. Worst case a retry executes once more.
3. **New cron:** `bin/oe-console stripe:prune-idempotency` (daily).
   `deleteExpired()` had no production caller before this sprint.
4. **`oe:cache:clear` required** — `services.yaml` gained a command, new parameters
   and explicit logger arguments.
5. **New parameters:** `payment.fraud_check.fail_open_on_error` (true),
   `payment.return_security.enforce` (false).
6. **Breaking for hand-constructed objects:** `RefundHelper`, `PaymentIntentHelper`,
   `StripeAdapter`, `StripeAdapterFactory` and `OxpaidReconciliationService` now
   require collaborators that used to default to null. Production wiring already
   passed them.
7. **Behaviour changes to announce:** a `charge.refunded` webhook with an unreadable
   amount now returns 500 (Stripe retries) instead of recording a 0.00 refund; an
   unresolvable shop id or currency now throws instead of quietly using shop 1 or
   EUR.

## 11. Environment caveat

`--testsuite Unit` cannot run as a whole in this dev shop: paypal, mollie,
opalreturns and stripe all extend the same OXID core classes and the extension
chain cannot be built in unit-test context. Two failures
(`Unit/Stripe/Controller/` collection and `Core/ViewConfigDebugTest`) exist
**unchanged on the untouched v3.2.0 tag** — verified by checking it out with cold
PHPStan and PHPUnit caches. Local verification therefore ran per-directory; CI,
which installs only stripe + payment-base, runs the full suite green (1499 tests).
