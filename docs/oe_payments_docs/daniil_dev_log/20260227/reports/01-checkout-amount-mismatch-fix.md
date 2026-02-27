# FIX: Payment Amount Mismatch Between Cart and Stripe Checkout

**Date:** 2026-02-27
**Severity:** CRITICAL
**Status:** FIXED (TDD)
**Related:** Report `20260226/reports/03-checkout-amount-mismatch.md` (root cause analysis)

## Problem

The amount sent to Stripe Checkout did not include discounts or vouchers. Customers were overcharged when any discount was applied.

**Example:** Cart total €95 (products €100 + shipping €10 - discount €15), but Stripe charged €110.

## Root Causes (Two Issues)

### Issue 1: `buildLineItems()` ignored discounts
`CheckoutSessionService::buildLineItems()` only iterated `snapshot->getItems()` (products + shipping + fees) and completely ignored `snapshot->getDiscounts()`. Since Stripe Checkout doesn't support negative `unit_amount`, there was no way to represent discounts as line items.

### Issue 2: Vouchers never extracted into snapshot
OXID separates discount types:
- `basket->getDiscounts()` — returns item-level + basket-level discounts
- `basket->getVouchers()` — returns voucher discounts (separate API!)

`ContractService::extractDiscounts()` only called `getDiscounts()`, missing all vouchers. This meant even if Issue 1 was fixed, voucher-only baskets would still have empty `getDiscounts()` in the snapshot.

## Solution

### Part 1: Extract vouchers into snapshot discounts (payment-component)
**File:** `payment-component/src/Service/ContractService.php`

Extended `extractDiscounts()` to also call `basket->getVouchers()` and extract each voucher's `dVoucherdiscount` amount into the discounts array. Now the snapshot explicitly contains ALL discount types.

### Part 2: Use `totalGross` when discounts present (stripe module)
**File:** `src/Stripe/Service/CheckoutSessionService.php`

`buildLineItems()` checks `snapshot->getDiscounts()`:
- **Empty** → itemized line items (products visible on Stripe page)
- **Non-empty** → single "Order Total" line item using `snapshot->getTotalGross()`

This is an **explicit** check based on actual discount data in the snapshot, not a heuristic comparison of sums.

## TDD Process

### Red Phase — 8 tests written first (6 failing):

| Test | Scenario | Expected | Before fix |
|------|----------|----------|------------|
| `testBuildLineItemsTotalMatchesTotalGrossWithDiscounts` | Products €100 + Shipping €10 - 2 discounts €15 | 9500 cents | 11000 |
| `testBuildLineItemsTotalMatchesTotalGrossWithSingleDiscount` | Product €29.99 - discount €3 | 2699 cents | 2999 |
| `testBuildLineItemsNoDiscountsStillMatchesTotalGross` | No discounts, items match total | 3500 cents | 3500 (pass) |
| `testBuildLineItemsIncludesDiscountsAsVisibleItems` | Product €50 - discount €10 | 4000 cents | 5000 |
| `testBuildLineItemsWithMultipleDiscountsAndShipping` | Complex basket, 2 discounts | 5500 cents | 7250 |
| `testCreateSessionSendsCorrectAmountWithDiscounts` | End-to-end: Stripe API receives correct amount | 8500 cents | 11000 |
| `testBuildLineItemsUsesTotalGrossWhenVoucherApplied` | Voucher €10 applied | 5000 cents | would be 6000 |
| `testBuildLineItemsKeepsItemizedWhenSumMatchesTotalGross` | No discounts, itemized display | 2 items | 2 items (pass) |

### Green Phase — Implementation in two layers:

1. `ContractService::extractDiscounts()` — now extracts vouchers via `getVouchers()`
2. `CheckoutSessionService::buildLineItems()` — explicit discount check → totalGross fallback

## Files Changed

### `payment-component/src/Service/ContractService.php`
Extended `extractDiscounts()` to also iterate `basket->getVouchers()` and extract voucher amounts (`dVoucherdiscount`) into the discounts array.

### `src/Stripe/Service/CheckoutSessionService.php`
```php
public function buildLineItems(BasketSnapshot $snapshot): array
{
    $currency = strtolower($snapshot->getCurrency());

    if (!empty($snapshot->getDiscounts())) {
        return $this->buildTotalLineItem($snapshot, $currency);
    }

    return $this->buildItemizedLineItems($snapshot, $currency);
}
```

Plus two private methods:
- `buildItemizedLineItems()` — extracted from original `buildLineItems()`
- `buildTotalLineItem()` — uses `snapshot->getTotalGross()`

### `tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php`
- Updated `createBasketSnapshot()` helper to accept `$discounts` and `$currency` params
- Updated existing tests to pass matching `totalGross` values
- Added 8 new test methods covering discount/voucher scenarios

## Verification

- Stripe unit suite: 723 tests, 1728 assertions — all pass
- CheckoutSessionServiceTest: 27 tests, 77 assertions — all pass
- payment-component ContractServiceTest: 5 tests, 20 assertions — all pass
- No regressions

## Trade-offs

**When discounts/vouchers are applied**, the Stripe Checkout page shows a single "Order Total" line instead of an itemized product list. This is because Stripe's line_items API doesn't support negative amounts.

**Alternative considered:** Stripe Coupons API could show itemized products with a visible discount, but would require creating/managing coupon objects per checkout — significantly more complex with cleanup concerns.

**Why `totalGross` is safe:** It comes from OXID's `basket->getPrice()->getBruttoPrice()` which is the basket engine's authoritative final total, including all products, shipping, fees, discounts, vouchers, and taxes.
