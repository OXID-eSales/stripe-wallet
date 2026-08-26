# One checkout, five Stripe sessions — found and fixed

**Date:** 2026-08-26
**Module:** `stripe` (branch `b-7.4.x`)
**Follows:** [report 01](01-checkout-return-and-container-fixes.md) §7, which left this open

---

## 1. What was happening

One walkthrough — log in, add a product, payment step, order page — created
**five Stripe Checkout Sessions, five contracts and five early orders**. Four of
them were dead weight, and they are what made a paid return refusable in the
first place (report 01 §3).

Two mechanisms were undoing each other:

| Who | What it did | Why |
|---|---|---|
| `StripePaymentHandler::processPayment()` | prepared a **new** contract + early order + Stripe session on every call | OPC's checkout API calls it each time the customer moves through the accordion |
| `RetryCleanupService` | **cancelled** whatever was in flight whenever a checkout page rendered | "the customer came back, so the previous attempt is stale" (STRP-105) |

Each was reasonable alone. Together: OPC prepares a session → the payment step
renders and cancels it → OPC prepares another → the order page renders and
cancels that → the order page's own eager mount creates a fifth.

Measured with a `debug_backtrace()` at the single point every session passes
through (`CheckoutSessionService::createSession`):

```
4 × /index.php?cl=OeCheckoutApi&fnc=processCheckout   → StripePaymentHandler::processPayment
1 × /index.php?cl=order&fnc=createCheckoutSession     → StripeOrderController
```

and the cancels:

```
/index.php?cl=payment  → PaymentController::cleanupStaleCheckoutAttempt
/index.php?cl=order    → StripeOrderController::cleanupStaleCheckoutOnRender
```

## 2. The fix

Both mechanisms now ask the same question through **`CheckoutInFlightGuard`**:
*is the checkout this shopper already has still the one to use?*

- the handler hands that session back instead of minting another
- the cleanup leaves it alone instead of cancelling it

The rule behind the answer is **`PendingCheckoutReuse`** — pure, static, and
tested for everything that must never be reused:

| Refused when | Because |
|---|---|
| the session is already paid | handing it out again would be catastrophic |
| the total or currency differs from the basket | Stripe would charge the old amount |
| the contract is not `pending` | it has been cancelled, committed or is still a draft |
| the contract belongs to another provider | not ours to reuse |
| the reference is not a `cs_` Checkout Session | a payment intent is not one |
| the amount is zero | the total could not be determined |

**"Cannot tell" is always null**, and both callers read that as *carry on as
before*: the handler prepares a fresh checkout, the cleanup cancels the old one.
Neither breaks because Stripe was briefly unreachable or the basket unreadable.

TDD: 18 unit tests written red first (9 for the rule, 9 for the guard), then the
implementation, then the walkthrough measurement.

## 3. Result

| | before | after |
|---|---|---|
| Stripe sessions per walkthrough | **5** | **2** |
| redundant OPC sessions | 3 | **0** |
| cleanup cancelling a live session | yes, twice | **no** |

The remaining two are **one per checkout UI**: OPC's accordion prepares one, and
the classic order page's eager mount prepares its own. Whichever the customer
does not use is waste — but collapsing them is a decision about *which UI owns
the session*, not another guard, so it is deliberately left alone here.

## 4. Verification

| Gate | Result |
|---|---|
| `composer phpcs` | clean |
| phpstan `--level=max` | no errors |
| phpmd (changed files, `--strict`) | clean |
| unit, per file (new + touched, incl. existing handler and cleanup tests) | 59 + 29 tests OK |
| E2E `stripe-eager-mount-single-session` | passes |
| E2E `single-active-payment` | passes |

Measured live on `daniil.oxiddev.de`. Reproducing it needs a **Stripe-only**
shop: with a second method active the payment step submits whichever is checked,
and with stale pending contracts from earlier runs the guard rightly refuses
(different basket totals) and the count is polluted. Mollie was deactivated for
the measurement and **reactivated afterwards**; both methods are active again.

## 5. Notes for next time

- The reproduction is state-sensitive. Cancel the test user's leftover pending
  contracts first, or the guard compares against an abandoned checkout for a
  different basket and the numbers lie.
- `findActiveByUserId()` already orders newest-first — the stale contract kept
  winning only because the fresh one had just been cancelled. That was the clue
  that two mechanisms were fighting.
- Both E2E specs now annotate instead of failing when the shop does not match
  their scenario (more than one payment method; a wallet-only embedded sheet).

## 6. Commits

| Commit | Subject |
|---|---|
| `745a193` | fix(checkout): one checkout, one Stripe session |
| `9235f8c` | chore(e2e): bump submodule — specs annotate instead of failing off-scenario |
| `aa6882d` | fix(checkout-return): don't let the ownership check break on an absent user (CI) |
