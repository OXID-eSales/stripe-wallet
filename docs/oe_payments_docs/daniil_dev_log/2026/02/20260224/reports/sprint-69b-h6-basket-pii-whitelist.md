# Sprint 69b — H6: Basket Snapshot PII Whitelist

**Date:** 2026-02-24
**Status:** DONE
**Finding:** H6 — PII in Basket Snapshot (CVSS 4.0, HIGH)
**Package:** payment-component

## Problem

`BasketSnapshot::extractItems()` passes through all item array fields without filtering. If OXID basket items contain buyer-specific data (gift messages, personalization fields, customer notes, internal IDs), it all gets persisted as JSON in `oe_payments_contract.OXBASKETDATA`.

The contract table is long-lived (audit trail, reconciliation). Under GDPR data minimization, only fields needed for the contract purpose should persist.

## Fix

Added a **whitelist-based** sanitizer to `BasketSnapshot`. Only explicitly allowed item fields survive:

```php
private const ITEM_WHITELIST = ['artnum', 'title', 'quantity', 'price', 'vat', 'amount'];
```

Applied in `extractItems()` via `sanitizeItems()`:

```php
private static function sanitizeItems(array $items): array
{
    $whitelist = array_flip(self::ITEM_WHITELIST);
    return array_map(
        fn(array $item): array => array_intersect_key($item, $whitelist),
        $items
    );
}
```

### Why Whitelist (Not Blacklist)?

Blacklisting is fragile — new PII fields added by OXID plugins or custom extensions would pass through silently. Whitelisting ensures only known-safe fields persist. If a new essential field is needed later, it's added to the constant explicitly.

### Why Not Filter Discounts?

Discounts are system-generated (voucher codes, campaign rules) — they don't contain user-provided PII. Same pattern can be applied later if needed.

## Files Modified (1)

- `payment-component/src/Contract/BasketSnapshot.php`
  - Added `ITEM_WHITELIST` constant
  - Added `sanitizeItems(array): array` private static method
  - Modified `extractItems()` to call `sanitizeItems()` before returning

## Files Created (1)

### Tests
- `payment-component/tests/Unit/Contract/BasketSnapshotSanitizationTest.php`

## Test Results

```
Tests: 9, Assertions: 11, Failures: 0
```

| # | Test | Input | Expected |
|---|------|-------|----------|
| 1 | `snapshotKeepsProductId` | `artnum=ABC123` | Preserved |
| 2 | `snapshotKeepsTitle` | `title=Widget` | Preserved |
| 3 | `snapshotKeepsQuantity` | `quantity=2` | Preserved |
| 4 | `snapshotKeepsPrice` | `price=19.99` | Preserved |
| 5 | `snapshotKeepsVat` | `vat=19.0` | Preserved |
| 6 | `snapshotStripsUnknownItemFields` | `unknownField=secret` | Removed |
| 7 | `snapshotStripsGiftMessage` | `giftMessage=Happy birthday` | Removed |
| 8 | `snapshotStripsPersonalization` | `personalization=Custom text` | Removed |
| 9 | `sanitizeItemsIsDeterministic` | Same input twice | Identical output |

## Regression Impact

Existing `BasketSnapshotTest` tests continue to pass (168 related tests, 335 assertions). The whitelist includes all fields used in existing test data (`artnum`, `title`, `quantity`, `price`, `vat`, `amount`).

## SOLID Compliance

- **S**: `sanitizeItems()` has one job — filter item fields
- **O**: `toArray()` serialization unaffected — outputs whatever is stored
- **L**: `fromArray()` contract unchanged — still returns `BasketSnapshot`
- **I**: No new interfaces
- **D**: No new dependencies
