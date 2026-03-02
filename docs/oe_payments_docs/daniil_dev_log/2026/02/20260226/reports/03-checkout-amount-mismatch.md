# BUG: Payment Amount Mismatch Between Cart and Stripe Checkout

**Date:** 2026-02-26
**Severity:** CRITICAL
**Status:** Root cause identified, fix pending

## Symptom

The grand total displayed in the cart does not match the amount shown on the Stripe Checkout payment page. The customer is charged the wrong amount.

**Steps to reproduce:**
1. Add a product to the cart
2. Apply a discount or voucher
3. Note the Grand Total in the cart (e.g. €75.00 after discount)
4. Proceed to checkout → payment page
5. Stripe shows a different (higher) total (e.g. €80.00)

## Root Cause

`CheckoutSessionService::buildLineItems()` only converts `snapshot->getItems()` into Stripe line items. It **completely ignores** `snapshot->getDiscounts()`.

The `BasketSnapshot` stores data in two separate collections:
- `items[]` — products + shipping + payment fees (from `extractProductItems()` + `extractAdditionalCosts()`)
- `discounts[]` — vouchers, basket discounts (from `extractDiscounts()`)

But `buildLineItems()` only reads `items[]`:

```php
// CheckoutSessionService.php:123-148
public function buildLineItems(BasketSnapshot $snapshot): array
{
    $lineItems = [];
    $currency = strtolower($snapshot->getCurrency());

    foreach ($snapshot->getItems() as $item) {        // ← ONLY items
        // ... builds Stripe line item ...
    }

    return $lineItems;                                 // ← discounts MISSING
}
```

Meanwhile, `BasketSnapshot::getTotalGross()` returns the **correct** total (from `basket->getPrice()->getBruttoPrice()` which includes everything). But this value is never used to validate or correct the Stripe line items.

## Example

```
Basket state:
  Product A:     €50.00 × 2 = €100.00
  Shipping:      €10.00
  Discount:      -€5.00
  Voucher:       -€10.00
  ─────────────────────────
  Grand Total:   €95.00

BasketSnapshot:
  items: [
    {title: "Product A",  unitPrice: 50.00, quantity: 2},    // €100
    {title: "Shipping",   unitPrice: 10.00, quantity: 1},    // €10
  ]
  discounts: [
    {name: "Summer Sale", amount: 5.00},                     // -€5  ← IGNORED
    {name: "Voucher ABC", amount: 10.00},                    // -€10 ← IGNORED
  ]
  totalGross: 95.00                                          // ← CORRECT but unused

Stripe receives line_items:
  [
    {unit_amount: 5000, quantity: 2},    // €100
    {unit_amount: 1000, quantity: 1},    // €10
  ]
  Stripe total: €110.00                  // ← WRONG! Should be €95.00
```

## Affected Code Path

```
StripeOrderController::createCheckoutSession()
  → StripeCheckoutSessionHandler::handle()
    → CheckoutSessionService::createSession()
      → buildLineItems($basketSnapshot)          ← BUG: ignores discounts
      → Stripe API: createCheckoutSession(line_items: [...])
```

## Key Files

| File | Lines | Role |
|------|-------|------|
| `src/Stripe/Service/CheckoutSessionService.php` | 123-148 | **BUG:** `buildLineItems()` ignores `snapshot->getDiscounts()` |
| `src/Stripe/Service/CheckoutSessionService.php` | 46-118 | `createSession()` passes line items to Stripe API |
| `payment-component/src/Service/ContractService.php` | 70-90 | Creates `BasketSnapshot` with items + discounts separately |
| `payment-component/src/Service/ContractService.php` | 187-224 | `extractDiscounts()` stores discounts in separate field |
| `payment-component/src/Contract/BasketSnapshot.php` | 190-193 | `getDiscounts()` — available but never called by Stripe |

## Fix Options

### Option A: Use `totalGross` as single line item (Recommended)

Instead of reconstructing line items from the snapshot, send a single line item with the correct total from `snapshot->getTotalGross()`. This is the safest approach because:
- The total is calculated by OXID's basket engine (authoritative source)
- No rounding errors from re-summing individual items
- Discounts, vouchers, shipping — all already included

```php
public function buildLineItems(BasketSnapshot $snapshot): array
{
    $currency = strtolower($snapshot->getCurrency());
    $totalCents = (int) round($snapshot->getTotalGross() * 100);

    return [
        [
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $totalCents,
                'product_data' => [
                    'name' => 'Order Total',
                ],
            ],
            'quantity' => 1,
        ],
    ];
}
```

**Pros:** Simple, correct, no rounding issues
**Cons:** Customer sees "Order Total: €95.00" on Stripe page instead of itemized list

### Option B: Keep itemized list + add discount line items

Add discounts as negative-amount line items:

```php
// After existing item loop, add:
foreach ($snapshot->getDiscounts() as $discount) {
    $amount = isset($discount['amount']) ? (float) $discount['amount'] : 0.0;
    if ($amount <= 0.0) {
        continue;
    }
    $lineItems[] = [
        'price_data' => [
            'currency' => $currency,
            'unit_amount' => -1 * (int) round($amount * 100),
            'product_data' => [
                'name' => $discount['name'] ?? 'Discount',
            ],
        ],
        'quantity' => 1,
    ];
}
```

**Pros:** Customer sees itemized breakdown
**Cons:** Stripe does NOT support negative `unit_amount` in line items — this won't work

### Option C: Use Stripe Coupons/Discounts API

Create Stripe coupon objects for discounts and pass them via `discounts` parameter in the Checkout Session.

**Pros:** Proper Stripe-native discount display
**Cons:** More complex, requires creating coupon objects, potential cleanup needed

### Option D: Itemized list + adjustment line item

Keep all items, then add an "Adjustment" line item to reconcile with `totalGross`:

```php
$itemsTotal = array_sum(array_map(
    fn($li) => $li['price_data']['unit_amount'] * $li['quantity'],
    $lineItems
));
$expectedTotal = (int) round($snapshot->getTotalGross() * 100);
$diff = $expectedTotal - $itemsTotal;

if ($diff !== 0 && $diff < 0) {
    // Stripe doesn't allow negative amounts, so reduce the last item
    // or use Stripe coupons API
}
```

**Cons:** Fragile, negative adjustments can't be line items

### Recommendation

**Option A** is the safest and simplest fix. The Stripe Checkout page will show "Order Total" as a single line. If itemized display is required, **Option C** (Stripe Coupons) is the proper approach but significantly more complex.

## Impact

- Customers are **overcharged** when discounts or vouchers are applied
- The discrepancy is visible to the customer (cart says €95, Stripe page says €110)
- This likely causes cart abandonment and customer complaints
- Refunds would be needed to correct overcharges
