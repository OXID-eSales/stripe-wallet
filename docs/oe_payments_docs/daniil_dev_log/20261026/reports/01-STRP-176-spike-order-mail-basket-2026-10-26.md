# STRP-176 spike: can the order mail be sent later from the saved order?

**Date:** 2026-10-26 · **Sprint:** `../done/STRP-176-order-email-after-payment.md` · **Time-box:** 30 min (took ~60)
**Question:** at commit time there is no session basket, as on the webhook leg. Can we rebuild one from the saved
order (core `getOrderBasket()` + `addOrderArticlesToBasket()` + `calculateBasket(true)`, as `recalculateOrder()`
does) and get the same mail the customer gets today?

**Answer: no.** Rebuilding from the order gives wrong totals for most orders. The sprint's S2 changes to *render
the mail at finalize, store it, send it on commit* (option C below).

Scripts are in `strp176-spike/`. They run in the `php` container (`docker compose exec -T php php <script>`) and
never send mail: an `Email` subclass captures `send()`. The voucher reservation that `addVoucher()` makes was reset
afterwards (0 vouchers of the "Test" series reserved or used).

## 1. Rebuild from the saved order (the plan as written): fails

`rebuild-from-order.php` was run on five real orders. "reload" means core's defaults
(`_blReloadDelivery = _blReloadDiscount = true`). "stored" means `reloadDelivery(false)` + `reloadDiscount(false)`,
which uses the amounts saved on the order.

| Order | Payment | Has | DB total | reload | stored |
|-------|---------|-----|---------:|-------:|-------:|
| #416 | stripe_wallet | voucher 1440, del 125 | 13085 | 14525 ✗ | 14525 ✗ |
| #986 | paypal | voucher 24.5, del 10 | 230.5 | 245 ✗ (del 0) | 255 ✗ |
| #985 | paypal | discount 5, del 10 | 215 | 210 ✗ (del 0, discount 0) | **215 ✓** |
| #514 | invoice | voucher 240, del 125 | 2285 | 2525 ✗ | 2525 ✗ |
| #505 | mollie, lang 0 | (its only article is cancelled, so a bad sample) | n/a | **crash** (see 3) | n/a |

What goes wrong:
1. **The voucher discount is lost in every case.** In "reload" mode the vouchers are re-read from
   `oxvouchers WHERE oxorderid = …`. No order in the DB has its vouchers still linked: 0 of the 76 orders with a
   voucher discount. The payment-base `Order` extension (Sprint 09) unlinks them when an order is cancelled or
   deleted, and e2e cleanups do the same. In "stored" mode `setVoucherDiscount()` is overwritten. Outside admin,
   `Basket::calcVoucherDiscount()` recomputes it from the (empty) voucher list whenever `_blUpdateNeeded` is set,
   and `calculateBasket(true)` sets it (`… || ($this->_blUpdateNeeded && !$this->isAdmin())`). That's why core's
   `recalculateOrder()` works in admin but not in a storefront or webhook request.
2. **"reload" re-prices delivery** with today's shipping rules: #986 and #985 came out at 0.00 instead of the 10.00
   that was charged. **"reload" also drops discounts** (#985).
3. **Switching the language mid-process breaks queries.** When #505 (lang 0) followed lang-1 orders in the same
   process, it crashed with `Unknown column 'oxv_oxdelivery_1_en.oxactive'`: views cached for the previous language
   got mixed into the new query. Run alone, #505 had no error. A webhook request that switches to the order's
   language after it has already queried anything would hit the same problem.
4. `order.getVoucherList()` (the "Folgende Gutscheine werden benutzt" row) is only filled by `markVouchers()`
   during `finalizeOrder()`, so it's empty on a reloaded order.

## 2. Serialize the checkout basket, unserialize it at commit: works, with costs

`build-serialized-basket.php` builds a basket the way checkout does: 2 × article a00ec + 34 × article a10c8, plus a
"Test" 50 % voucher (minimum order value 40,000). `render-from-serialized-basket.php` unserializes it in a **fresh
process** and renders the customer mail.

| | built | unserialized (lang 1) | unserialized (lang 0) |
|-|------:|-----------:|-----------:|
| total | 20595.95 | 20595.95 | 20595.95 |
| delivery | 125 | 125 | 125 |
| voucher | 20470.95 (1 voucher) | same | same |
| VAT 19 % | 3268.47 | same | same |
| subject | | `Your order at OXID eShop (#416)` | `Ihre Bestellung bei OXID eSales (#416)` |
| body has total / voucher amount | | ✓ / ✓ | ✓ / ✓ |

The voucher row renders only if `_aVoucherList` holds loaded `Voucher` models, as `markVouchers()` leaves them
(load by id, don't call `markAsUsed()` again). With that, the body shows `Test 50%`.

Costs: about 17 KB of serialized PHP per order (23 KB base64). Running `unserialize()` on database content opens
an object-injection risk: the payload would need an HMAC (like `AddressHmacService`), and a Basket graph can't
easily be limited with `allowed_classes`. The language still has to be switched before the first query (finding 3),
and article titles are re-read at send time, not at order time.

## 3. Options

| | A. Rebuild from order | B. Serialized basket | **C. Render at finalize, send on commit** |
|-|----|----|----|
| Totals correct | ✗ (vouchers, delivery, discounts) | ✓ | ✓, the same mail as today by construction |
| Webhook leg (no session) | ✗ | ✓ | ✓ |
| Language handling | must switch before any query (crash risk) | same | none: rendered in the customer's own request, as today |
| Security | n/a | `unserialize` of DB content needs signing | stores rendered HTML (PII that's also in `oxorder`), deleted after send |
| Storage | none | ~17 KB/order | ~2 × 36 KB HTML + plain text per order, until sent |
| Code | small, but wrong | medium | medium: an `Email` extension (capture), a table, a sender |

## 4. Recommendation: C

In `finalizeOrder()` (the customer's own request, with the real basket and language) let core render both mails as
it does today, but **capture** them instead of sending. Store subject, HTML, plain text, recipient and reply-to per
mail. When `ContractCommittedEvent` fires, send the stored mails and stamp `sent_at`. That stamp is also what keeps
the send from happening twice. Delete the stored mails when the order is deleted or cleaned up as not finished, and
after a successful send.

Consequences for the sprint (applied in `../done/STRP-176-order-email-after-payment.md`):
- S1: "defer" now means "capture instead of send", not "skip".
- S2: no basket rebuild and no language switch. The handler sends the stored mails.
- New: a `payment-base` migration for `oe_payments_order_mail`. The earlier "metadata key, no new table" note no
  longer holds: the stored mail needs a table.
- Still open for a fresh order: **two VAT rates** (no order in the DB has `oxartvat2 > 0`) and **owner mail
  language** (core renders it in the shop's language via `getObjectTplLanguage()`; capturing at finalize keeps
  today's behaviour). Both are covered by S2's integration tests, not by this spike.
