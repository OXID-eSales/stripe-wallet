# Sprint 56c: Fix MCP Response Format

**Status:** DONE
**Date:** 2026-02-18
**Branch:** `b-7.4.x-mcp-STRP-88`

## Problem

MCP `create_checkout` response had three issues:
1. Empty line item IDs (showed `""` instead of product OXID)
2. Zero amounts for all line items
3. Missing Stripe checkout URL (agent couldn't provide payment link)

## Root Causes

| Issue | Root Cause |
|-------|-----------|
| Empty IDs | `AcpResponseFormatter` checked `articleId` key, but `ContractService` stores `productId` |
| Zero amounts | `ContractService` didn't extract per-item `netPrice`/`vatValue`; formatter expected `grossPrice` but snapshot has `totalPrice`/`unitPrice` |
| Missing URL | `StripeCheckoutSessionHandler::setProvider()` stored shop success URL, not Stripe checkout URL; formatter didn't include `checkout_url` |

## Fix

### 1. StripeCheckoutSessionHandler
```php
// Before: stored shop success URL
$contract->setProvider('stripe', $result->getSessionId() ?? '', $successUrl);

// After: store Stripe checkout URL
$contract->setProvider('stripe', $result->getSessionId() ?? '', $result->getCheckoutUrl());
```

Also appends `&source=acp` to success URL for ACP source detection on return.

### 2. AcpResponseFormatter
- Added `checkout_url` from `$contract->getProviderRedirectUrl()`
- Fixed field mapping: checks `productId` → `articleId` → `id` for item IDs
- Handles `totalPrice` → `grossPrice` → `unitPrice * quantity` fallback chain

### 3. ContractService
- Extracts per-item `netPrice` and `vatValue` from basket item's `getUnitPrice()` price object

### 4. PaymentContractInterface
- Added `getProviderRedirectUrl(): ?string` to interface (method existed on implementation)

## Files Modified

| File | Package |
|------|---------|
| `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php` | stripe |
| `src/Mcp/Acp/AcpResponseFormatter.php` | payment-component |
| `src/Service/ContractService.php` | payment-component |
| `src/Contract/PaymentContractInterface.php` | payment-component |
