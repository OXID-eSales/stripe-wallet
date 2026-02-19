# Sprint 3 Report: Fix Manual Capture Redirect Issue

**Date:** 2025-12-17
**Duration:** ~45 minutes
**Status:** DONE

---

## Summary

Fixed E2E test failure where manual capture mode checkout redirected to start page instead of thankyou page.

## Problem

In manual capture mode (`requires_capture`), no order was being created, causing OXID to redirect away from thankyou page.

## Root Cause

`handleRequiresCaptureStatus()` was not dispatching `PaymentAuthorizedEvent`, so the order creation flow was never triggered.

## Solution

1. Dispatch `PaymentAuthorizedEvent` in manual capture mode
2. Set `requiresCapture=true` in context
3. Skip OXPAID update for manual capture orders

## Files Changed

| Action | File |
|--------|------|
| Modified | `StripeCheckoutReturnHandler.php` |
| Modified | `StripeOrderCreationHandler.php` |

## Verification

- E2E test passing
- Event log confirms order creation
- OXPAID correctly skipped for manual capture
- PHPStan/PHPCS/PHPMD passing

## Impact

- Critical bug fix
- Manual capture mode now fully functional
- Orders created with OXPAID=NULL until capture

## Test Results

```
✓ 1 [chromium] › stripe-checkout.spec.ts › Complete checkout flow with Stripe Wallet (49.2s)
1 passed (49.2s)
```

