# Sprint 68b — M9: Address Hash HMAC Binding

**Date:** 2026-02-24
**Status:** DONE
**Finding:** M9 — Address Hash Not HMAC-Protected (MEDIUM)
**Package:** payment-component + stripe (DI)

## Problem

`ContractMetadataService::computeAddressHashFromBasket()` uses OXID's `getEncodedDeliveryAddress()` which produces an MD5 hash **without a server-side secret**. An attacker with DB write access (SQL injection, compromised admin) can forge a matching MD5 hash for a modified delivery address, bypassing the address-change detection between contract creation and order fulfillment.

## Fix

### New Service: `AddressHmacService`

Wraps OXID's MD5 address hash with HMAC-SHA256 using a server-side secret. Two methods: `sign()` and `verify()` with timing-safe `hash_equals()` comparison.

### Flow Change

```
BEFORE (forgeable):
  Store:   MD5 hash → contract metadata
  Verify:  MD5 hash from metadata → inject into $_REQUEST

AFTER (HMAC-protected):
  Store:   MD5 hash + HMAC-SHA256(MD5, secret) → contract metadata
  Verify:  recalculate HMAC → compare with stored HMAC → if match, inject MD5
```

### Backwards Compatibility

Contracts created before this change have no `delivery_address_hmac` metadata key. `getVerifiedDeliveryAddressHash()` falls back to returning the raw hash when no HMAC is stored — existing contracts continue to work.

## Files Created (3)

### Production (2)
- `payment-component/src/Service/AddressHmacServiceInterface.php` — Interface (ISP, LSP)
- `payment-component/src/Service/AddressHmacService.php` — HMAC-SHA256 sign/verify implementation
  - Rejects empty secret in constructor
  - Returns empty string for empty hash (edge case safety)
  - Uses `hash_equals()` for timing-safe comparison

### Tests (1)
- `payment-component/tests/Unit/Service/AddressHmacServiceTest.php`

## Files Modified (2)

- `payment-component/src/Service/ContractMetadataService.php`
  - Added `?AddressHmacServiceInterface` constructor parameter (nullable for backwards compat)
  - `storeDeliveryAddressMetadata()`: stores `delivery_address_hmac` alongside hash when service available
  - Added `getVerifiedDeliveryAddressHash()`: verifies HMAC before returning hash, falls back for legacy contracts

- `stripe/services.yaml`
  - Added `AddressHmacService` definition with `$secret: '%stripe.address_hmac_secret%'`
  - Added `$addressHmacService` argument to `ContractMetadataService`
  - Added `stripe.address_hmac_secret` parameter

## Test Results

```
Tests: 6, Assertions: 8, Failures: 0
```

| # | Test | What It Proves |
|---|------|----------------|
| 1 | `hmacDiffersFromPlainMd5` | HMAC output != raw input |
| 2 | `hmacRequiresSecret` | Different secrets → different HMACs |
| 3 | `hmacVerifiesSuccessfully` | sign→verify round-trip works |
| 4 | `hmacRejectsTamperedHash` | Modified hash fails verification |
| 5 | `hmacRejectsEmptyHash` | Empty inputs → `false` |
| 6 | `constructorRejectsEmptySecret` | Empty secret → `InvalidArgumentException` |

## SOLID Compliance

- **S**: `AddressHmacService` — one job (sign/verify). `ContractMetadataService` — metadata storage.
- **O**: `ContractMetadataService` extended via constructor injection, not modified internally
- **L**: `AddressHmacService` implements `AddressHmacServiceInterface` — substitutable
- **I**: Interface has 2 methods only (sign, verify)
- **D**: `ContractMetadataService` depends on `AddressHmacServiceInterface` abstraction, injected via DI
