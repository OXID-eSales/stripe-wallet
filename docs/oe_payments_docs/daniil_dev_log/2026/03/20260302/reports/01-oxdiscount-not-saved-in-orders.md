# Report: OXDISCOUNT Not Saved in Orders

**Date:** 2026-03-02
**Branch:** b-7.4.x-discounts-in-payment-intentions-STRP-103
**Issue:** Orders created through Stripe checkout have `OXDISCOUNT = 0` despite an active discount being configured.

---

## 1. Problem Description

All orders in the shop have `OXDISCOUNT = 0` in the `oxorder` table, even though a 10% discount named "disc10" is configured and active in the OXID admin. Product prices in the basket are already shown with the discount applied (reduced unit prices), but the discount is not visible as a separate line item.

**Observed (Order 188):**
- Ocean Eyes: OXBPRICE = 68.85 (catalog OXPRICE = 106.50)
- ME-DCS-X81: OXBPRICE = 1004.90 (catalog OXPRICE = 1146.55)
- OXDISCOUNT = 0
- Product prices are already reduced — discount is baked into unit prices

## 2. Root Cause

### OXID eShop has two distinct discount application modes:

**Item-level discounts** — applied directly to the article's unit price:
- Triggered when discount is assigned to **specific articles or categories** in `oxobject2discount`
- `Basket::calcItemsPrice()` calls `DiscountList::getBasketItemDiscounts()` per article
- Discount is applied via `$oBasketPrice->setDiscount()` + `calculateDiscount()`
- Unit prices are reduced before they reach basket totals
- **OXDISCOUNT stays 0** — discount is invisible as a separate line

**Basket-level discounts** — shown as a separate "Discount" line:
- Triggered when discount has **NO article/category assignments**
- `Basket::calcBasketDiscount()` calls `DiscountList::getBasketDiscounts()`
- Stored in `Basket::_aDiscounts` → written to `oxorder.OXDISCOUNT`
- Unit prices remain at full brutto

### The critical code in `Discount::isForBasket()` (line 321–327):

```php
$sQ = 'select 1 from oxobject2discount
    where oxdiscountid = :oxdiscountid and oxtype in ("oxarticles", "oxcategories")';
return !((bool)$oDb->getOne($sQ, $params));
```

The `!` negation means: if the discount HAS article/category assignments → `isForBasket()` returns `false` → discount is NOT treated as a basket discount.

### Meanwhile `Discount::isForBasketItem()` (line 216–229):

Checks if the discount IS assigned to the specific article → returns `true` → discount IS applied at item level, reducing the unit price.

### The "disc10" discount configuration:

| Assignment | OXTYPE | OXOBJECTID |
|------------|--------|------------|
| Article "Ocean Eyes" | oxarticles | 22e135eb03a3aa69198ae30762ee785c |
| Category | oxcategories | 8eacb69eb3e19d0287a0c174ba119bcb |

Because "disc10" has `oxarticles` and `oxcategories` assignments, OXID applies it as an **item-level discount** (reduces unit price). It never reaches `_aDiscounts` and therefore `OXDISCOUNT` stays 0.

**This is OXID eShop's expected behavior, not a bug in the Stripe module.**

## 3. Solution

### To get basket-level discount (full brutto prices + separate discount line):

1. In OXID Admin → Shop Settings → Discounts → "disc10"
2. **Remove** all assignments from the "Articles" and "Categories" tabs
3. **Keep** the user group assignment (Users tab)
4. The discount will now match `isForBasket()` (no article/category rows) and appear as `OXDISCOUNT`

### Impact on Stripe module:

- **No code changes needed** — the Stripe module correctly passes the basket to `Order::finalizeOrder()`, which reads `$oBasket->getDiscounts()` to populate `OXDISCOUNT`
- When the discount is configured as basket-level, `_aDiscounts` will be populated during `calculateBasket()` and correctly saved to the order

## 4. Verification

After reconfiguring the discount:
1. Place a test order through standard OXID checkout (Cash on Delivery)
2. Verify in OXID Admin that the order shows: full product brutto prices + separate Discount line
3. Place a test order through Stripe checkout
4. Verify both orders show identical discount handling

## 5. Files Referenced (OXID Core)

| File | Lines | Role |
|------|-------|------|
| `Application/Model/Discount.php` | 202–230 | `isForBasketItem()` — matches item-level discounts |
| `Application/Model/Discount.php` | 300–328 | `isForBasket()` — matches basket-level discounts (negated query) |
| `Application/Model/DiscountList.php` | 194–206 | `getBasketItemDiscounts()` — returns per-article discounts |
| `Application/Model/DiscountList.php` | 216–228 | `getBasketDiscounts()` — returns basket-wide discounts |
| `Application/Model/Basket.php` | 814–822 | Item discount application in `calcItemsPrice()` |
| `Application/Model/Basket.php` | 1197–1255 | Basket discount calculation in `calcBasketDiscount()` |
| `Application/Model/Order.php` | 686–696 | `loadFromBasket()` — reads `getDiscounts()` → OXDISCOUNT |

## 6. Note on Failed Fix Attempt

A previous attempt to add `$basket->calculateBasket(true)` in `OxidShopOrderService::validateBasketAndUser()` caused basket clearing (Order 183 created with all zeros). This has been reverted. The root cause was not a missing recalculation but the discount configuration mode in OXID.
