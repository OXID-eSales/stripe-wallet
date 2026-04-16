# Sprint 89: Preserve Active Language and Shop in Stripe Return URL

**Date:** 2026-04-16
**Branch:** `b-7.4.x`
**Ticket:** STRP-124

## Core Requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Sprint 89a: failing tests before production code |
| **SOLID / SRP** | URL building stays in `CheckoutSessionService` + `StripeCheckoutSessionHandler`, context retrieval stays in controller/helper |
| **DRY** | One URL builder method handles both `lang=` and `shp=` for success + cancel URLs |
| **Liskov** | Parameters passed via event context — any controller can set them |
| **Clean Code** | Early returns, no magic numbers, no hardcoded `lang=0` or `shp=1` |
| **No overengineering** | Append `&lang={id}&shp={id}` to 2 URLs. No language detection heuristics, no cookie/header parsing. |

## Problem

When a customer returns from Stripe Checkout to the shop:
1. **Language lost** — URL defaults to `lang=0` (German) regardless of the customer's active language
2. **Shop lost** — In multi-shop setups, `shp=` parameter is missing, causing the return to land on the default shop instead of the sub-shop the customer was using

Both `successUrl` and `cancelUrl` built by the module omit these OXID-essential context parameters.

**Current URLs:**
```
Success: https://shop.example.com/index.php?cl=order&fnc=checkoutSuccess&session_id=...&contract_id=...
Cancel:  https://shop.example.com/index.php?cl=order&fnc=checkoutCancel
```

**Expected URLs (multi-shop, English):**
```
Success: https://shop.example.com/index.php?cl=order&fnc=checkoutSuccess&lang=1&shp=2&session_id=...&contract_id=...
Cancel:  https://shop.example.com/index.php?cl=order&fnc=checkoutCancel&lang=1&shp=2
```

Where `lang=1` = English, `shp=2` = sub-shop #2.

## Root Cause

Two URL construction points lack both `lang=` and `shp=` parameters:

| Location | Method | Line | Missing |
|----------|--------|------|---------|
| `CheckoutSessionService.php` | `buildSuccessUrl()` | ~210 | `lang=`, `shp=` |
| `StripeCheckoutSessionHandler.php` | `buildCheckoutParams()` → cancel URL | ~146 | `lang=`, `shp=` |

Neither reads the active language/shop from OXID or passes them through the event context.

## What Already Exists

| Component | Method | Status |
|-----------|--------|--------|
| `OxidShopAdapter` | `getCurrentLanguageAbbr(): string` | Returns `'de'`, `'en'`, etc. |
| `OxidShopAdapter` | `getLanguageIdByAbbr(string): ?int` | Maps `'en'` → `1` (private) |
| `Registry::getLang()` | `getBaseLanguage(): int` | Returns active language ID (0, 1, ...) |
| Event context | `'shopUrl'` | Already passed through context |

## Implementation Plan

### What Changes

| File | Change |
|------|--------|
| `CheckoutSessionService::buildSuccessUrl()` | Accept `int $languageId`, `int $shopId` params, append `&lang={id}&shp={id}` |
| `StripeCheckoutSessionHandler::buildCheckoutParams()` | Read language + shop from context, pass to `buildSuccessUrl()`, append to cancel URL |
| `StripeOrderController::execute()` | Pass `'languageId'` and `'shopId'` in event context |
| `ControllerRequestHelper` | Add `getActiveLanguageId(): int` method |

### What Does NOT Change

- `OxidShopAdapter` — already has `getShopId()` and language methods
- Stripe adapter / API calls — Stripe doesn't care about `lang`/`shp`, they're OXID-only
- Event system / handler chain — just extra context values
- Payment-component — no changes

### Flow

```
1. User on English sub-shop → clicks "Pay with Stripe"
2. StripeOrderController::execute()
   → context['languageId'] = Registry::getLang()->getBaseLanguage()  // = 1
   → context['shopId'] = already passed (from helper.getShopId())
3. StripeCheckoutSessionHandler::buildCheckoutParams()
   → reads context['languageId'] (default: 0) and context['shopId'] (default: '1')
   → passes to buildSuccessUrl(..., $languageId, $shopId)
   → appends &lang=1&shp=2 to cancelUrl
4. User completes payment on Stripe
5. Stripe redirects to: ...?cl=order&fnc=checkoutSuccess&lang=1&shp=2&session_id=...
6. OXID reads lang=1, shp=2 → renders English thank-you page on correct sub-shop
```

## TDD Plan

### Phase 1: RED — Failing Tests

**Unit: `CheckoutSessionServiceTest`**
```
Test: testBuildSuccessUrlIncludesLanguageAndShopParameters()
  Arrange: languageId = 1, shopId = 2
  Act: buildSuccessUrl($shopUrl, $contractId, $token, $sessionId, 1, 2)
  Assert: URL contains '&lang=1' and '&shp=2'

Test: testBuildSuccessUrlDefaultsToZeroLanguageAndShopOne()
  Arrange: no languageId, no shopId (defaults)
  Act: buildSuccessUrl($shopUrl, $contractId, $token, $sessionId)
  Assert: URL contains '&lang=0' and '&shp=1'
```

**Unit: `StripeCheckoutSessionHandlerTest`**
```
Test: testBuildCheckoutParamsIncludesLanguageAndShopInUrls()
  Arrange: context with languageId = 1, shopId = '2'
  Act: handler builds params
  Assert: successUrl contains '&lang=1&shp=2', cancelUrl contains '&lang=1&shp=2'
```

### Phase 2: GREEN — Implementation

1. Add `getActiveLanguageId(): int` to `ControllerRequestHelper`
2. Pass `'languageId'` in `StripeOrderController::execute()` context (shopId already passed)
3. Update `buildSuccessUrl()` signature: add `int $languageId = 0, string $shopId = '1'`
4. Append `&lang={id}&shp={id}` in `buildSuccessUrl()`
5. Append `&lang={id}&shp={id}` to cancel URL in `buildCheckoutParams()`

### Phase 3: REFACTOR

- `./bin/pre-commit-check.sh --full`
- Verify existing tests still pass (backward compatible — default `lang=0`)

## Sub-Sprints

| Sprint | Description | Status |
|--------|-------------|--------|
| 89a | RED — Failing tests for language in URLs | todo |
| 89b | GREEN — Implement language param in success + cancel URLs | todo |
| 89c | REFACTOR — Pre-commit + manual verification | todo |

## Out of Scope

- Stripe Checkout page language (controlled by Stripe via `locale` param — separate feature)
- SEO-friendly URLs (`/en/checkout/` style) — OXID handles this via `lang` param internally
- Shop-specific Stripe API keys per sub-shop (separate configuration feature)
- Inherited sub-shop settings — OXID handles this transparently via `shp=`
