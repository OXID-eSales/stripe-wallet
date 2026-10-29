# OPC-175 · S2d — One contract continues; the old payment page is closed — done 2026-10-29

**Commits (not pushed):** payment-base `002611b`, stripe `d783116`, mollie-payment `233406b`, paypal `c15bb21`.

## What changed
- `PaymentContract::reopenForNewAttempt(AttemptClosure)` — only from `not_finished` / `pending`, never for a paid attempt. Records the replaced attempt (provider, provider order id, payment method, closure, time) in `ReplacedAttempts` (contract metadata `replaced_attempts`); clears provider, provider order id, redirect URL and `payment_method_id`; conditions start again; state `draft`; the order id stays. `transitionToNotFinished()` keeps an order a contract already has.
- `AttemptClosure` enum (paid / closed / open) and `ReplacedAttempts` reader live in `Contract` (domain types).
- `PaymentAttemptCloserInterface` + `PaymentAttemptClosers` (tag `oe.payment.attempt_closer`). No closer for the provider → `LogicException` (wiring error, no "nothing to close").
- Stripe `StripeAttemptCloser`: paid → Paid; `open` session → `expireCheckoutSession()` (new adapter call) → Closed; complete / expired → Closed; Stripe error → reaches the caller. DTO `StripeCheckoutSessionDto::$status` mapped.
- Mollie `MollieAttemptCloser`: paid / authorized → Paid; open / pending + `isCancelable` → `cancelPayment()` → Closed; open, not cancelable → Open; canceled / expired / failed → Closed. DTO `MolliePaymentDto::$isCancelable` mapped.
- PayPal `PayPalAttemptCloser`: Closed (money moves only on our capture; the replaced PayPal order matches no contract).
- `PaymentContract::failCondition()` removed — no caller in any module; keeps the entity inside the PHPMD limits (public count < 50, complexity ≤ 120).

## Tests (red → green)
- payment-base: `PaymentContractReopenTest` 7, `PaymentAttemptClosersTest` 2; unit 1959.
- Stripe: `StripeAttemptCloserTest` 6, `StripeObjectMapperTest` +1.
- Mollie: `MollieAttemptCloserTest` 6; unit 962.
- PayPal: `PayPalAttemptCloserTest` 2; unit 846.
- Container: `PaymentAttemptClosers` holds the three closers (paypal, stripe, mollie).

## Gates
- payment-base and Mollie `pre-commit-check.sh`: all checks passed; Mollie CI-form PHPCS on `src/` clean. Stripe and PayPal: PHPCS + PHPStan clean on the changed files.
