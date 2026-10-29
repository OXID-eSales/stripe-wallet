# OPC-175 · S2e — The next start continues the open attempt; the second paths are gone — done 2026-10-29

**Commits (not pushed):** payment-base `c7559d8`, stripe `7c37b06`, mollie-payment `4140787`, paypal `c7cce05`, one-page-checkout `86e3d9d7`, OPC e2e repo `0b8c27c`.

## The flow (one order, one contract, one path)
1. Every provider creates its contract through payment-base `ContractService::createContract()`. When the session's `OpenCheckoutAttemptRegistry` holds an open attempt of this user (draft with order, `not_finished`, `pending`), the provider of that attempt closes its payment page (`PaymentAttemptClosersInterface`, S2d) and the **same contract** is reopened with the new basket and conditions. A paid attempt refuses the new start (`CheckoutAttemptAlreadyPaidException`).
2. `EarlyOrderWriter(Interface)`: a contract that has its order gets it **written again** (`rewriteOrder()`, S2c); a new contract gets a new order. Used by `EarlyOrderCreationHandler` (Stripe, Mollie, OPC) and `PayPalOrderCreationHandler`.
3. Stripe stamps every Checkout Session and payment intent with `contract_attempt`. A Stripe webhook of a replaced attempt (the expiry the closer caused, a late payment) is not applied to the contract (`AbstractStripeWebhookHandler::contractOfCurrentAttempt()` / `isOfReplacedAttempt()`). Mollie and PayPal find contracts by the provider's own payment / order id, so a replaced attempt matches nothing there.

## Removed (no second path)
- Retire of the previous attempt in `EarlyOrderCreationHandler` and `PayPalOrderCreationHandler` (+ the `previousAttemptCleaner` / `openAttemptFinder` collaborators).
- Retire on return: OPC `ExternalReturnAttemptCleanupHandler`, Stripe `OpcExternalReturnCleanupHandler`, Mollie `AbandonedAttemptCleanup`, PayPal `CheckoutCancellation`.
- Stripe standard checkout: retire on payment-page render, order-page render, `checkoutCancel` and before a new session; `RetryCleanupService::cleanupForUser()` (a cross-session fallback).
- OPC-96 voucher snapshot / unwind / restore (`OpcVoucherSnapshotService`, `OpcVoucherReservationUnwindService`, `OpcVoucherRestoreHandler`, the registry's external-payment hooks, the snapshot session key) and the OPC-185 unwind + re-add in `setPaymentMethod()`.

## Proof
- Playwright `opc-175-coupon-survives-payment-switch.spec.ts` (expectations changed to the decision): Stripe→Mollie, Mollie→Stripe, PayPal→Stripe — **one order for the whole checkout**, its payment type follows the last attempt, `NOT_FINISHED`, the voucher stays on it, **exactly one contract** with the replaced attempt in `replaced_attempts` (closures seen: Stripe `closed` (session expired), Mollie `open` (not cancelable), PayPal `closed`). 3/3 green, total stays 1320.
- Unit: payment-base 1959 (`ContractServiceTest` +5, `EarlyOrderCreationHandlerOpenAttemptTest` 4, reopen 10), Stripe Webhook 103 / Service 597 / PaymentHandler 42 / EventSystem 202 / Handler 42, Mollie 958, PayPal 839, OPC 692.
- Gates: payment-base and Mollie all checks passed; Stripe / PayPal PHPCS + PHPStan clean on the changed files (two Stripe >120-char warnings are on old lines); OPC: PHPCS / PHPMD / architecture guards passed.

## Known, not caused by this story
- OPC gate: PHPStan `BasketService.php:485` (file untouched), `PaymentControllerTest` error, ESLint / JS tests — all red before.
- Stripe controller unit tests (`StripeOrderController*Test`, `PaymentControllerCleanupTest`) do not load in this environment (test-support autoload), before and after.
- A headless attempt continues only through its own scope's registry; another device opens its own attempt (its own order and contract — 1:1 holds).
- A Mollie webhook `canceled` that arrives before the next start ends the attempt (the contract is the current one); the next start then creates a new contract and order.
- `RetryCleanupService::cleanupPreviousAttempt()` has no production caller any more (kept: its tests cover the cancel logic the stale cleanup shares). Follow-up.
