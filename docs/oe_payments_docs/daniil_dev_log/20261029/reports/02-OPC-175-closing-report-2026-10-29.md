# OPC-175 — Closing report (2026-10-29)

Branch `b-7.4.x-coupon-reaplying-OPC-175` in payment-base, stripe, mollie-payment, paypal, one-page-checkout; OPC e2e repo local branch `OPC-175-coupon-payment-switch-e2e`. **Nothing pushed.**

## Result

A coupon applied in OPC stays when the customer changes the payment method. The same NOT_FINISHED order and the same contract continue (one order — one contract, no fallbacks). The order is rewritten in place from the basket, the old PSP page is closed, money on a replaced attempt goes back, and an order that ends gives back its vouchers and its stock.

## Stories

| Story | Commits |
|---|---|
| S0 proof | pb `f634b79`, mollie `33f7f28`, paypal `53563cf`, stripe `dd6cac0`, e2e `2a4a7db` |
| S1 + S3 Stripe on the shared chain, paid attempt never cancelled | stripe `7cd03e4`, `566e24b` |
| S2a coupon valid while the attempt is open | pb `1c97198`, `6da11f1` |
| S2b one order, one contract (unique OXORDERID) | pb `2fa1706` |
| S2c rewrite the order in place | pb `2150aee` |
| S2d re-bind the contract, close the PSP page | pb `002611b`, stripe `d783116`, mollie `233406b`, paypal `c15bb21` |
| S2e continue the open attempt, remove retire/unwind paths | pb `c7559d8`, stripe `7c37b06`, mollie `4140787`, paypal `c7cce05`, OPC `86e3d9d7`, e2e `0b8c27c` |
| S2g money on a replaced attempt goes back | pb `97db439`, stripe `90773c6`, mollie `e2fd66e` |
| S4 ended order releases vouchers + stock | pb `2193886`, mollie `af77b8a`, paypal `a3c4f48`, stripe `2db68e2` |
| S5 proof | e2e `2f47ccd` (matrix spec fix) |

## S5 proof (local shop https://daniil.oxiddev.de)

| Check | Result |
|---|---|
| `opc-175-coupon-survives-payment-switch.spec.ts` (Stripe→Mollie, Mollie→Stripe, PayPal→Stripe) | **3/3 green** — one order + one contract continue, total 1320 with `E2E_5FLAT` |
| `stripe-double-click-one-order.spec.ts` | green |
| `checkout-matrix` OPC / Stripe with + without coupon | **2/2 green** |
| `checkout-matrix` classic `cl=order` / Stripe with + without coupon (OPC switched off for the run, restored after) | **2/2 green** after the spec fix (Terms ticked before the sheet mounts — stripe `093ef4a` on b-7.4.x) |
| DB: vouchers linked to a stornoed order (today) | 0 |
| DB: orders with more than one contract | 0 |
| Unit/integration | pb unit 1966, pb integration 242, PayPal unit 840, Stripe Service 592 + Webhook 104, Mollie gate ALL CHECKS PASSED |

### Not green, not caused by this branch

- Mollie cells (matrix, `mollie-voucher-checkout`, `mollie-double-click-one-order`): Mollie answers 422 "The payment method is not enabled in your website profile" — Mollie profile setting of the dev account.
- Invoice cells: `oxidinvoice` is inactive in this shop (OXACTIVE=0) → `invalid_payment_method`.
- OPC `voucher-*` specs (19): fixture setup fails, datagen-rest Voucher fixture answers HTTP 404 (`OeDataGenApi` cannot be resolved).
- `tests/checkout/stripe-checkout.spec.ts`: stale generic spec (first "Details" link, no `#toBasket`).
- Classic Mollie/invoice not run (same environment causes).
- No real sandbox payment round trip (`coupon-switch-roundtrip`): not run.

### Gates

- mollie-payment: ALL CHECKS PASSED.
- payment-base: PHPCS, PHPMD, unit green; CI-form PHPStan (module `phpstan.neon` + baseline) "No errors". The local changed-file mode lists 4 untouched old lines in `OxidShopOrderService.php`.
- stripe: PHPCS + PHPStan green; pre-existing PHPMD `StripeOrderController` TooManyMethods and unit-suite load error (`PaymentControllerCleanupTest`).
- paypal: PHPStan 87 errors before and after (pre-existing, none in changed files).

## Shop state changes made during the sprint

- `oe_payment_base` class extension (Voucher) added by hand to `var/configuration/shops/1/modules/oe_payment_base.yaml` + `class_extension_chain.yaml` (backups in the job tmp) — a module re-activation does the same after merge.
- Migration `Version20261029100000` ran (unique OXORDERID); 135 test duplicates + one real Mollie duplicate deleted after backup (`opc175-duplicate-contracts-backup.sql`, job tmp).
- OPC `oeOnePageCheckoutEnabled` was switched off twice for the classic runs and restored to `true`.

## Open follow-ups

1. Admin view for money on a replaced attempt (sprint 4.6): today only an ERROR log line + `replaced_attempts` history in the contract.
2. Mollie `expired` and Stripe `checkout.session.expired` leave the order NOT_FINISHED until the cleanup command (consistent, but late).
3. `RetryCleanupService::cleanupPreviousAttempt()` has no production caller.
4. Re-entering the same coupon code while its row is held by the open attempt is refused by core `getVoucherByNr()`.
5. Headless checkout continues only through its own scope registry.
6. Sandbox round trip with a real payment after a switch; Mollie profile methods + datagen Voucher fixture need fixing to run the rest of the regression set.
7. Push + PRs (payment-base first) — waiting for approval.
