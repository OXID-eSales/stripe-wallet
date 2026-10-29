# OPC-175 S4 — An ended order gives back its vouchers and its stock

Date: 2026-10-29. Branch: `b-7.4.x-coupon-reaplying-OPC-175`. Not pushed.

## Problem

- G6: a Mollie webhook `canceled` / `failed` set only `OXTRANSSTATUS`. No storno, the voucher stayed used, the stock stayed taken, and the cleanup command (NOT_FINISHED only) never saw the order again.
- G7: PayPal `PAYMENT.AUTHORIZATION.VOIDED` cancelled only the contract; the order stayed `NOT_FINISHED` (and a terminal contract was hidden by `catch (Throwable)`).
- G9: the early order takes the stock in `finalizeOrder()`. The SQL storno of the cleanup command and `deleteNotFinishedOrder()` did not return it.
- Stripe ended its orders with storno + voucher release, but without restock — its own copy of the logic.

## Change (one path for each case)

| Repo | Commit | Content |
|---|---|---|
| payment-base | `2193886` | `LinkedOrderEndingInterface` / `OxidLinkedOrderEnding`: set the status; unpaid order → core `cancelOrder()` (storno + restock; payment-base `Order` extension releases vouchers, drops waiting mails); paid order → status only. `OrderRestockInterface` / `OxidOrderRestock` (core `OrderArticle::cancelOrderArticle()`): the cleanup command and `deleteNotFinishedOrder()` restock after the guarded storno. PHPStan stubs for `cancelOrder`, `getOrderArticles`, `OrderArticle`, `oxorder__oxpaid`. |
| mollie-payment | `af77b8a` | `OxidContractLinkedOrderUpdater` delegates to the shared ending (G6). |
| paypal | `a3c4f48` | `PaymentAuthorizationVoidedHandler`: terminal contract → nothing; else cancel + `deleteNotFinishedOrder()` (G7). No swallowed exception. |
| stripe | `2db68e2` | `OxidContractLinkedOrderUpdater` delegates to the shared ending: the stock comes back too. Its own voucher release + logger removed. |

Not changed: Mollie `expired` and Stripe `checkout.session.expired` leave the order `NOT_FINISHED` (same in both). The cleanup command ends it later — now with vouchers and stock back.

## Proof

- G6 red → green: mollie `LinkedOrderEndReleasesVoucherTest` 3/3 (unpaid cancelled/failed: storno + voucher free; paid keeps the voucher).
- G7 red → green: paypal `AuthorizationVoidedReleasesVoucherTest` 1/1 (contract cancelled, order CANCELLED + storno, voucher free).
- G9: payment-base `OxidOrderRestockTest` 3 (real article: stock 10 → 12 once on restock, on `deleteNotFinishedOrder()`, on the shared ending with status FAILED). Runs in its own process — a plain TestCase that builds the container first broke the later `OxidShopOrderServiceHeadlessDoubleSubmitTest`.
- Unit: payment-base 1966 green (`OxidLinkedOrderEndingTest` 3, cleanup + order service tests extended); mollie updater 3; paypal Unit 840 green (voided: 2 tests); Stripe `tests/Unit/Stripe/Service` 592, `Webhook` 104, integration `StaleOrderCleanupIntegrationTest` 2 green.
- payment-base integration suite: 242 green.
- Gates: mollie ALL CHECKS PASSED; payment-base PHPCS/PHPMD/unit green, CI-form PHPStan (`phpstan.neon` + baseline) "No errors" — the local changed-file mode lists 4 old lines of `OxidShopOrderService.php` (403/420/475/541, untouched); paypal PHPStan 87 errors before and after (pre-existing, none in the changed files); Stripe PHPMD `StripeOrderController` and suite load error pre-existing.
- Container (cache cleared): PayPal voided handler, cleanup service, order service, Mollie fulfillment handler resolve.

## Follow-ups

- `RetryCleanupService::cleanupPreviousAttempt()` has no production caller (from S2e).
