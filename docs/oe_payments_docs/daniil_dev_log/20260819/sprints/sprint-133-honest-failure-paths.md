# Sprint 133 — Honest failure paths: fix false fallbacks & SOLID breaks

**Ticket:** STRP-TBD (assign before branching)
**Branch:** `b-7.4.x-honest-failure-paths-STRP-TBD` (both repos, see *Cross-repo constraint*)
**Source:** [`reports/01-solid-violations-and-misleading-fallbacks.md`](../reports/01-solid-violations-and-misleading-fallbacks.md) — 20 findings (F1-F20), all line-verified 2026-08-19
**Base:** stripe `b-7.4.x` @ v3.2.0 · payment-base `b-7.4.x` @ v3.2.0

---

## The one rule this sprint installs

> A fallback may substitute a value only when it can **verify** the substitution
> is correct, or when it is **provably the safe direction**. Everywhere else —
> money, currency, shop id, fraud verdicts, payment dates — the honest answer is
> an exception or an explicit "unknown" the type system can carry.

Every Critical and High finding is the same defect: *an error path returning a
value indistinguishable from a real one*. The module already contains both
reference implementations of the rule — `CheckoutSessionService::buildLineItems()`
(verifies its itemised sum against OXID's authoritative total before trusting it)
and `StripePaymentCaptureStatusQuery::isPaymentCaptured()` (returns `?bool` so
"unknown" is representable, and logs the PSP failure). Stories below refactor
*into* those two patterns; they are the acceptance bar, not an invention.

## Definition of Done (sprint-level)

1. No production code path fabricates a currency, an amount, a shop id, a
   payment date, or a fraud verdict. Each either resolves the real value or
   fails/returns `null` explicitly.
2. No security control degrades silently: fraud check, webhook guards and return
   validation each either run, or fail the request, or log at `error` with the
   reason.
3. Every behaviour change is driven by a test written first, and every test that
   currently *locks in* a defect is rewritten with the reason recorded in
   `tests/SKIPPED_TESTS_REASON.md` or the test's own docblock (see
   **Behaviour-change register** — this sprint deliberately breaks 5 known
   green tests).
4. All gates green in both repos: `composer phpcs` · `composer phpstan`
   (level max, **no new baseline entries**) · `composer phpmd` ·
   `--testsuite Unit` · `--testsuite Integration` · `./bin/pre-commit-check.sh --full`.
5. Findings F1-F20 each map to a landed story or an explicit, dated deferral.

## Out of scope

- Rewriting the Smart-Contract state machine or the event-dispatcher wiring.
  Stories touch handlers only to make failures visible.
- The `payment-base` → `payment-component` naming drift in the module's
  `CLAUDE.md` (§Source Structure calls it `payment-component`; the directory is
  `extensions/payment-base`). Doc-only; fix in a docs pass.
- Migrating `ModuleConfigurationServiceInterface` consumers to role interfaces —
  that is Sprint 132's job, already specified; do not duplicate it here.
- New features. F5's validator gets wired or deleted, not extended.
- The `composer phpmd` script referencing `tests/PhpMd/standard.xml` while the
  directory holds `phpmd.xml` — pre-existing tooling inconsistency, note it and
  move on.

## Cross-repo constraint (read before Story 1)

`extensions/stripe` and `extensions/payment-base` are **separate git repos**,
both currently on `b-7.4.x` @ v3.2.0. Four stories touch payment-base:

| Story | payment-base change | Coupling |
|---|---|---|
| S4 (F1) | `FraudCheckHandler` — honest audit record + fail-open policy flag | Stripe service must land **after** the handler accepts the flag |
| S9 (F9) | none (Stripe-side parser only) | — |
| S15 (F15) | narrow `ContractStateTransitionsInterface` so consumers stop depending on concrete `PaymentContract` | Stripe narrows typehints **after** the interface exists |
| S20 (F20) | `FraudCheckResponse` — drop the duplicate getter/property API | Breaking for any consumer using getters; sweep both repos |

**Protocol:** payment-base PR first, tagged (`v3.3.0`), stripe `composer.json`
constraint bumped in the same stripe PR that consumes it. Never merge the stripe
side against an untagged payment-base branch — CI already had to be pointed at a
payment-base branch once (`e3842da`), avoid repeating it.

## Behaviour-change register (tests that currently assert the defect)

These are green today **because** the bug is the specified behaviour. Rewriting
them is the point of the story, not a regression — each needs a docblock naming
this sprint and the finding.

| Test | Asserts today | Story |
|---|---|---|
| `StripeRadarFraudCheckServiceTest::testPassesOnApiError` (:106) | API error ⇒ `isSuccessful() === true`, `score === 0.0` | S4 |
| `StripeRadarFraudCheckServiceTest::testPassesWhenRiskScoreNotAvailable` (:91) | no score ⇒ `score === 0.0` (unknown conflated with clean) | S4 |
| `RefundHelperIdempotencyTest` (:57,:94,:110-112) | key literal `'refund:pi_abc123'`; cached replay returns `re_cached` as **success** | S2 |
| `StripeCustomerServiceTest::testCreatesNewCustomerWhenExistingIsStale` (:87) | generic `\Exception('No such customer')` ⇒ treat as stale, create duplicate | S10 |
| `OxpaidReconciliationServiceTest` (:522) + `ReconcileOxpaidCommandTest` (:99) | dry run ⇒ `success: true` | S12 |

`PaymentIntentHelperIdempotencyTest` also asserts the `'capture:'` key shape —
S3 changes the key derivation, so expect fixture churn there even though capture
semantics are unaffected.

## Risks & unknowns

- **PHPStan/PHPMD baselines.** `tests/PhpStan/phpstan-baseline.neon` is
  documented as "OXID virtual parent class errors only" and
  `tests/PhpMd/phpmd.baseline.xml` as "interface-driven adapter complexity only".
  Widening a return type to `?float` (S9) or adding required constructor args
  (S3, S14) can *resolve* baselined entries and fail the gate on a stale
  baseline. **De-risk:** regenerate as the last step of each story and review the
  diff — net-smaller only; a growing baseline fails review.
- **F1's business decision is not the engineer's to make.** Fail-closed fraud
  checking blocks orders during a Stripe outage. S4 therefore separates the
  *audit record* (always honest) from the *blocking policy* (merchant-configurable,
  defaulting to today's behaviour). Do not change the default without product sign-off.
- **F5 blocking-after-authorization hazard.** If the return validator is wired to
  reject, a customer whose money is already authorized ends up with no order.
  S8 wires it **advisory-only** for this reason. Enforcement stays behind a flag
  that defaults off.
- **Zero-decimal currency coverage.** S1's fix is only provable with a JPY-class
  test. There may be no such shop in the fixtures; the unit tests do not need one
  (`AmountConverter` is pure), but the integration suite may. Confirm in S0.
- **`final` classes.** `CaptureService`, `StripeOrderApiService`,
  `OrderActionDispatcher` are `final` — per module CLAUDE.md use real instances
  with mocked dependencies, never `createMock()` on the class itself.
- **Idempotency key change is a data migration in effect.** In-flight records
  keyed the old way stop matching after deploy. Harmless (worst case a retry
  re-executes once) but must be stated in the release note, and argues for
  deploying S2/S3 together during a low-traffic window.

---

# Phase A — money & security (Critical + High)

Nothing in Phase B or C ships before Phase A is green. F2/F3 move real money.

## Story 0 — Spike: refund-request identity & currency threading (de-risking)

**Why:** S1 and S2 both need a decision this sprint cannot fake: *what uniquely
identifies one refund attempt?* Get it wrong and either legitimate repeat refunds
are swallowed or browser retries double-refund. Read-only discovery, no
production code.
**Estimate:** S (½ day)

**Tests first (TDD):** none — output is a committed markdown table in this
dev_log day (`done/sprint-133-story-0-findings.md`), same idiom as Sprint 132
Story 0.

**Implementation steps:**
1. Trace every caller into `RefundService::processRefund` /
   `processRefundByCharge` (currently `StripeRefundRequestHandler:133,140`) and
   record what stable per-attempt identifier each already has in hand: admin
   form CSRF token, `StripeRefundRequestEvent` payload, order id, admin user id.
2. Same for `CaptureService` (capture is once-per-PI at Stripe, so confirm it
   needs no per-request identity — expected outcome: key stays PI-derived).
3. Check `oe_payments_idempotency` schema (payment-base `migration/`) for a
   UNIQUE index on the key column and the column width — the new key must fit.
4. Confirm whether `charge->currency` is available on the DTO already returned by
   `retrievePaymentIntent` (`getChargeIdFromPaymentIntent` fetches the PI), or
   whether S1 needs an extra API call. Prefer zero extra calls.
5. Note whether any integration fixture uses a zero-decimal currency.

**SOLID/Clean check:** n/a (discovery). Output decides S1's currency source and
S2's key composition.

**DevOps gate:** none (no code).

**Definition of Done:** A committed table: caller → available per-attempt
identifier → proposed key input; a yes/no on the extra-API-call question; the
idempotency column width. Zero callers unmapped.

---

## Story 1 — Thread currency into refund minor-unit conversion (F3)

**Why:** `RefundService.php:72` converts partial-refund amounts with
`AmountConverter::toMinorUnits($amount, '')`, which always multiplies by 100. On
a zero-decimal currency that is a **100× over-refund** that stays inside the
charge total, so Stripe accepts it silently. The comment concedes the whole
safety argument is "safe for EUR-primary shops" — a deployment assumption, not a
guarantee. It also violates the rule `MinorUnitConverter:32-33` states in
writing: *"do NOT hardcode 'EUR'"*.
**Estimate:** S (½ day) — highest value-to-risk ratio in the sprint. Do it first.

**Tests first (TDD):**
- `tests/Unit/Stripe/Service/RefundServiceTest.php` (extend)
  - `testProcessRefund_WhenZeroDecimalCurrency_ConvertsWithoutMultiplier` —
    JPY charge, refund ¥1,000, assert the adapter receives `amount === 1000`.
    **Fails today with 100000.**
  - `testProcessRefund_WhenTwoDecimalCurrency_StillConvertsToCents` — EUR
    €25.50 ⇒ `2550`. Regression guard, green before and after.
  - `testProcessRefund_WhenThreeDecimalCurrency_UsesThousandths` — BHD 1.234 ⇒ `1234`.
  - `testProcessRefund_WhenCurrencyUnresolvable_FailsInsteadOfGuessing` — assert
    a `RefundResponse::failure()` (or thrown domain exception) and that
    `createRefundByCharge` is **never** called.
- `tests/Unit/Stripe/Core/AmountConverterTest.php` — no change needed; the
  converter is already correct and case-insensitive. Do not touch it.

**Implementation steps:**
1. Per Story 0, source the currency from the charge/PI already retrieved in
   `getChargeIdFromPaymentIntent()` (rename to reflect it now returns charge id +
   currency, or return a small `ChargeRef` value object — no extra API call).
2. Pass it into `toMinorUnits()`. Delete the "safe for EUR-primary shops" comment
   along with the assumption.
3. Add the unresolvable-currency guard: fail the refund; never fall back to 2
   decimals when the currency is *unknown* (as opposed to genuinely 2-decimal).
4. Sweep `RefundHelper::executeCreateRefundByCharge` for the same pattern.

**SOLID/Clean check:**
- SRP: `RefundService` still orchestrates refunds; conversion stays in
  `AmountConverter`/`MinorUnitConverter`.
- DIP: no new concretion; the charge reference is a value object, not an SDK type
  leaking upward.
- DRY: reuses the existing converter — this story *removes* a divergent
  conversion, it does not add one.
- No overengineering: a `ChargeRef` DTO only if Story 0 shows two call sites need
  it; otherwise thread the string.

**DevOps gate:** `composer phpcs` ✓ · `composer phpstan` ✓ · `composer phpmd` ✓ ·
Unit ✓ · `./bin/pre-commit-check.sh` ✓

**Definition of Done:** No `toMinorUnits(..., '')` anywhere in `src/`; a JPY
partial refund is provably 1× in a unit test; unresolvable currency fails loudly.

---

## Story 2 — Refund idempotency identifies the *request*, not the payment (F2)

**Why:** `RefundHelper` has two sibling methods for one operation, with opposite
semantics and different record shapes:
`refundWithIdempotency` (key `'refund:'.$providerPaymentId`) replays the **first**
refund's stored response as a fresh success on any later call; its sibling
`refundByChargeWithIdempotency` (key `'refund_charge:'.$chargeId`) never checks
`COMPLETED` and re-executes for real. Neither key contains the amount, yet
`RefundPaymentRequest` explicitly supports partial refunds
(payment-base `:18-19`). So path A fabricates success (no money moves, admin sees
a green refund with the *old* `refundId`) and path B — the live admin path via
`StripeRefundRequestHandler:133,140` — permits genuine duplicates.
**Estimate:** M (1½ days)

**Tests first (TDD):**
- `tests/Unit/Stripe/Adapter/Helper/RefundHelperIdempotencyTest.php` (**rewrite** —
  see Behaviour-change register)
  - `testRefund_WhenSameRequestRetried_ReplaysStoredResultWithoutCallingStripe` —
    same request reference ⇒ one Stripe call, second returns the stored response.
    This is the *legitimate* replay and must keep working.
  - `testRefund_WhenSecondDistinctPartialRefund_CallsStripeAgain` — two €10
    refunds with **different** request references ⇒ two Stripe calls, two
    distinct `refundId`s. **Fails today** (path A replays the first).
  - `testRefundByCharge_WhenSameRequestRetried_DoesNotRefundTwice` — **fails
    today** (path B has no `COMPLETED` check).
  - `testRefundByCharge_StoresSerializedResultOnCompletion` — **fails today**
    (`setResult()` is never called on that path).
  - `testBothPaths_UseSameExecutorAndKeyDerivation` — structural guard against
    the semantics diverging again.
- `tests/Unit/Stripe/Adapter/Helper/IdempotentExecutorTest.php` (extend)
  - `testExecute_WhenRecordCompletedButResultNull_ReExecutesRatherThanReturningNull`
    — closes the shape hole path B leaves behind.
- `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php`
  - `testHandle_PassesRequestReferenceThroughToRefundService`.

**Implementation steps:**
1. Introduce the per-attempt reference chosen in Story 0 (recommendation: a
   caller-supplied `requestReference` originating at the admin submit — the
   existing CSRF/session challenge is a natural source — so a browser retry of
   *one* submit dedupes while a *new* submit does not).
2. Derive the key from `(operation, chargeId|paymentIntentId, amountInCents, requestReference)`.
   Hash it if Story 0 shows the column is too narrow.
3. Route **both** public methods through the single `IdempotentExecutor`; delete
   the hand-rolled duplicate flow in `refundByChargeWithIdempotency`, including
   its missing `setResult()`.
4. Thread `requestReference` from `StripeRefundRequestEvent` →
   `RefundService::processRefund*` → helper. Where a caller has no reference
   (console/reconciliation), require an explicit one at the call site rather than
   defaulting — a nameless refund is a bug.

**SOLID/Clean check:**
- DRY: one executor, one key derivation, one record shape. The duplicated
  PROCESSING/COMPLETED/FAILED flow that Sprint 114.8 already extracted stops
  being re-inlined.
- LSP: the two public methods become genuinely interchangeable in their
  idempotency contract — today they are not, which is the finding.
- SRP: key derivation moves to one small collaborator
  (`IdempotencyKeyFactory`) instead of string concatenation at two sites.
- No overengineering: no new abstraction beyond the key factory; the executor
  already exists.

**DevOps gate:** all four ✓ · Unit ✓ · Integration ✓ (idempotency repo is
DB-backed: `tests/Integration/Repository/DoctrineIdempotencyRepositoryTest.php`)
· `./bin/pre-commit-check.sh --full` ✓

**Definition of Done:** Two distinct partial refunds both reach Stripe; one
retried refund reaches Stripe once; both paths share the executor; release note
records that in-flight keys change shape.

---

## Story 3 — Real idempotency: native Stripe keys, required repository, lock reaper (F8)

**Why:** Three linked gaps. (a) `PaymentIntentHelper:42-49` /
`RefundHelper:36-43` take `?IdempotencyRepositoryInterface = null` and
`capturePaymentIntent():84-88` branches past *all* protection when it is null —
no log, no signal, same class and API either way. (b) A grep for
`idempotency_key` across both repos returns **nothing**: the DB executor
deduplicates local invocations but cannot help with the case Stripe's header
exists for — request lands, Stripe performs the operation, response is lost.
`IdempotentExecutor:87-91` then marks the record FAILED and lets the retry
re-execute; for refunds that is a second real refund. (c) A record stuck in
`PROCESSING` (PHP timeout, OOM, deploy restart) blocks that payment for the full
`DEFAULT_TTL_SECONDS = 86400` with the message *"operation already in progress"*
when nothing is running; `deleteExpired()` exists
(`DoctrineIdempotencyRepository:69`) and is called only from a test.
**Estimate:** M (1½ days)

**Tests first (TDD):**
- `tests/Unit/Stripe/Adapter/Helper/PaymentIntentHelperIdempotencyTest.php` (extend)
  - `testCapture_SendsNativeIdempotencyKeyToStripe` — assert the SDK options
    array carries `idempotency_key` equal to the derived reference. **New.**
  - `testConstructor_RequiresIdempotencyRepository` — assert a `TypeError`/
    compile-time requirement once the parameter loses its `= null`.
- `tests/Unit/Stripe/Adapter/Helper/RefundHelperIdempotencyTest.php` (extend)
  - `testRefund_SendsNativeIdempotencyKeyToStripe`.
- `tests/Unit/Stripe/Adapter/Helper/IdempotentExecutorTest.php` (extend)
  - `testExecute_WhenProcessingRecordOlderThanLockTimeout_TreatsItAsAbandoned` —
    **fails today** (throws for 24 h).
  - `testExecute_WhenProcessingRecordWithinLockTimeout_StillThrows` — keeps the
    concurrency guarantee.
- `tests/Unit/Stripe/Command/PruneIdempotencyCommandTest.php` (**new**)
  - `testExecute_DeletesExpiredRecordsAndReportsCount`.

**Implementation steps:**
1. Pass `idempotency_key` on every mutating Stripe call (capture, refund, PI
   create/confirm, cancel) using the Story 2 reference. This is the correct layer
   for the guarantee; the DB executor becomes the *local* lock, not the whole
   defence.
2. Make `IdempotencyRepositoryInterface` a **required** constructor argument on
   both helpers; delete the null branches. Production already wires it
   (`services.yaml:236,244`), so this is a compile-time tightening, not a
   behaviour change.
3. Split the two timeouts: a short **lock** timeout for `PROCESSING`
   (60-120 s — a lock is not a cache) and the existing 24 h **result** TTL. An
   abandoned `PROCESSING` record is reclaimed, and the message distinguishes
   "in progress" from "abandoned, retrying".
4. Add `PruneIdempotencyCommand` next to the existing `ReconcileOxpaidCommand`
   (that directory is the established home for scheduled maintenance) calling
   `deleteExpired()`; document the cron line in `docs/for_merchant/`.

**SOLID/Clean check:**
- DIP: an "optional" collaborator that silently changes behaviour is not
  optional — making it required removes a hidden mode.
- SRP: the command owns scheduling/reporting; the repository owns the delete.
- LSP: helpers keep their interfaces; only construction tightens.
- DRY: reuse the Story 2 reference as the native key — one identity, two layers.
- No overengineering: no distributed-lock abstraction; a timestamp comparison
  covers the observed failure.

**DevOps gate:** all four ✓ · Unit ✓ · Integration ✓ · `./bin/pre-commit-check.sh --full` ✓

**Definition of Done:** Every mutating Stripe call carries a native idempotency
key; neither helper can be constructed without the repository; an abandoned
`PROCESSING` record no longer blocks past the lock timeout; a command prunes
expired records and is documented.

---

## Story 4 — Radar fraud check: honest signal, fail-open as an explicit policy (F1)

**Why:** `StripeRadarFraudCheckService:75-79` catches `Throwable` and returns
`FraudCheckResponse::success(0.0)`. Three defects stacked: the comment promises
*"Log the error for debugging"* but the class has no logger and none is wired
(`services.yaml:1283-1288`), so `$e` is discarded; `success(0.0)` is not
"unknown" but *maximally clean* on the DTO's own documented scale, and
`FraudCheckHandler:80-87` writes `['passed' => true, 'score' => 0.0]` into the
contract as a permanent audit claim that Radar cleared an order it never saw; and
`FraudCheckResponse::error()` — built for exactly this, `score: 1.0`,
*"Highest risk when check fails"* — has **zero callers** repo-wide.
**Estimate:** M (1 day, split across two repos)

**Design decision (needs product sign-off before merge, not before coding):**
separate the *record* from the *policy*. The service always reports honestly
(`error()`); `FraudCheckHandler` decides whether an unscreenable order proceeds,
via `$failOpenOnCheckError` defaulting to **true** so today's business outcome is
preserved — but the fulfilled condition now records
`['passed' => false, 'screened' => false, 'reason' => 'check_error']` instead of
a forged pass. A merchant can flip the flag to fail closed. Blocking every order
during a Stripe outage is a business call; forging audit records never was.

**Tests first (TDD):**
- `tests/Unit/Stripe/Service/StripeRadarFraudCheckServiceTest.php`
  - **Rewrite** `testPassesOnApiError` → `testReturnsErrorResponseOnApiError`:
    assert `isSuccessful() === false`, `score === 1.0`, `getErrorMessage()`
    non-null. Docblock: *"Sprint 133 / F1 — supersedes testPassesOnApiError,
    which asserted the fail-open forgery."*
  - **Rewrite** `testPassesWhenRiskScoreNotAvailable` → assert the *unknown*
    outcome is distinguishable from a real 0.0 score (own error code, e.g.
    `score_unavailable`), not `success(0.0)`.
  - `testLogsErrorWithContractAndPaymentIntentIdOnApiError` — **fails today**
    (no logger exists). Use a `LoggerInterface` mock; assert contract id and PI
    id are in the context, mirroring `StripePaymentCaptureStatusQuery:58-66`.
  - Keep `testPassesWhenNoPaymentIntentId` green but assert it is a *distinct*
    outcome from a check error — "nothing to screen" ≠ "screening failed".
- payment-base `tests/Unit/EventSystem/Handler/FraudCheckHandlerTest.php` (**extend**)
  Note: it already covers pass / fail / disabled / threshold, but has **no test at
  all** for "the check itself errored" — which is how the gap went unnoticed.
  Today an `error()` response (`successful === false`) would fall into the
  `$contract->fail(...)` branch at `FraudCheckHandler:88-95`, i.e. fail *closed*;
  the policy flag below is what preserves the current fail-open business outcome.
  - `testHandle_WhenCheckErrored_AndFailOpenEnabled_FulfilsConditionMarkedUnscreened`
  - `testHandle_WhenCheckErrored_AndFailOpenDisabled_FailsContract`
  - `testHandle_WhenCheckPassed_RecordsRealScore` (regression).

**Implementation steps:**
1. **payment-base first:** add `$failOpenOnCheckError = true` to
   `FraudCheckHandler`; branch on `getErrorMessage() !== null` to distinguish
   "check errored" from "check failed the order"; write the honest condition
   payload. Tag `v3.3.0`.
2. **stripe:** inject `LoggerInterface` (default `NullLogger` per module idiom,
   wired to `@oxid_esales.monolog.logger` in `services.yaml` exactly as
   `StripePaymentCaptureStatusQuery` at `:797`); return
   `FraudCheckResponse::error($e->getMessage())`; log with contract + PI id;
   delete the false comment.
3. Add `payment.fraud_check.fail_open_on_error: true` to the `parameters:` block
   (`services.yaml:1373-1379`) and wire it into the handler.
4. Bump the payment-base constraint in `composer.json` in the same PR.

**SOLID/Clean check:**
- SRP: the service reports a fraud signal; the handler owns the blocking policy.
  Today the service silently owns both by fabricating a score.
- OCP: policy changes by configuration, not by editing the adapter.
- DIP: the logger arrives by injection, matching the class this refactor copies.
- DRY: reuses the existing `error()` factory instead of a new sentinel.
- No overengineering: no retry/circuit-breaker — out of scope, note it.

**DevOps gate:** both repos: all four ✓ · Unit ✓ · payment-base Unit ✓ ·
`./bin/pre-commit-check.sh` ✓

**Definition of Done:** `FraudCheckResponse::error()` has a caller; a Stripe
outage produces a logged error and an audit record that says *unscreened*; the
blocking decision is one configurable flag; the two bug-locking tests are
rewritten with reasons.

---

## Story 5 — Webhook guards fail closed (F4)

**Why:** `WebhookController:62-69` catches a container failure, logs one
`warning` at `init()`, and leaves `$this->guard === null`; line 94's
`$this->getGuard()?->check(...)` then skips the **entire** chain — HTTPS,
IP allowlist, payload-size cap, rate limit. Signature verification still runs
(`StripeWebhookProcessor:63-84`), so this is not an auth bypass, but every
pre-authentication protection on a public endpoint disappears on one DI error,
with no per-request trace. The same `?->` hides the audit log:
`$this->webhookLogger?->logReceived(...)` (:99) and inside `sendErrorResponse()`
(:219) mean a failed logger service yields zero `oe_payments_webhooklogs` rows
while processing continues.
**Estimate:** M (1 day)

**Tests first (TDD):** `WebhookControllerTest` does not exist — create it using
the module's testable-subclass pattern (the controller already exposes
`getGuard()`, `extractWebhookInput()`, `sendErrorResponse()` as protected seams
for exactly this).
- `tests/Unit/Stripe/Controller/Webhook/WebhookControllerTest.php` (**new**)
  - `testRender_WhenGuardChainUnavailable_Returns503AndDoesNotProcess` — **fails
    today** (processes happily). Assert the processor is never invoked.
  - `testRender_WhenGuardChainUnavailable_LogsAtErrorNotWarning`.
  - `testRender_WhenGuardRejects_ReturnsGuardStatusCode` (regression).
  - `testRender_WhenWebhookLoggerUnavailable_StillLogsToFallbackLogger` — the
    audit trail must not be silently optional.
  - `testRender_WhenAllGuardsPass_DelegatesToProcessor` (regression).

**Implementation steps:**
1. Treat an unbuildable guard chain as a service outage: respond `503` with
   `Retry-After`, log at `error`. Stripe retries webhooks, so a 503 is
   recoverable — silently unprotected processing is not.
2. Keep a **minimal always-available** guard constructed without the container
   (HTTPS + payload size need no DB) so a partial DI failure still enforces the
   cheap invariants. `WebhookGuardChain` already composes guards positionally
   (`services.yaml:1325-1333`), so this is a second, hardcoded chain — not a new
   abstraction.
3. Replace the `?->` on the webhook logger with an explicit fallback logger
   (`RequestLogService:51-52` already demonstrates the `$fallbackLogger` idiom in
   this codebase — reuse it, do not invent a variant).

**SOLID/Clean check:**
- OCP preserved: guards are still added by config, per Sprint 64a's chain design.
- SRP: the controller stays HTTP-only; the decision "no guards ⇒ 503" is one
  branch, not new logic.
- DRY: reuse `WebhookGuardChain` and the existing fallback-logger pattern.
- No overengineering: no health-check subsystem; one branch and one hardcoded
  minimal chain.

**DevOps gate:** all four ✓ · Unit ✓ · Integration ✓ (webhook path) ·
`./bin/pre-commit-check.sh --full` ✓

**Definition of Done:** With the guard service removed from the container, the
endpoint answers 503 and processes nothing; payload-size and HTTPS still enforced
by the minimal chain; no `?->` remains on a security control or the audit log.

---

## Story 6 — Implement `confirmPayment()` or refuse it (F6)

**Why:** `StripePaymentHandler:136-142` returns
`PaymentHandlerResult::success(...)` with a note and **no provider call**, against
an interface documented as *"Confirm payment with provider / Result with
confirmation status"* (`PaymentHandlerInterface:41-47`). Textbook LSP violation:
a caller written to the interface receives an answer structurally identical to a
real confirmation. No caller exists today — which is the only reason it is not
already an incident. The module ships beside `opalsubscription` (recurring) and
`opalreturns`, both consuming payment-base interfaces; the first off-session flow
that asks "is this confirmed?" gets a free yes.
**Estimate:** S (½ day)

**Tests first (TDD):**
- `tests/Unit/Stripe/PaymentHandler/StripePaymentHandlerTest.php` (extend)
  - `testConfirmPayment_WhenPaymentIntentSucceeded_ReturnsSuccess`
  - `testConfirmPayment_WhenPaymentIntentRequiresCapture_ReturnsSuccessMarkedAuthorized`
  - `testConfirmPayment_WhenPaymentIntentRequiresAction_ReturnsFailure` — **fails
    today** (unconditional success).
  - `testConfirmPayment_WhenAdapterThrows_ReturnsFailureNotSuccess` — **fails today.**
  - `testConfirmPayment_NeverReturnsSuccessWithoutQueryingProvider` — assert the
    adapter is actually called.

**Implementation steps:**
1. Implement against the PaymentIntent status, reusing the mapping
   `StripePaymentCaptureStatusQuery:70-74` already owns
   (`STATUS_CAPTURED`/`STATUS_AUTHORIZED`/other) — do not re-derive a second
   status map.
2. Return a failure result (not an exception) on adapter errors, matching
   `processPayment`'s existing error contract at `:124-133`.
3. Keep the informational note in metadata, but only alongside a real verdict.

**SOLID/Clean check:**
- LSP: the implementation now honours the interface's documented contract.
- DRY: reuses the existing status mapper rather than a parallel `match`.
- SRP: unchanged — the handler already owns provider dialogue.
- No overengineering: no polling/waiting; one status read.

**DevOps gate:** all four ✓ · Unit ✓ · `./bin/pre-commit-check.sh` ✓

**Definition of Done:** `confirmPayment()` cannot return success without a
provider round-trip; error and requires-action paths return failure.

---

## Story 7 — One currency resolver, zero `'EUR'` literals (F7)

**Why:** Four fallbacks hardcode EUR — `OxidShopAdapter:86`,
`OxidShopOrderService:135`, `PaymentController:125` (all
`$currency->name ?? 'EUR'`) and `StripeReturnResolver:80,86`
(`?? 'EUR'`) — duplicated logic that also breaks the rule
`MinorUnitConverter:32-33` states in writing. A CHF/USD shop whose
`getActShopCurrencyObject()` lacks `name` creates a PaymentIntent in EUR carrying
the CHF amount: CHF 100.00 charged as €100.00, accepted by Stripe, surfacing only
in reconciliation. The two resolver sites are worse than useless — they are
already unreachable (`CheckoutReturnResult::success()` requires a non-null
`string $currency`), so they document an impossible state and would mask a real
regression if the DTO ever loosened.
**Estimate:** S (½ day)

**Tests first (TDD):**
- `tests/Unit/Stripe/Adapter/OxidShopAdapterTest.php` (**new**)
  - `testGetShopCurrency_ReturnsConfiguredCurrencyName`
  - `testGetShopCurrency_WhenCurrencyUnresolvable_ThrowsInsteadOfDefaultingToEur`
    — **fails today.**
- `tests/Unit/Stripe/Service/Return/StripeReturnResolverTest.php` (**new**)
  - `testResolve_UsesCurrencyAndAmountFromValidatedResult`
  - `testResolve_HasNoCurrencyFallback` — structural assertion that the resolver
    passes the DTO's currency through unmodified.
- `tests/Unit/Stripe/Controller/PaymentControllerTest.php` — extend if it exists;
  otherwise cover the shared resolver only and note it.

**Implementation steps:**
1. One resolver (extend `OxidShopAdapter::getShopCurrency()`, the natural home)
   that returns the shop currency or throws a domain exception. A payment must
   never guess its own currency.
2. Point `OxidShopOrderService:135` and `PaymentController:125` at it; delete
   both literals.
3. Delete the two unreachable `?? 'EUR'` and `?? 0.0` in
   `StripeReturnResolver:79-86` so PHPStan keeps the invariant honest.

**SOLID/Clean check:**
- DRY: four copies collapse to one.
- SRP: currency resolution belongs to the shop adapter, not to an order service
  and a controller.
- DIP: consumers depend on the adapter interface, as they already do.
- No overengineering: no currency-service abstraction; one method on an existing
  adapter.

**DevOps gate:** all four ✓ · Unit ✓ · Integration ✓ · `./bin/pre-commit-check.sh` ✓

**Definition of Done:** `grep -rn "'EUR'" src/` returns only genuine
documentation/test-data references; unresolvable currency fails loudly; the dead
resolver fallbacks are gone.

---

## Story 8 — Return-session security: wire it advisory, or delete it (F5)

**Why:** `ReturnSessionSecurityService` (272 lines) scores IP/timing/user-agent
risk against a threshold of 50, is DI-bound (`services.yaml:365-372`) and unit
tested — and has **no production consumer** anywhere in `extensions/` or
`modules/`. `CheckoutReturnResult::securityFailure()` (`:96-110`), the only
producer of error code `security_check_failed`, likewise has zero callers: the
failure branch is unreachable. The module therefore presents a session-hijack
defence that never runs, and green unit tests make the gap invisible in CI.
**Estimate:** M (1 day)

**Decision:** wire it **advisory-only**. Rejecting a return happens *after*
Stripe has authorized, so a false positive strands a paying customer with no
order — and the penalty table can already fail a legitimate one (missing
`user_ip` −20 plus a >1 h 3DS detour −35 = 45, below the threshold of 50).
Advisory means: score computed, warnings logged, result written to contract
metadata and surfaced in the admin Stripe tab; enforcement behind
`payment.return_security.enforce` defaulting to **false**.

**Tests first (TDD):**
- `tests/Unit/Stripe/Service/Return/StripeReturnResolverTest.php` (from S7, extend)
  - `testResolve_InvokesReturnSecurityValidator` — **fails today** (never called).
  - `testResolve_WhenScoreBelowThreshold_AndEnforceDisabled_StillResolvesSuccessfully`
  - `testResolve_WhenScoreBelowThreshold_AndEnforceEnabled_ReturnsSecurityFailure`
    — gives `securityFailure()` its first caller.
  - `testResolve_WritesSecurityWarningsToContractMetadata`
- `tests/Unit/Stripe/Service/ReturnSessionSecurityServiceTest.php` (extend)
  - `testValidateTiming_WhenTimestampMalformed_WarnsMalformedNotVeryLate` —
    **fails today**: `:159` turns a non-numeric timestamp into `elapsed ≈ 1.7e9`,
    so a corrupt-metadata bug reports as a slow customer.
  - `testValidateReturn_MissingIpPlusLateReturn_IsNotAutomaticallyRejected` —
    documents the 45-vs-50 hazard as an intentional, tested boundary.

**Implementation steps:**
1. Inject `ReturnSecurityValidatorInterface` into `StripeReturnResolver` (it
   already holds the contract and the return context — the natural seam) and
   populate `$currentContext` from the request.
2. Log warnings at `warning`, write score + warnings to contract metadata, and
   pass them into the existing admin panel view data.
3. Add the `enforce` parameter; only then produce `securityFailure()`.
4. Add a distinct `malformed_timestamp` warning separate from `very_late_return`.
5. **If product declines the feature:** delete the service, `SecurityValidationResult`,
   `securityFailure()`, the DI binding and both test files in one commit. Do not
   leave the middle state — that is the finding.

**SOLID/Clean check:**
- DIP: the resolver depends on the payment-base interface, not the concrete scorer.
- SRP: scoring stays in the validator; the resolver only consults it.
- OCP: enforcement is configuration, not a code branch per merchant.
- No overengineering: no geo-IP enrichment, no new signals — wire what exists.

**DevOps gate:** all four ✓ · Unit ✓ · Integration ✓ · `./bin/pre-commit-check.sh --full` ✓

**Definition of Done:** either the validator runs on every Stripe return with its
result visible in contract metadata and admin, `securityFailure()` reachable
under the enforce flag — or the whole cluster is deleted. No third outcome.

---

# Phase B — the honesty pass (Medium)

Same rule, smaller blast radius. Each story is ≤½ day; they are independent and
can be parallelised across two engineers. Format compressed: **Why → RED tests →
steps → gate → DoD**; the SOLID/Clean check for all of Phase B is the sprint rule
plus "no new suppressions".

## Story 9 — `?float` for webhook amounts (F9)

**Why:** `StripeWebhookEventParser:83-95` returns `0.0` for a missing or
non-int amount, so "field absent", "field malformed" and "genuinely zero" are one
value. Downstream, a 0.00 captured amount makes
`CapturableAmount::remaining($authorized, 0.0)` report the **full** amount as
still capturable, and `WebhookContractFulfillmentHandler:53` records a 0.00
capture for a real one.
**RED:** `tests/Unit/Stripe/Webhook/StripeWebhookEventParserTest.php` (**new**) —
`testExtractAmount_WhenFieldMissing_ReturnsNull`,
`testExtractAmount_WhenFieldNotInteger_ReturnsNull`,
`testExtractAmount_WhenAmountIsZero_ReturnsZeroNotNull`,
`testExtractAmount_ConvertsZeroDecimalCurrencyWithoutDivision`. Plus
`WebhookContractFulfillmentHandlerAuditTest` — `testAudit_WhenAmountUnknown_DoesNotRecordZero`.
**Steps:** widen to `?float`; make the amount-bearing handlers treat `null` as a
processing failure so Stripe retries rather than committing a fiction; audit the
`?? 0.0` at `WebhookContractFulfillmentHandler:53` in the same pass.
**Gate:** all four ✓ · Unit ✓ · Integration ✓ (baseline diff reviewed — a widened
return type may resolve entries).
**DoD:** no amount sentinel remains; an unparseable amount fails the webhook.

## Story 10 — "Customer missing" means missing, not "any error" (F10)

**Why:** `StripeCustomerService:88-96` catches `\Throwable` ⇒ `false`. The caller
(`:41-55`) then logs *"Stale Stripe Customer ID, creating new one"* — a claim it
has not established — creates a second Stripe Customer and **overwrites** the
stored mapping. One transient blip permanently repoints the user's
`oe_payments_customer` row; saved payment methods, mandates and Radar history on
the old Customer become unreachable, irreversibly from the shop side.
**RED:** `tests/Unit/Stripe/Service/StripeCustomerServiceTest.php` — **rewrite**
`testCreatesNewCustomerWhenExistingIsStale` to throw
`InvalidRequestException` with code `resource_missing` (today it throws a generic
`\Exception`, locking in the bug); add
`testTransientApiErrorIsRethrownAndMappingUnchanged` and
`testTransientApiError_DoesNotCreateDuplicateCustomer` — both **fail today**.
**Steps:** catch `InvalidRequestException`, inspect the Stripe error code, return
`false` only for `resource_missing`; rethrow everything else so checkout fails
loudly and retryably instead of mutating durable state on a guess.
**Gate:** all four ✓ · Unit ✓.
**DoD:** only a confirmed 404 from Stripe can repoint a customer mapping.

## Story 11 — Stop inventing payment dates (F11)

**Why:** `OxpaidReconciliationService:176-184` writes
`$capturedAt?->format(...) ?? date('Y-m-d H:i:s')` into `oxorder.OXPAID` — the
payment date used for accounting exports and dunning — so a catch-up cron stamps
its own run time, moving payment dates by days into the wrong accounting period.
`OxidShopOrderService:346-359` does the same for `oxorderdate` with a bare-comment
`catch`.
**RED:** `tests/Unit/Stripe/Service/OxpaidReconciliationServiceTest.php` —
`testReconcile_WhenCapturedAtMissing_DoesNotWriteOxpaid`,
`testReconcile_WhenCapturedAtMissing_ReturnsSkippedNeedsReview` (**fail today**).
`tests/Unit/Stripe/Adapter/OxidShopOrderServiceTest.php` —
`testOrderCreationDate_WhenUnparseable_LogsAndPropagatesInsteadOfUsingNow`.
**Steps:** if the PSP cannot say when money moved, do not write it — return the
existing `skipped` action with a needs-review reason and log at `warning`;
same for the order date.
**Gate:** all four ✓ · Unit ✓ · Integration ✓ (reconciliation touches DB).
**DoD:** no financial timestamp is ever synthesised from `date()`/`new DateTimeImmutable()`.

## Story 12 — Reconciliation audit tells the truth (F12)

**Why:** `OxpaidReconciliationService:201` prints `NO_CONTRACT` whenever
`fulfill()` returns `false`, but the method already returned early at `:85-94`
when no contract exists — so the one artefact you consult to explain a payment
discrepancy actively misdirects. Also `:156-164` reports `success: true` for a
dry run (callers counting successes over-report) and `:197-199` makes the
financial audit log an optional constructor argument.
**RED:** `tests/Unit/Stripe/Service/OxpaidReconciliationServiceTest.php` —
`testLog_WhenContractFoundButUnchanged_SaysContractUnchangedNotNoContract`
(**fails today**), `testDryRun_DoesNotReportSuccess` (**rewrites** the `:522`
assertion — see register), `testConstructor_RequiresFileLogger`. Update
`ReconcileOxpaidCommandTest:99` accordingly.
**Steps:** three states (`CONTRACT_FULFILLED` / `CONTRACT_UNCHANGED` /
`NO_CONTRACT`); a distinct field or `success: false` for dry runs; require the
logger.
**Gate:** all four ✓ · Unit ✓.
**DoD:** every reconciliation log line is verifiable against the code path that
produced it.

## Story 13 — Mode-scope the webhook secret fallback (F13)

**Why:** `ModuleConfigurationService:133-149` picks the per-mode secret by
`isTestMode()` but falls back to a mode-agnostic legacy setting. A shop that
pasted a **test** signing secret before auto-registration existed and then went
live verifies live webhooks with the test secret: every webhook 400s, Stripe
retries and gives up, and because verification is (correctly) fail-closed the
symptom is not an error in the shop but **orders that silently never become
paid**. A fallback that looks like backwards-compatibility turns a
missing-config error into a payment-status outage.
**RED:** `tests/Unit/Stripe/Service/ModuleConfigurationServiceTest.php` (**new**)
— `testWebhookSecret_InLiveMode_DoesNotFallBackToLegacyTestSecret` (**fails
today**), `testWebhookSecret_UsesModeSpecificWhenPresent`,
`testWebhookSecret_WhenMissingForCurrentMode_ReportsUnconfigured`.
**Steps:** scope the legacy fallback to the mode it was stored under (or drop it
behind a one-off migration); surface "webhook secret missing for current mode" as
a module health warning in the admin panel (`StripePanelViewDataBuilder` already
renders mode state) instead of letting it present as signature failures.
**Gate:** all four ✓ · Unit ✓ · Integration ✓ (webhook signature path).
**DoD:** a test-mode secret can never verify a live webhook; missing config is
visible in admin before the first webhook arrives.

## Story 14 — Shop id is required context (F14)

**Why:** `StripePaymentHandler:259` and `StripeCheckoutSessionHandler:149` both
do `is_numeric($shopId) ? (int) $shopId : 1`, so in an EE multishop install a
non-numeric or unavailable shop id silently attributes the checkout session — and
the metadata that later resolves contract and order — to shop 1. Duplicated, so a
fix must find both.
**RED:** `tests/Unit/Stripe/PaymentHandler/StripePaymentHandlerTest.php` and
`tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutSessionHandlerTest.php` —
`testCheckoutSession_WhenShopIdUnresolvable_ThrowsInsteadOfDefaultingToShopOne`
(**fails today**), `testCheckoutSession_UsesActiveShopId` (regression). There is
precedent: `WebhookContractFulfillmentHandlerShopIdTest` already exists.
**Steps:** one resolver that returns the active shop id or throws; both call
sites use it.
**Gate:** all four ✓ · Unit ✓.
**DoD:** no `: 1` shop-id default in `src/`.

## Story 15 — One invariant, one reaction (F15)

**Why:** The same check produces opposite behaviour:
`StripePaymentHandler:224-231` throws `LogicException` naming the offending class
(correct); `RetryCleanupService:83-87` returns `false`, indistinguishable from
"nothing to clean", so a contract that can never satisfy the guard is re-fetched
by `findStaleNotFinished()` after **every** webhook
(`WebhookController:192-208`), never cleaned and never reported. Both depend on
concrete `PaymentContract` rather than the interface, because payment-base keeps
state transitions off the abstraction — honestly documented at `:222-224`, but it
forces every consumer to narrow-and-throw or narrow-and-skip.
**RED:** `tests/Unit/Stripe/Service/RetryCleanupServiceTest.php` —
`testCleanup_WhenContractIsNotConcreteType_LogsAndCountsAsFailure` (**fails
today**: silent `false`). payment-base:
`testContractStateTransitionsInterface_IsImplementedByPaymentContract`.
**Steps:** payment-base exposes a narrow `ContractStateTransitionsInterface`
(cancel / transitionToNotFinished / transitionToPending) — the transitions
consumers actually need; Stripe narrows both typehints to it; the cleanup service
logs and distinguishes "skipped" from "cleaned". Cross-repo: payment-base first,
per the protocol above.
**Gate:** both repos: all four ✓ · Unit ✓.
**DoD:** neither class depends on the concrete contract; an unsatisfiable
contract is logged once, not retried forever in silence.

## Story 16 — Fail-silent handlers become visible (F16)

**Why:** ~10 handlers open with `if (!$event instanceof X) { return; }` —
`FraudCheckHandler:51-53`, `StripeCaptureRequestHandler:62`,
`StripeCheckoutSessionHandler:61`, `StripePaymentStatusHandler:52` and peers —
and `FraudCheckHandler:56-60` does the same for a missing contract. As the *only*
behaviour this is a fail-silent state machine: a tag/priority regression in
`services.yaml` means the condition is never fulfilled, the contract stalls, no
order is created, the customer's money is authorized at Stripe, and **not one log
line is written**.
**RED:** one test per handler family, e.g.
`testHandle_WhenEventTypeMismatched_LogsWarningAndReturns` (**fails today**);
`tests/Unit/Stripe/Service/StalledContractWatchdogTest.php` (**new**) —
`testFindsContractsStuckInPendingBeyondThreshold`,
`testIgnoresRecentPendingContracts`.
**Steps:** log at `warning` before returning — the dispatcher knows the expected
class via `getHandledEventClass()`, so a mismatch is a wiring bug, not a normal
condition. Add a stalled-`PENDING` watchdog beside the existing stale-`NOT_FINISHED`
sweep in `RetryCleanupService`, reported through the same webhook-tail hook or
`ReconcileOxpaidCommand`.
**Gate:** all four ✓ · Unit ✓ · Integration ✓.
**DoD:** every silent handler exit is logged; a contract stuck in PENDING is
detected and reported rather than waiting forever.

---

# Phase C — structure (Low)

Schedule with the next refactor window; none of these move money. Include them so
the report closes out fully.

## Story 17 — `StripeOrderController`: extract and inject (F17)

**Why:** 776 lines, 35 methods: HTTP routing, JSON emission, response headers,
CSRF challenge, AGB consent, user-data validation, basket buyability,
event-context assembly, contract loading, stale-checkout cleanup, error
formatting and translation — change any one and you edit this file. Dependencies
are pulled from the container inside `getRequestHelper()`,
`getUserDataValidator()`, `getBasketBuyabilityValidator()`,
`getLanguageTranslator()` via the `ServiceContainer` trait: service location, not
DI, so the real dependency list is invisible at the constructor and tests can only
reach it by subclassing protected seams. `Controller/Admin/ModuleConfiguration.php`
(506 lines) has the same shape.
**RED:** characterization tests for each group *before* moving anything —
`CheckoutPreconditionsValidatorTest`, `ReturnInputReaderTest`,
`CheckoutErrorResponderTest` — then the existing `StripeOrderController` tests
stay green as the delegation regression net.
**Steps:** extract the validation / formatting / cleanup groups (already separate
collaborators — they only need wiring); leave routing + delegation. Keep the
seam-override pattern where OXID's `_parent` chain forbids constructor DI, but
narrow each seam to one injected collaborator.
**Gate:** all four ✓ · Unit ✓ · Integration ✓ · PHPMD baseline **net-smaller**.
**DoD:** controller under ~300 lines, every public action ≤25 lines per
CLAUDE.md, no behaviour change.

## Story 18 — Activation/deactivation stops hiding failures (F18)

**Why:** `Events::deletePaymentMethod():154-167` is `catch (Exception) { // do nothing }`,
so a failed cleanup of the removed `stripepaypal` method is invisible and it keeps
appearing in admin. `deactivatePaymentMethods():173-176` is an **empty body**
under a name that promises action — reading `onDeactivate()` suggests
deactivation happens; Stripe payment methods stay active after the module is
switched off. And `onDeactivate():84-90` wraps everything in
`if (Registry::getConfig()->isAdmin())`, so the documented CLI path
(`bin/oe-console oe:module:deactivate`) skips the file-cache reset entirely.
**RED:** `tests/Unit/Stripe/Core/EventsTest.php` —
`testDeletePaymentMethod_WhenDbFails_LogsError`,
`testOnDeactivate_ViaCli_StillClearsCache` (**fails today**),
`testDeactivatePaymentMethods_ActuallyDeactivatesOrIsRemoved`.
**Steps:** log the swallowed DB error; either implement deactivation or delete the
method and its call (an empty method that promises action is the finding); drop
the `isAdmin()` gate around cache clearing.
**Gate:** all four ✓ · Unit ✓ · Integration ✓ (activation touches DB).
**DoD:** no silent failure in activation/deactivation; no method that promises
work it does not do.

## Story 19 — Never hand the frontend an empty publishable key (F19)

**Why:** `ViewConfig:29-40` returns `null` when the container lookup throws and
`getStripePublishableKey():180-187` maps that to `''`, so the template boots
Stripe.js with an empty key — a client-side failure with no server-side error,
presenting to the customer as a checkout that simply does not work. The catch is
on `Throwable`, so it covers genuine runtime misconfiguration, not only the
"module being deactivated" case the comment cites. `$this->stripeConfig` is also
never memoised on the failure path, so every call retries the failing lookup.
(`isStripeIframeCheckout():191-199` is the *acceptable* version — falling back to
redirect checkout is a working flow.)
**RED:** `tests/Unit/Stripe/Core/ViewConfigTest.php` —
`testPublishableKey_WhenConfigUnavailable_DoesNotReturnEmptyString`,
`testPublishableKey_WhenConfigUnavailable_LogsError`,
`testConfigLookupFailure_IsNotRetriedOnEveryCall`.
**Steps:** distinguish deactivation from misconfiguration; log at `error` and let
the template render a payment-unavailable state rather than a broken widget;
memoise the failure.
**Gate:** all four ✓ · Unit ✓.
**DoD:** the frontend either gets a usable key or an explicit unavailable state.

## Story 20 — Name and shape cleanups (F20)

**Why:** `OxidShopAdapter::isTestMode():89-92` returns
`(bool) getConfigParam('blDebugMode')` while
`StripeAdapterFactory::isTestMode():116-119` returns the Stripe mode from module
config — one method name, two contradictory meanings. The adapter version has no
consumers today, which is the only thing keeping it harmless, and a trap for
whoever next wires a `ShopAdapter` into anything mode-sensitive. Also
`FraudCheckResponse` exposes every field twice (public readonly properties **and**
getters, payment-base `:32-121`) with consumers already mixing styles
(`FraudCheckHandler:78` uses `isSuccessful()`, `:85` uses `$result->score`), and
`OxidShopAdapter::getShopName():76-81` guesses the merchant's identity as
`'OXID eShop'` — it reaches Stripe as session branding.
**RED:** `OxidShopAdapterTest` (from S7) —
`testIsDebugMode_IsNotConfusedWithStripeTestMode`,
`testGetShopName_WhenUnavailable_DoesNotInventABrand`. payment-base:
`FraudCheckResponseTest::testExposesSingleAccessorStyle`.
**Steps:** rename to `isShopDebugMode()` or delete it; pick one accessor style on
`FraudCheckResponse` (properties, given `readonly`) and sweep both repos —
cross-repo, payment-base first; make the shop-name fallback explicit or fail.
**Gate:** both repos: all four ✓ · Unit ✓.
**DoD:** no method name means two things; one accessor style per DTO.

---

## Suggested order

1. **S0** (½ d) — unblocks S1-S3; prevents a wrong idempotency key, which is
   expensive to undo once records exist.
2. **S1** (½ d) — smallest fix, largest money exposure. Ship alone if the sprint
   is cut short.
3. **S2 + S3** (3 d) — deploy **together**: S2 changes key shape, S3 changes the
   lock semantics and adds the native key. One low-traffic release window, one
   release note.
4. **S4** (1 d) — cross-repo; start the payment-base PR early so review latency
   overlaps S2/S3.
5. **S5** (1 d) — independent, self-contained; good parallel track for a second
   engineer.
6. **S6, S7** (1 d) — mechanical once S1's currency resolver exists (S7 reuses it).
7. **S8** (1 d) — needs the product decision from the *Risks* section; gate the
   start on that answer, not on code.
8. **Phase B, S9-S16** (≈3 d, parallelisable) — independent of each other.
9. **Phase C, S17-S20** — next refactor window.

Phase A is ≈8 engineer-days including the spike, so a one-week sprint fits Phase A
only. Recommendation: **Sprint 133 = Phase A**, **Sprint 134 = Phases B + C**;
this document stays the single source for both so the traceability table below
does not fragment.

## Traceability: finding → story

| Finding | Severity | Story |
|---|---|---|
| F1 Radar fails open, forges clean score | Critical | S4 |
| F2 Contradictory refund idempotency | Critical | S2 |
| F3 Partial refund 2-decimal assumption | Critical | S1 |
| F4 Webhook guards fail open silently | High | S5 |
| F5 Dead return-security control | High | S8 |
| F6 `confirmPayment()` hardcoded success | High | S6 |
| F7 Hardcoded `'EUR'` ×4 | High | S7 |
| F8 Optional/non-native idempotency, stuck locks | High | S3 |
| F9 `0.0` as "unknown" amount | Medium | S9 |
| F10 Any error ⇒ "customer missing" | Medium | S10 |
| F11 Fabricated payment dates | Medium | S11 |
| F12 False reconciliation audit labels | Medium | S12 |
| F13 Mode-agnostic webhook-secret fallback | Medium | S13 |
| F14 `shopId` defaults to 1 | Medium | S14 |
| F15 Same invariant, opposite reactions | Medium | S15 |
| F16 Fail-silent handlers | Medium | S16 |
| F17 God controller + service location | Medium | S17 |
| F18 Silent activation failures, empty promise | Low | S18 |
| F19 Empty publishable key | Low | S19 |
| F20 `isTestMode()` ambiguity, dual DTO API | Low | S20 |

All 20 findings mapped; none deferred without a story.
