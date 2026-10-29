# OPC-175 S2g — Money on a replaced attempt goes back

Date: 2026-10-29. Branch: `b-7.4.x-coupon-reaplying-OPC-175`. Not pushed.

## Problem

After S2e the contract continues with a new attempt when the customer changes the payment method. The closer (S2d) closes the old payment page, but a provider cannot always close it (Mollie: not every payment is cancelable; Stripe: the customer can complete the page in the same second). Money can arrive on a replaced attempt. No contract names that provider id any more, so the webhook answered "contract not found" and the money stayed with the merchant without an order.

## Change

| Repo | Commit | Content |
|---|---|---|
| payment-base | `97db439` | `ReplacedAttemptFinderInterface` (implemented by `DoctrineContractRepository`: `JSON_SEARCH` on `$.replaced_attempts[*].providerOrderId`). `ReplacedAttempts::withLateMoney()`, `lateMoneyIn()`, `indexOf()`. |
| stripe | `90773c6` | `StripeLatePaymentRefunder`. `CheckoutSessionCompleted`, `PaymentIntentSucceeded`, `PaymentIntentAmountCapturableUpdated` handlers: an event of a replaced attempt (`contract_attempt` stamp) goes to the refunder. |
| mollie-payment | `e2fd66e` | `MollieLatePaymentRefunder`. `WebhookContractFulfillmentHandler` paid/authorized: before "contract not found", ask the refunder. |

Rules (one path, no fallbacks):

- Paid → full refund (Stripe reason `duplicate`; Mollie idempotency key `opc175-<payment id>`). Authorized → release the authorization.
- Other status (not money yet) → nothing recorded, the later event is handled.
- The outcome (`released`, `refunded:<id>`, Stripe `refund_failed:<message>`) is recorded once on the replaced attempt in `replaced_attempts`; a second event does nothing.
- Each giveback is logged as `ERROR` with contract, order, attempt, provider id and outcome.
- PayPal: no refunder needed. The PayPal closer (S2d) closes the order without capture and the pin test `57d0fa3` proves a late approval never captures.

## Proof

- payment-base: `ReplacedAttemptsTest` (unit), `FindByReplacedAttemptTest` (integration, real DB); gate COMMITABLE; integration `tests/Integration/Repository` 62 green.
- stripe: `StripeLatePaymentRefunderTest`, webhook handler tests — `tests/Unit/Stripe/Webhook` 104, `tests/Unit/Stripe/Service` 602 green. Gate: PHPCS + PHPStan green; PHPMD `StripeOrderController` and suite load error are pre-existing.
- mollie-payment: `MollieLatePaymentRefunderTest` 4, handler tests (2 new); gate ALL CHECKS PASSED; CI phpcs (`tests/phpcs.xml`) clean on `src`.
- Container (cache cleared): Mollie `WebhookContractFulfillmentHandlerInterface` and Stripe `StripeWebhookProcessor` with all 8 handlers resolve.
- Side fix: payment-base PHPStan `arrayValues.list` in `findByStateAndProvider()` (old line, surfaced by the changed-file gate).

## Not done

- Admin view (sprint 4.6 "Payment tab shows each replaced attempt with money and its refund state"). Today the merchant sees the `ERROR` log line and the history in the contract metadata. Follow-up.
- No live run against Stripe/Mollie: the replaced attempt is hard to pay in the sandbox after the closer ran. Unit + integration only.
