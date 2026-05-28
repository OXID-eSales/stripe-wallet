# Sprint 114.2 — Narrow `validateDeliveryAddress()` Stripe bypass + real test

**Module:** `extensions/stripe`
**Priority:** P0 (security — address-tamper validation bypass)
**Findings:** L1 (Liskov / latent bug), T2 (TDD — `markTestIncomplete` on shipped code)
**Mode:** single atomic commit, TDD-first. 1 production file, 1 test file rewritten.
**Depends on:** none.
**Engineering requirements:** [`_engineering_requirements.md`](./_engineering_requirements.md) — R-1…R-10 binding. Key here: **R-3** (override must not weaken the parent's security contract), **R-8** (gate on contract/return-flow state, not a blanket payment-id check), **R-1.5** (test the real override, no re-implementation).

## 1. Why

`src/Stripe/Model/Order.php:111-138` overrides OXID's
`validateDeliveryAddress()` and returns `0` ("address OK") for **every**
Stripe payment, unconditionally:

```php
if (strpos($paymentId, 'oe_payments_stripe_') === 0) {
    return 0;
}
return parent::validateDeliveryAddress($oUser);
```

The method's own docblock says this is for the **Stripe Checkout return
flow** (GET redirect, no form hash). But the implementation does not gate on
the return flow — it disables address-tamper detection for Stripe in *all*
contexts, weakening the parent's security contract (Liskov violation).

Compounding it, the test `tests/Unit/Stripe/Model/OrderAddressValidationTest.php:39`
is `markTestIncomplete(...)` claiming the fix is "to be implemented" (it
already is), and its sibling asserts only on local literals — so there is
**zero** coverage on a security-relevant bypass.

## 2. Goals

- **G1.** The bypass fires only in the legitimate Stripe Checkout return
  flow — i.e. when the return-flow session marker set by
  `StripeCheckoutReturnHandler` is present (the `sDelAddrMD5` restore the
  docblock describes), or equivalently when there is no form
  `sDeliveryAddressMD5` AND a Stripe return is in progress.
- **G2.** Outside the return flow, Stripe payments fall through to
  `parent::validateDeliveryAddress($oUser)` (normal tamper detection).
- **G3.** Remove the dead `$oBasket !== null` guard (OXID's `getBasket()`
  never returns null here; PHPStan flags it).
- **G4.** `OrderAddressValidationTest` exercises the real override via a
  seam-only testable subclass — no `markTestIncomplete`, no literal-only
  asserts. Covers: (a) return-flow → 0, (b) non-return Stripe + changed
  address → parent's non-zero, (c) non-Stripe → parent path.
- **G5.** `./bin/pre-commit-check.sh --full` green.

## 3. Decision: how to detect "return flow"

Confirm the exact marker before coding (read `StripeCheckoutReturnHandler`
and `ControllerRequestHelper::clearStripeSessionVariables()` for the session
keys). Candidate gate, in priority order:

1. Session var `sDelAddrMD5` was restored from contract metadata (the
   docblock's stated mechanism) **and** the request lacks `sDeliveryAddressMD5`.
2. A dedicated return-flow flag (e.g. `stripe_checkout_return` session var) —
   add one in `StripeCheckoutReturnHandler` if no precise marker exists today.

Prefer an explicit boolean flag set on return and cleared on completion over
inferring from absence of a request param.

## 4. TDD plan (RED first)

Testable subclass overriding only the framework seams (basket payment id,
session-marker read, request param read, and a spy `parentValidate()`),
exercising the **real** `validateDeliveryAddress()` body:

1. **`stripeReturnFlowSkipsAddressValidation`** — payment id `oe_payments_stripe_wallet`, return marker present → returns `0`, `parentValidate` NOT called.
2. **`stripeNonReturnFlowDelegatesToParent`** — Stripe payment, NO return marker → calls parent; assert the parent's result (e.g. `7`) is returned. **RED** today (current code returns 0).
3. **`nonStripePaymentAlwaysDelegatesToParent`** — payment id `oxidcashondel` → parent path.
4. Delete the `markTestIncomplete` test and the literal-only `testExpectedBehaviorForStripeFix`.

## 5. Implementation steps

1. Read `StripeCheckoutReturnHandler` + session-key helpers; pick the return-flow marker (Section 3).
2. Extract two protected seams on the model for testability:
   `protected function getBasketPaymentId(): string` and
   `protected function isStripeCheckoutReturn(): bool`.
3. Rewrite the guard: `if ($this->isStripePaymentId($paymentId) && $this->isStripeCheckoutReturn()) { return 0; }` then `return parent::validateDeliveryAddress($oUser);`.
4. Route the `oe_payments_stripe_` check through the shared prefix helper if 114.9 (D7) has landed; otherwise keep `strpos(...) === 0` and leave a `// TODO 114.9` note.
5. Remove the dead null check (G3).

## 6. Risks & rollback

- **Risk (high-value):** over-narrowing re-breaks the Cyrillic/multibyte
  Checkout-return case the original bypass was added for. The return-flow
  test (TDD #1) must use the *same* marker the real return handler sets —
  verify end-to-end with an E2E Checkout-return spec if available.
- **Risk:** `parent::validateDeliveryAddress()` virtual-parent call under
  PHPStan — keep the existing `@phpstan-ignore` only if already present for
  the virtual parent; do not add new suppressions for our own logic.
- **Rollback:** single commit; revert restores the (insecure) blanket bypass.

## 7. Definition of Done

- G1–G5 met; `OrderAddressValidationTest` rewritten with 3 behavioral tests, no skips.
- Manual/E2E confirmation that the legitimate Stripe Checkout return still completes (address step not falsely rejected).
- Completion report in `done/`; memory item #2 marked fixed.
