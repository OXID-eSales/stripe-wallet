# Sprint 56e: Fix Guest User __clone Crash

**Status:** DONE
**Date:** 2026-02-18
**Branch:** `b-7.4.x-mcp-STRP-88`

## Problem

Creating ACP checkout with a new email address (not existing in DB) crashed:
```
ShopOrderException: Unexpected error during order creation: __clone method called on non-object
```

## Root Cause

**OXID's magic property system + incomplete field initialization.**

1. `AcpContextResolverHandler::createGuestUser()` called `$user->assign($userData)` with only 7 fields
2. OXID's `BaseModel::assign()` only creates `Field` objects for provided keys
3. `Order::assignUserInformation($oUser)` tries to clone ~15 user field objects:
   ```php
   $this->oxorder__oxbillcompany = clone $oUser->oxuser__oxcompany;
   $this->oxorder__oxbillsal = clone $oUser->oxuser__oxsal;
   // ... etc
   ```
4. Missing fields like `oxcompany` are not initialized as `Field` objects
5. OXID's magic `__get()` returns `null` for uninitialized fields
6. `clone null` → **fatal error**

**Why existing users don't crash:** `User::load($id)` fetches ALL database columns and creates `Field` objects for every one, even empty fields. `clone $emptyFieldObject` works fine.

## Fix

Added all fields that `Order::assignUserInformation()` needs to the `$userData` array:

```php
$userData = [
    'oxusername' => $email,
    'oxfname' => $buyer['first_name'] ?? '',
    'oxlname' => $buyer['last_name'] ?? '',
    'oxsal' => '',          // NEW
    'oxcompany' => '',      // NEW
    'oxactive' => 1,
    'oxstreet' => $address['line_one'] ?? '',
    'oxstreetnr' => '',     // NEW
    'oxaddinfo' => '',      // NEW
    'oxcity' => $address['city'] ?? '',
    'oxzip' => $address['postal_code'] ?? '',
    'oxstateid' => '',      // NEW
    'oxustid' => '',        // NEW
    'oxfon' => $buyer['phone_number'] ?? '',  // NEW (mapped from buyer data)
    'oxfax' => '',          // NEW
];
```

Also maps `phone_number` from ACP buyer data to `oxfon`.

## Files Modified

| File | Changes |
|------|---------|
| `src/Stripe/Mcp/Handler/AcpContextResolverHandler.php` | Added 8 missing user fields to `createGuestUser()` |

## Lesson Learned

When creating OXID model objects via `assign()` instead of `load()`, ALL fields that downstream code might access via magic properties must be explicitly initialized. OXID's `__get()` returns `null` for missing fields rather than creating default `Field` objects.
