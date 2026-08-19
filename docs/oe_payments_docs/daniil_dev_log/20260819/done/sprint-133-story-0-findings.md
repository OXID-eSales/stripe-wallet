# Sprint 133 · Story 0 — Spike output: refund-request identity & currency threading

**Status:** done (2026-08-19) · read-only discovery, no production code touched.

## Q1 — What uniquely identifies one refund attempt?

**Decision: a caller-supplied idempotency key derived from the refund's
pre-state, computed by the orchestrating service (`RefundService`) and passed
down to the adapter/helper. Helpers no longer derive keys internally.**

Key inputs: `(operation, providerId, amountMinorUnits, reason, priorRefundedMinorUnits)`.

Rejected alternatives:

| Candidate | Why rejected |
|---|---|
| OXID `stoken` / session challenge (what the sprint draft suggested) | **Session-scoped, not submit-scoped.** Two legitimate €10 partial refunds in one admin session would share it and the second would be silently deduped — reproducing the exact defect F2 describes. |
| `(chargeId, amount, reason)` only | Two legitimate identical partial refunds collide. |
| Hidden per-render nonce in the refund form | Correct, but needs a template + controller + dispatcher change through four layers, and trusts a client-supplied token. |
| Random per-call value | Defeats idempotency entirely — every retry becomes a new refund. |

Why pre-state works: a **retry** of one submit sees the same
`priorRefundedMinorUnits` ⇒ same key ⇒ deduped. A **new, legitimate** partial
refund sees a larger prior-refunded total ⇒ different key ⇒ executes. It is
server-side truth rather than a client token, and concurrent double-clicks land
on the same key (deduped by the PROCESSING lock), which is the desired outcome.

Available per-caller today:

| Caller | Path | Pre-state source |
|---|---|---|
| `OrderActionDispatcher::dispatchRefund` (admin) | `processRefund(orderId, pi, …, amount)` | PI already retrieved by `getChargeIdFromPaymentIntent()` — `$piDto->charge->amountRefunded` |
| `StripeRefundRequestHandler` w/ explicit `chargeId` | `processRefundByCharge(chargeId, …)` | needs one `retrieveCharge()` — `RefundHelper::retrieveCharge()` already exists |
| webhook / api / mcp initiators | same two entry points | same; `initiator` already travels in the event context |

**Capture needs no change:** Stripe permits one capture per PaymentIntent, so
`capture:<paymentIntentId>` is already a correct request identity. Only the
native-key addition (S3) touches it — `PaymentIntentHelperIdempotencyTest`
fixtures stay valid.

## Q2 — Is the charge currency available without an extra API call?

**Yes. Zero extra calls for the main path.**

- `StripePaymentIntentDto` already exposes `public string $currency` (lowercase
  ISO-4217) plus `public ?StripeChargeDto $charge`.
- `StripeChargeDto` exposes `$currency`, `$amount`, `$amountCaptured`,
  `$amountRefunded`.
- `RefundService::getChargeIdFromPaymentIntent()` **already calls**
  `retrievePaymentIntent()` and throws away everything but the charge id. S1
  only has to stop discarding `$piDto->currency`.
- The `processRefundByCharge` path has no prior retrieve and needs one
  `retrieveCharge()` — acceptable on an admin action, and it is the same call
  the pre-state key needs, so one GET serves both.

## Q3 — Idempotency storage limits

`oe_payments_idempotency` (payment-base `migration/data/Version20251031140200.php:129-183`):

- `OXKEY VARCHAR(128)` + **UNIQUE** index `UK_KEY` → keys must stay ≤128 chars.
- `OXOPERATION VARCHAR(32)` → `refund` / `refund_charge` / `capture` all fit.
- `OXRESULT TEXT` nullable, `OXSTATUS VARCHAR(32)` nullable, `IDX_EXPIRES` on
  `OXEXPIRES` (so a prune by expiry is indexed — good for S3's command).

Chosen key shape stays well inside 128 and remains greppable:
`{operation}:{providerId}:{sha1(amountMinor|reason|priorRefundedMinor)[0..16]}`
→ ≈ 7 + 27 + 1 + 16 ≈ 51 chars.

## Q4 — Zero-decimal currency coverage

A JPY test **already exists** and is green:
`tests/Unit/Stripe/Service/RefundServiceDtoCharacterizationTest::testProcessRefundJpyPreservesZeroDecimalAmount`.

**It does not cover the bug.** It calls `processRefund('order_jpy', 'pi_jpy_test')`
with **no amount**, i.e. the full-refund path, where `$amountInCents` is `null`
and the defective `toMinorUnits($amount, '')` line is never reached. It only
characterises response mapping (`StripeRefundDto` → major units), which is
correct. So the suite currently *looks* JPY-safe while the partial-refund path is
100× wrong — worth noting as a coverage-shape lesson, not just a bug.

No integration fixture uses a zero-decimal currency; none is needed, since
`AmountConverter`/`MinorUnitConverter` are pure and unit-testable.

## Consequences for the stories

1. **S1** — read `$piDto->currency`; no new API call; add the *partial*-refund
   JPY/BHD tests the existing characterization test omits.
2. **S2** — introduce `IdempotencyKeyFactory`; move key derivation up into
   `RefundService`; helpers accept the key as a parameter. This also removes the
   duplicated PROCESSING/COMPLETED/FAILED flow, since both helper paths can then
   share `IdempotentExecutor`.
3. **S3** — reuse the same key string as Stripe's native `idempotency_key`; add
   a lock timeout distinct from the 24 h result TTL; prune via `OXEXPIRES`.
