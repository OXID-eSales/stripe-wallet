# OPC-175 · S2c — Rewrite the open order in place — done 2026-10-29

**Commit:** payment-base `2150aee` (not pushed).

## Spike result (core, OXID 7)
- `finalizeOrder($basket, $user, true)` keeps id and order number, skips `validateOrder()`, `executePayment()`, `markVouchers()` and the mail; writes payment (`setPayment()` → new `oxuserpayments` row, `OXPAYMENTID`, `OXPAYMENTTYPE`), totals and lines from the basket; ends with status `OK`; answers `ORDEREXISTS` while `sess_challenge` names any existing order.
- With the session basket every line is a NEW order line (`setIsNewOrderItem(true)`, new id) → saving takes the stock again. Old lines are not touched. `OrderArticle::delete()` gives the stock back.
- `getPaymentType()` caches the user payment; after the rewrite the cache still names the old payment.

## What changed
- `ShopOrderRewriterInterface::rewriteOrder(orderId, CreateOrderRequest)` (new, ISP) — implemented by payment-base `OxidShopOrderService`; alias in `services.yaml`. One DB transaction. Refuses an order that is not open (`order_not_rewritable`). Forgets a `sess_challenge` that names the order. Keeps the re-rendered mails on the contract; a rewrite that cannot keep its mails fails (`order_mails_not_kept`) — no second path.
- `RewritableOrderInterface` + `Order::rewriteFromBasket()` (payment-base Order extension): delete old lines (restock) → release the order's vouchers → core recalculate mode → mark the basket's vouchers → `NOT_FINISHED`, order date now → reset the payment cache → render the mails on the capturing mailer.
- PHPStan stubs: legacy DB transaction methods; the core `Order` methods the rewrite uses.

## Red → green
- `OxidShopOrderServiceRewriteOrderTest` (5, real core + Mailpit): id and number kept; payment, cost 2.50, totals = basket; old line replaced (`[[article, 2.0]]`); stock 99 → 98; `NOT_FINISHED`; mails re-rendered with the new payment, nothing sent; voucher stays on the order; a voucher removed from the basket is released; a closed order is refused. First run: 3/4 red → the mail named the old payment (payment cache) → fixed.

## Gates
- PHPCS ✓, PHPMD ✓, unit 1951 ✓. PHPStan on the changed files: only the 5 errors from before (`oxNew` not found ×3, `getBruttoPrice()` on mixed ×2 — confirmed on a stash).
- Integration: all `Adapter/OxidShopOrderService*` tests, `RetireReleasesVoucherTest` green.
