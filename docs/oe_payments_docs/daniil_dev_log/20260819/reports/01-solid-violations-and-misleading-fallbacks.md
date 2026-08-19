# Code Review — SOLID violations, false & misleading fallbacks

**Date:** 2026-08-19
**Scope:** `source/extensions/stripe/src/` (179 files, ~18.7k LOC), plus the
`payment-base` collaborators the Stripe module binds to.
**Question asked:** where does the code violate SOLID, and where does it carry a
*false or misleading fallback* — a default, catch-block or stub that keeps the
system running while silently reporting something untrue, and that will break at
an edge case?
**Method:** static reading of every fail-open `catch`, every literal default
(`?? 'EUR'`, `?? 0.0`, `: 1`), every `?->` on a security control, every
unconditional `success()`; each finding traced to its live caller before being
written down. No test run was needed for these; where a claim depends on a path
being reachable, the caller chain is named.

---

## Severity summary

| # | Finding | Principle | Severity |
|---|---------|-----------|----------|
| F1 | Radar fraud check fails open **and** forges a clean score | SRP / honest-signal | **Critical** |
| F2 | Two contradictory refund-idempotency semantics; neither is per-request correct | LSP / DRY | **Critical** |
| F3 | Partial refund converts amount with a hardcoded 2-decimal assumption (100× over-refund on JPY-class currencies) | — (correctness) | **Critical** |
| F4 | Webhook guard chain fails open silently (`?->check()`) | — (security) | **High** |
| F5 | Return-session fraud scoring is wired, tested, and never called | DIP / dead abstraction | **High** |
| F6 | `confirmPayment()` returns hardcoded success | **LSP** | **High** |
| F7 | `'EUR'` hardcoded in 4 fallbacks, against the codebase's own written rule | DRY | **High** |
| F8 | Idempotency protection is silently optional; no native Stripe key; no stale-lock reaper | DIP | **High** |
| F9 | Webhook amount parser returns `0.0` for "unknown" | — (sentinel collision) | Medium |
| F10 | `stripeCustomerExists()` treats any error as "does not exist" | — | Medium |
| F11 | OXPAID / order date silently stamped with "now" | — (financial record) | Medium |
| F12 | Reconciliation audit log prints `NO_CONTRACT` when a contract was found | — (false audit) | Medium |
| F13 | Legacy webhook-secret fallback is mode-agnostic | — | Medium |
| F14 | `shopId` silently defaults to `1` | — (EE multishop) | Medium |
| F15 | Same invariant: one class throws, its sibling silently returns `false` | LSP / consistency | Medium |
| F16 | Event handlers silently `return;` on type mismatch — contract stalls with no trace | — (fail-silent FSM) | Medium |
| F17 | `StripeOrderController`: 776 lines, 35 methods, container service-location | **SRP / DIP** | Medium |
| F18 | Activation/deactivation swallows everything; `deactivatePaymentMethods()` is an empty promise | — | Low |
| F19 | `ViewConfig` hands the frontend an empty publishable key | — | Low |
| F20 | `isTestMode()` means two different things in two implementations | ISP | Low |

---

## Critical

### F1 — Radar fraud check fails open, and records a *clean* score it never obtained

`src/Stripe/Service/StripeRadarFraudCheckService.php:75-79`

```php
} catch (\Throwable $e) {
    // On error, pass by default to not block legitimate transactions
    // Log the error for debugging
    return FraudCheckResponse::success(0.0);
}
```

Three separate problems stacked in five lines:

1. **The comment is false.** There is no logger in this class — not injected, not
   in `services.yaml:1283-1288` (only `$adapterFactory` and `$threshold`). `$e`
   is captured and discarded. Nothing is logged, ever. A reader auditing the
   fraud path will believe there is a trail; there is none.
2. **The fallback lies about the outcome.** `success(0.0)` does not mean
   "unknown" — `FraudCheckResponse` documents `score` as "0.0-1.0 where 1.0 is
   highest risk", so `0.0` is *maximally clean*. `FraudCheckHandler:80-87` then
   writes into the contract's fulfilled condition:
   `['passed' => true, 'score' => 0.0]`. The contract now carries a permanent,
   auditable claim that Stripe Radar cleared this order with a perfect score,
   for an order Radar never saw.
3. **The correct constructor already exists and is unused.**
   `FraudCheckResponse::error()` (payment-base
   `src/Adapter/Response/FraudCheckResponse.php:72-81`) sets `score: 1.0` with
   the comment *"Highest risk when check fails"* and `successful: false`. A
   repo-wide grep for `FraudCheckResponse::error` returns **zero** hits. The
   fail-closed path was designed, built, and then not wired.

**Edge case that breaks it:** any Stripe API outage, a revoked/rotated secret
key, a DNS or TLS failure, or an expired-key 401 — for the whole duration of the
incident every order passes fraud screening and each contract is stamped
`passed: true, score: 0.0`. There is no log line and no metric to notice it, and
afterwards nothing distinguishes a genuinely-clean order from an unscreened one.

**Fix:** inject the logger that the comment already promises; return
`FraudCheckResponse::error($e->getMessage())` on `Throwable`; decide the
fail-open/fail-closed policy *in `FraudCheckHandler`* (an explicit
`$failOpenOnError` flag), not by fabricating a score in the adapter. Keep
"no PaymentIntent" (line 49-52) distinct from "check failed" — those are
different facts and currently both render as `success(0.0)`.

---

### F2 — The same refund operation has two contradictory idempotency semantics

`src/Stripe/Adapter/Helper/RefundHelper.php:150-167` vs `:172-203`

Both methods guard "a refund of this payment", and they disagree:

| | `refundWithIdempotency` (via `IdempotentExecutor`) | `refundByChargeWithIdempotency` |
|---|---|---|
| Key | `'refund:' . $providerPaymentId` | `'refund_charge:' . $chargeId` |
| `PROCESSING` seen | throws | throws |
| `COMPLETED` seen | **returns the stored first response** (`IdempotentExecutor:66-69`) | **not checked — re-executes against Stripe** |
| Stores result | yes | no (`setStatus(COMPLETED)` only, `setResult` never called) |

Neither key contains the refund amount or a per-request id, but
`RefundPaymentRequest` explicitly supports *"Partial refund (amount < captured
amount)"* (payment-base `src/Adapter/Request/RefundPaymentRequest.php:18-19`).
So:

- **Path A (`StripeAdapter::refundPayment`, the payment-base interface method
  other modules call):** first partial refund of €10 succeeds and is stored
  under `refund:pi_X`. A *second, legitimate* €10 partial refund within the 24 h
  TTL hits `COMPLETED`, and `IdempotentExecutor` returns the **first** refund's
  deserialized response — same `refundId`, same €10, `successful: true`. Stripe
  is never called. The admin sees a successful refund; the customer gets
  nothing. A false success on a financial operation.
- **Path B (live admin path: `StripeRefundRequestHandler:133,140` →
  `RefundService::processRefund*` → `createRefundByCharge` →
  `refundByChargeWithIdempotency`):** the opposite — because `COMPLETED` is never
  checked, a retried request (double-click, browser retry, queue redelivery)
  performs a **second real refund**. The idempotency record is written on every
  call but only ever functions as a concurrency lock.

The asymmetry is the finding: two sibling private methods, identical intent,
opposite behaviour, and the record shape differs (one stores a payload, one
stores `NULL`) — so the two are not interchangeable, and neither is correct for
partial refunds.

**Fix:** make the key identify the *request*, not the payment — hash
`(chargeId|paymentIntentId, amountInCents, reason)`, or thread a caller-supplied
refund reference through `RefundPaymentRequest`. Then route both public methods
through the single `IdempotentExecutor` so there is one code path and one
semantic. If the intent is only a concurrency lock, say so and drop the
`COMPLETED` short-circuit from `IdempotentExecutor` for refunds.

---

### F3 — Partial refunds convert money with an admitted-wrong currency assumption

`src/Stripe/Service/RefundService.php:66-72`

```php
// currency is sourced from the charge returned by createRefundByCharge;
// ... pass '' to default to 2 decimals.
// Sprint 114.7: full currency threading would require adding currency to RefundService
// callers; safe for EUR-primary shops.
$amountInCents = $amount !== null ? AmountConverter::toMinorUnits($amount, '') : null;
```

`MinorUnitConverter::decimalsFor('')` returns 2, so the amount is always
multiplied by 100. For a zero-decimal currency (JPY, KRW, VND, CLP, XOF …) the
minor unit **is** the major unit.

**Edge case that breaks it:** a ¥1,000 partial refund on a ¥1,000,000 charge is
sent to Stripe as `amount=100000` → **¥100,000 refunded, 100× the intent**, and
it stays inside the charge total so Stripe accepts it without complaint. Nothing
in the module flags it; the audit row records the requested ¥1,000. The same
multiplication applies to three-decimal currencies (BHD, KWD) in the other
direction. The comment's mitigation — *"safe for EUR-primary shops"* — is the
whole safety argument, and it is a deployment assumption, not a code guarantee.

This also contradicts the codebase's own doctrine twice over: Sprint 114.7
exists specifically to make conversions currency-aware
(`AmountConverter` docblock), and `MinorUnitConverter:32-33` states
*"Unknown or empty currency defaults to 2 decimals (safe, shop-agnostic
fallback; do NOT hardcode 'EUR')"* — passing `''` here is exactly the hardcode
that rule forbids, just spelled differently.

**Fix:** `RefundService` already fetches the charge in
`getChargeIdFromPaymentIntent()` — retrieve `charge->currency` there and thread
it into `toMinorUnits()`, or (cheaper) accept the currency as a parameter from
`StripeRefundRequestHandler`, which has the order. Until then the guard should
be loud: throw when `decimalsFor($currency) !== 2` and the currency is unknown,
rather than silently assuming.

---

## High

### F4 — The webhook guard chain fails open, silently, on a public endpoint

`src/Stripe/Controller/Webhook/WebhookController.php:62-69` and `:94`

```php
} catch (Exception $e) {
    Registry::getLogger()->warning('Webhook guard chain unavailable — processing without rate limiting/IP checks', ...);
}
// ...
$guardResult = $this->getGuard()?->check($payload, $signature, $remoteIp);
```

If the container cannot build `WebhookRequestGuardInterface`, `$this->guard`
stays `null` and the null-safe operator on line 94 skips the entire chain —
HTTPS enforcement, IP allowlist, payload-size cap and rate limiting
(`WebhookHttpsGuard`, `WebhookIpAllowlistGuard`, `WebhookPayloadSizeGuard`,
`WebhookRateLimitGuard`). Signature verification still runs later
(`StripeWebhookProcessor:63-84`), so this is not an authentication bypass — but
every *pre-authentication* protection disappears on a single DI error, and the
only trace is one `warning` emitted at `init()`, not per request.

**Edge case that breaks it:** a services.yaml regression, a failed migration for
the rate-limit table, or a DB hiccup during `init()` downgrades the endpoint to
unprotected — unbounded body reads and unlimited unsigned-request throughput,
each of which still costs a signature computation and a log write. The shop
keeps answering 200/400 and nothing escalates.

The same `?->` pattern hides the audit log: `$this->webhookLogger?->logReceived(...)`
(line 99) and inside `sendErrorResponse()` (line 219) — if that service failed
to build, webhook processing continues with **no** `oe_payments_webhook_log`
entries at all.

**Fix:** a security control that cannot be constructed should fail the request,
not the control. Either let the container error propagate (a misconfigured
module should refuse the endpoint), or keep a hardcoded conservative
`NullObject` guard that enforces payload size and HTTPS without DB access, and
return 503 when the real chain is missing.

### F5 — The return-session fraud scoring is dead code that looks like a live control

`src/Stripe/Service/ReturnSessionSecurityService.php` (272 lines)

The class computes an IP / timing / user-agent risk score against a threshold of
50, is bound in `services.yaml:365-372` as
`ReturnSecurityValidatorInterface`, and has its own unit suite
(`tests/Unit/Stripe/Service/ReturnSessionSecurityServiceTest.php`). A tree-wide
grep for `ReturnSecurityValidatorInterface` and `validateReturn` across
`extensions/` and `modules/` finds **no production consumer** — only the
interface, this implementation, and its tests. (The `validateReturn` hits in
`CheckoutReturnService` / `StripeReturnResolver` are an unrelated method with a
different signature: token + session validation, no scoring.)

Corroborating evidence that the branch was never connected:
`CheckoutReturnResult::securityFailure()` — the only producer of error code
`security_check_failed`, `src/Stripe/Service/Result/CheckoutReturnResult.php:96-110`
— has zero callers. The failure path of this control is unreachable.

**Why it is a *misleading* fallback rather than harmless dead code:** the module
presents a hijack/session-transfer defence that does not run, and its green unit
tests make the gap invisible in CI. Anyone reviewing "do we validate the user
who comes back from Stripe?" will find a scored validator, tests, and DI
wiring, and conclude yes.

Two smaller problems inside it, for whenever it is wired up:

- `validateTiming():159` — `time() - (is_numeric($ts) ? (int) $ts : 0)`. A
  malformed `created_timestamp` yields `elapsed ≈ 1.7e9`, which trips
  `very_late_return`. The score reacts correctly-ish, but the warning names the
  wrong cause; a corrupt-metadata bug will be read as a slow customer.
- The penalty table can fail a legitimate customer: missing `user_ip` metadata
  (-20) plus a >1 h 3DS/bank-app detour (-35) lands on 45, below the threshold
  of 50 — a mobile customer who authenticated slowly. Before wiring this in,
  confirm what the caller does on failure: if it refuses order creation *after*
  Stripe has authorized, the customer is charged with no order.

**Fix:** either wire it into the return flow (behind a config flag, with the
failure path leading to review rather than silent rejection) and use
`securityFailure()`, or delete class + result factory + tests. Both are fine;
the current middle state is the only bad option.

### F6 — `confirmPayment()` returns success without confirming anything (LSP)

`src/Stripe/PaymentHandler/StripePaymentHandler.php:136-142`

```php
public function confirmPayment(string $transactionId): PaymentHandlerResult
{
    return PaymentHandlerResult::success(
        contractId: $transactionId,
        metadata: ['note' => 'Stripe confirms via redirect return + webhooks']
    );
}
```

`PaymentHandlerInterface:41-47` specifies *"Confirm payment with provider /
@return PaymentHandlerResult Result with confirmation status"*. This
implementation contacts no provider, inspects no contract, and cannot fail — it
is a textbook Liskov violation: a caller written against the interface gets an
answer that is structurally indistinguishable from a real confirmation.

No caller exists today, which is the only reason this is not already a live
incident. It is high severity because of who will call it next: this module ships
alongside `opalsubscription` (recurring charges) and `opalreturns`, both of which
consume payment-base interfaces. The first off-session/MIT flow that asks
"is this payment confirmed?" will be told "yes" for free.

**Fix:** implement it against the PaymentIntent status (the module already has
`StripePaymentCaptureStatusQuery` doing exactly this kind of lookup properly),
or throw `LogicException('Stripe confirms out-of-band; use …')` so misuse is
loud. Do not return `success` for work not done.

### F7 — `'EUR'` hardcoded in four fallbacks, against the rule the codebase wrote down

- `src/Stripe/Adapter/OxidShopAdapter.php:86` — `return $currency->name ?? 'EUR';`
- `src/Stripe/Adapter/OxidShopOrderService.php:135` — same
- `src/Stripe/Controller/PaymentController.php:125` — same
- `src/Stripe/Service/Return/StripeReturnResolver.php:80,86` — `(string) ($result->getCurrency() ?? 'EUR')`

`MinorUnitConverter:32-33` states the policy explicitly: *"Unknown or empty
currency defaults to 2 decimals (safe, shop-agnostic fallback; **do NOT
hardcode 'EUR'**)"*. Four sites do exactly that, and it is duplicated logic
(DRY) on top of being wrong.

**Edge case that breaks it:** a CHF- or USD-only shop where
`getActShopCurrencyObject()` returns an object without `name` (misconfigured
`aCurrencies`, a currency deleted while a session is live). The PaymentIntent /
Checkout Session is then created with `currency: eur` carrying the CHF numeric
amount — CHF 100.00 charged as €100.00. Stripe accepts it; the shop's order
total and the PSP's currency disagree, and the discrepancy surfaces only in
reconciliation.

The two `StripeReturnResolver` sites are currently unreachable
(`CheckoutReturnResult::success()` requires a non-null `string $currency`), which
makes them worse than useless: they document a fallback for an impossible state
and would mask a real regression if the DTO ever loosened.

**Fix:** one helper — resolve the shop currency once, throw if it cannot be
determined. A payment must never guess its own currency. Delete the two
unreachable `?? 'EUR'` in the resolver so PHPStan keeps the invariant honest.

### F8 — Idempotency protection is optional, non-native, and can self-deadlock for 24 h

Three linked issues.

1. **Silently optional.** `PaymentIntentHelper:42-49` and `RefundHelper:36-43`
   accept `?IdempotencyRepositoryInterface = null`, and
   `PaymentIntentHelper::capturePaymentIntent():84-88` /
   `RefundHelper::createRefundByCharge():64-69` branch straight past all
   protection when it is `null` — no warning, no log. Production wiring does
   pass it (`services.yaml:236,244`), so this is latent; but the "protected" and
   "unprotected" adapters are the same class with the same API, so nothing at a
   call site or in a log tells you which one you have. Optional-null-dependency
   is the DIP smell here: the collaborator is not optional to the *behaviour*,
   only to the constructor.
2. **No native Stripe idempotency key.** A grep for `idempotency_key` /
   `Idempotency-Key` across `stripe/src` and `payment-base/src` returns nothing.
   The DB-backed executor only deduplicates *local invocations*; it cannot help
   with the case Stripe's header exists for — request reaches Stripe, Stripe
   performs the operation, the response is lost to a timeout. `IdempotentExecutor:87-91`
   then marks the record `FAILED`, and `IdempotencyHelper::reuseOrCreate` lets
   the next attempt re-execute. For `capture` Stripe rejects the second attempt;
   for **refund** it does not — a lost response on a partial refund produces a
   second real refund.
3. **No reaper for stuck locks.** `IdempotentExecutor:70-74` throws
   `'operation already in progress'` for any `PROCESSING` record. If the PHP
   process dies mid-capture (`max_execution_time`, OOM, deploy restart), that
   record stays `PROCESSING` for the full TTL — `DEFAULT_TTL_SECONDS = 86400`.
   Capture/refund for that payment is then impossible **for 24 hours**, with an
   error message that says a concurrent operation is running when none is.
   `deleteExpired()` exists (`DoctrineIdempotencyRepository:69`) but is called
   only from a test — no cron, no command, nothing in production.

**Fix:** pass Stripe's `idempotency_key` on every mutating call (that is the
correct layer for it); make the repository a required constructor argument; add
a stale-`PROCESSING` timeout that is much shorter than the result TTL (a lock is
not a cache — 60-120 s), and schedule `deleteExpired()` from the existing
console-command surface.

---

## Medium

### F9 — `0.0` used as "unknown" in the webhook amount parser

`src/Stripe/Webhook/StripeWebhookEventParser.php:83-95`

```php
$amount = $object[$field] ?? 0;
if (!is_int($amount)) {
    return 0.0;
}
```

The return type is `float`, so "field absent", "field malformed" and "amount
genuinely zero" are one value. A Stripe API-version change that renames a field,
or a JSON body where the amount decodes as string/float, silently yields 0.00.
Downstream: the captured/refunded amount written to the contract becomes 0.00,
`CapturableAmount::remaining($authorized, 0.0)` reports the **full** amount as
still capturable, and the audit row in
`WebhookContractFulfillmentHandler:53` records a 0.00 capture for a real one.

**Fix:** return `?float` (or throw) and let the caller decide; treat "no amount
in an amount-bearing event" as a processing failure so Stripe retries.

### F10 — "Customer does not exist" inferred from any error

`src/Stripe/Service/StripeCustomerService.php:88-96`

`stripeCustomerExists()` catches `\Throwable` and returns `false`. Only
`InvalidRequestException` with `resource_missing` actually means "gone"; a
timeout, a 429, or a 500 means "unknown". The caller (`:41-55`) then logs
`'Stale Stripe Customer ID, creating new one'` — a claim it has not
established — creates a second Stripe Customer, and **overwrites** the stored
mapping (`$existing->setPaymentCustomerId(...)`).

**Edge case that breaks it:** one transient API blip during checkout permanently
repoints the user's `oe_payments_customer` row at a new Customer. Saved payment
methods, mandates and Radar history attached to the old Customer become
unreachable for that user, and Stripe accumulates duplicate Customers for the
same person. The operation is irreversible from the shop side.

**Fix:** catch `InvalidRequestException` and check the error code for
`resource_missing` → `false`; rethrow everything else so checkout fails loudly
and retryably rather than mutating durable state on a guess.

### F11 — Financial timestamps silently replaced with "now"

- `src/Stripe/Service/OxpaidReconciliationService.php:176-184` —
  `$timestamp = $capturedAt?->format('Y-m-d H:i:s') ?? date('Y-m-d H:i:s');`
  writes `oxorder.OXPAID`.
- `src/Stripe/Adapter/OxidShopOrderService.php:346-359` —
  `getOrderCreationDate()` returns `new DateTimeImmutable()` when
  `oxorderdate` fails to parse (the `catch` body is a bare comment).

`OXPAID` is the payment date used for accounting exports and dunning. When
Stripe returns no `capturedAt`, the reconciliation cron stamps *its own run
time*. A cron that catches up after a weekend or a webhook outage moves payment
dates by days — into the wrong VAT/accounting period — and nothing marks the row
as estimated.

**Fix:** if the PSP cannot say when money moved, do not invent it. Leave OXPAID
untouched and report the order as needing manual review (the
`ReconciliationResult` vocabulary already has `skipped`), or persist the
fallback with an explicit flag in the reconciliation log.

### F12 — The reconciliation audit log states things that are not true

`src/Stripe/Service/OxpaidReconciliationService.php:190-213`

`$contractFlag = $contractUpdated ? 'CONTRACT_FULFILLED' : 'NO_CONTRACT';`

On the SUCCESS path a contract is guaranteed to exist — the method returns early
at `:85-94` when it does not. So when `fulfill()` returns `false` (already
fulfilled, or fulfillment declined), the audit line reads `NO_CONTRACT` for an
order whose contract was found and processed. The one artefact you would consult
to explain a payment discrepancy actively misdirects.

Two smaller issues in the same class: `reconcileAll()` returns
`success: true, action: 'dry_run'` (`:156-164`) for work deliberately not done —
any caller counting successes over-reports; and `logReconciliation()` returns
silently when `$fileLogger === null` (`:197-199`), so the financial audit trail
is an optional constructor argument.

**Fix:** three states, not two (`CONTRACT_FULFILLED` / `CONTRACT_UNCHANGED` /
`NO_CONTRACT`); `success: false` or a distinct field for dry runs; require the
logger.

### F13 — The legacy webhook-secret fallback ignores test/live mode

`src/Stripe/Service/ModuleConfigurationService.php:133-149`

`getWebhookSecret()` prefers the per-mode secret and falls back to the legacy
mode-agnostic module setting. The per-mode key is chosen by `isTestMode()`; the
legacy fallback is not.

**Edge case that breaks it:** a shop that pasted a **test** signing secret into
the legacy setting before auto-registration existed switches to live mode. The
live per-mode secret is empty, so the legacy test secret is used to verify live
webhooks. Every signature check fails, every webhook gets a 400, Stripe retries
and gives up — and because verification failure is (correctly) fail-closed, the
symptom is not an error in the shop but **orders that silently never become
paid** and contracts that never leave PENDING. The fallback that looks like
backwards-compatibility resilience is what turns a missing-config error into a
silent payment-status outage.

**Fix:** scope the legacy fallback to the mode it was stored under, or drop it
after a migration; and surface "webhook secret missing for current mode" as a
module health warning in the admin panel instead of letting it present as
signature failures.

### F14 — `shopId` silently becomes shop 1

`src/Stripe/PaymentHandler/StripePaymentHandler.php:259` and
`src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php:149`

```php
$shopIdInt = is_numeric($shopId) ? (int) $shopId : 1;
```

In an EE multishop install, a non-numeric or unavailable shop id attributes the
checkout session — and the metadata that later resolves the contract and order —
to shop 1. Duplicated in two places, so a fix has to find both. Given the recent
"fetch the private service for the current shop id" work in the sibling module,
this default is exactly the class of bug that was just chased elsewhere.

**Fix:** shop id is not optional context; throw if it cannot be resolved, and
resolve it in one place.

### F15 — One invariant, two opposite reactions

Same check, two classes:

- `src/Stripe/PaymentHandler/StripePaymentHandler.php:224-231` — not a concrete
  `PaymentContract` → `throw new LogicException(...)` naming the actual class.
  **Correct.**
- `src/Stripe/Service/RetryCleanupService.php:83-87` — not a concrete
  `PaymentContract` → `return false`.

The `return false` is indistinguishable from "nothing to clean", so the caller
(`cleanupStaleContracts()`, invoked after **every** webhook via
`WebhookController:192-208`) simply does not count it. A contract that can never
satisfy the guard is re-fetched by `findStaleNotFinished()` on every webhook
forever and never cleaned or reported.

Both classes also depend on the concrete `PaymentContract` rather than
`PaymentContractInterface` in order to reach state-machine transitions that
payment-base deliberately keeps off the interface. `StripePaymentHandler:222-224`
documents that trade-off honestly, but the underlying DIP break means every
consumer must either narrow-and-throw or narrow-and-skip. The real fix belongs in
payment-base: expose the transitions the collaborators need (a narrow
`ContractStateTransitionsInterface`), so no one has to guess.

### F16 — Handlers no-op silently when the event type does not match

Pattern in ~10 handlers, e.g. `FraudCheckHandler:51-53`,
`StripeCaptureRequestHandler:62`, `StripeCheckoutSessionHandler:61`,
`StripePaymentStatusHandler:52`:

```php
if (!$event instanceof PaymentAuthorizedEvent) {
    return;
}
```

As a defensive guard this is reasonable; as the *only* behaviour it is a
fail-silent state machine. These handlers advance a contract through
`DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED`. If a tag/priority
regression in `services.yaml` routes the wrong event to a handler, the condition
is never fulfilled, the contract stalls, no order is created, and **not one log
line is written** — the customer's money is authorized at Stripe and the shop
has no record that anything went wrong. `FraudCheckHandler:56-60` has the same
shape for a missing contract in the context.

**Fix:** log at `warning` before returning (the dispatcher knows the expected
class via `getHandledEventClass()`, so a mismatch is a wiring bug, not a normal
condition), and add a stalled-contract watchdog — `RetryCleanupService` already
sweeps stale `NOT_FINISHED`; `PENDING` contracts past a threshold need the same.

### F17 — `StripeOrderController`: SRP and DIP

`src/Stripe/Controller/StripeOrderController.php` — 776 lines, 35 methods, in
one class: HTTP routing, JSON emission, response headers, CSRF challenge
regeneration, AGB consent, user-data validation, basket buyability, event-context
assembly, contract loading, stale-checkout cleanup, error-message formatting and
translation. Change any one of those and you edit this file.

Dependencies are not injected — the `ServiceContainer` trait pulls them from the
container inside `getRequestHelper()`, `getUserDataValidator()`,
`getBasketBuyabilityValidator()`, `getLanguageTranslator()` and friends. That is
service location, not DI: the class's real dependency list is invisible at the
constructor, and the tests can only reach it by subclassing and overriding
`protected` seams (the "R-1.5 seam" comments in `WebhookController:145-147` show
the same pattern). It is an understandable concession to OXID's
`_parent` chain — controllers are instantiated by the framework — but the
extraction is available: the validation/ formatting/ cleanup groups are already
separate collaborators and only need constructor-or-setter wiring, leaving the
controller as routing plus delegation. `Controller/Admin/ModuleConfiguration.php`
(506 lines) has the same shape.

---

## Low

### F18 — Activation/deactivation hides its own failures

`src/Stripe/Core/Events.php`

- `deletePaymentMethod():154-167` — `catch (Exception) { // do nothing }`. A
  failed cleanup of a removed payment method (`stripepaypal`) is invisible;
  the method stays in `oxpayments` and keeps appearing in the admin.
- `deactivatePaymentMethods():173-176` — an **empty method body** under a name
  that promises action, with the comment *"Payment methods remain in database but
  can be deactivated if needed"*. `onDeactivate()` calls it, so reading the call
  site suggests deactivation happens. It does not: Stripe payment methods stay
  active after the module is switched off.
- `onDeactivate():84-90` — the whole body is wrapped in
  `if (Registry::getConfig()->isAdmin())`. Deactivating via
  `bin/oe-console oe:module:deactivate` (the documented CLI path in CLAUDE.md)
  therefore skips the file-cache reset, leaving stale templates/config in place.

### F19 — Empty publishable key handed to the frontend

`src/Stripe/Core/ViewConfig.php:29-40,180-187`

`getStripeConfig()` returns `null` when the container lookup throws, and
`getStripePublishableKey()` maps that to `''`. The template then boots Stripe.js
with an empty key — a client-side failure with no server-side error, which
presents to the customer as a checkout that simply does not work. The comment
justifies the null with *"module being deactivated"*, but the catch is on
`Throwable` and applies equally to a genuine misconfiguration at runtime.
Minor bonus: `$this->stripeConfig` is never memoised on the failure path, so
every call retries the failing lookup.

`isStripeIframeCheckout():191-199` catching `Throwable → false` is the acceptable
version of this: falling back to redirect checkout is a working flow, not a
broken one.

### F20 — `isTestMode()` means two different things

- `src/Stripe/Service/Factory/StripeAdapterFactory.php:116-119` → Stripe mode
  from module configuration. Correct.
- `src/Stripe/Adapter/OxidShopAdapter.php:89-92` →
  `(bool) Registry::getConfig()->getConfigParam('blDebugMode')`.

Shop debug mode is not Stripe test mode: a live shop with debug enabled reports
"test", a test shop with debug off reports "live". The `OxidShopAdapter` version
currently has no consumers, which is the only thing keeping it harmless — a trap
for the next person who wires a `ShopAdapter` into anything mode-sensitive.

Also in the ISP bucket: `FraudCheckResponse` exposes every field twice (public
readonly properties *and* getters, payment-base
`src/Adapter/Response/FraudCheckResponse.php:32-121`) and consumers already mix
the two styles (`FraudCheckHandler:85` uses `$result->score`, line 78 uses
`isSuccessful()`). Two public APIs for one DTO, both to maintain.
`OxidShopAdapter::getShopName()` falling back to the literal `'OXID eShop'`
(`:76-81`) reaches Stripe as a statement descriptor / session branding —
cosmetic, but it is the merchant's identity being guessed.

---

## Fallbacks the module gets right

Worth naming, because they are the pattern the findings above should be fixed
into — the module clearly knows how to do this:

- **`CheckoutSessionService::buildLineItems():152-171`** — builds itemised line
  items, then *verifies* their sum against OXID's authoritative
  `totalGross` and falls back to a single "Order Total" line when they differ
  (STRP-157 rounding residue). This is a fallback that checks its own premise, so
  the defensive coercions in `itemQuantity()` / `itemUnitPrice()` (`:245,235`)
  cannot mischarge — the guard catches them. **Verified, not assumed.**
- **`StripePaymentCaptureStatusQuery::isPaymentCaptured():44-75`** — returns
  `?bool` so "unknown" is representable, logs the PSP failure with contract and
  provider ids, and states the degraded-mode contract in its class docblock.
  This is precisely what F1 and F9 should look like.
- **`ModuleConfigurationService::getMode():75-85`** — defaults to `test`, never
  `live`. Fails safe in the direction that cannot move real money.
- **`MinorUnitConverter`** — case-insensitive currency matching, `round()` before
  `(int)` to avoid IEEE-754 drift, explicit 2-decimal default with the reasoning
  written down. The bugs in F3/F7 are all *bypasses* of this class, not defects
  in it.
- **`StripePaymentHandler:224-231`** — narrows to the concrete contract and
  throws `LogicException` with the offending class name when the invariant fails.

---

## Suggested order of work

1. **F3** (100× over-refund) and **F2** (refund idempotency) — real money, live
   code paths, small diffs. Thread the currency; make the idempotency key
   identify the request.
2. **F1** — use the `FraudCheckResponse::error()` that already exists; inject the
   logger the comment promises.
3. **F4**, **F8** — a security control and a duplicate-charge guard that both
   degrade invisibly; add the native Stripe idempotency key while in there.
4. **F5** — decide: wire it or delete it. Do not leave a tested, injected,
   never-called security control in place.
5. **F6**, **F7**, **F13**, **F14** — small, contained, each removes a
   fabricated value.
6. **F9-F12**, **F15**, **F16** — the honesty pass: make "unknown" representable,
   make silent skips logged, make audit strings match reality.
7. **F17-F20** — structural and cosmetic; schedule with the next refactor of the
   controller layer.

**Cross-cutting rule worth adopting:** a fallback may substitute a value only
when it can *verify* the substitution is correct (`buildLineItems`) or when it
is provably the safe direction (`getMode() → test`). Everywhere else — money,
currency, shop id, fraud verdicts, payment dates — the honest answer is an
exception or an explicit "unknown" the type system can carry. Every Critical and
High finding in this report is the same mistake: an error path that returns a
value indistinguishable from a real one.
