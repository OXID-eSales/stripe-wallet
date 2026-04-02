# Sprint 77: E2E Test Suite — Stripe Checkout Card Input Fix

**Date:** 2026-03-30
**Branch:** `b-7.4.x`
**Priority:** HIGH — Fixes 20 of 41 failures

---

## Problem

After the OXID shop redirects to `checkout.stripe.com`, the E2E tests try to fill in card details using:
```typescript
const cardNumber = this.page.locator('input[aria-label="Card number"]').first();
await cardNumber.waitFor({ state: 'visible', timeout: 20000 });
```

This times out because Stripe Checkout (hosted page) renders card inputs inside nested iframes. The locator searches the top-level frame only.

## Affected Methods in Helper.ts

1. `fillStripeCardDetails()` — line 1215
2. `fillStripeCardDetailsWithCard()` — line 2388

Both use `this.page.locator('input[aria-label="Card number"]').first()` which doesn't traverse iframes.

## Fix Plan

### Step 1: Analyze Stripe Checkout DOM

Stripe's hosted checkout page structure:
- Main page: `checkout.stripe.com/c/pay/cs_test_*`
- Card input is inside an iframe: `iframe[name*="__privateStripeFrame"]` or similar
- The actual input may use `id="cardNumber"` or `aria-label="Card number"` inside the iframe

Need to inspect the actual page in `--headed` mode to determine exact iframe structure.

### Step 2: Update Helper Methods

Add iframe frame-switching logic:
```typescript
// Wait for Stripe checkout page to load
await this.page.waitForURL(/checkout\.stripe\.com/, { timeout: 30000 });

// Find the iframe containing card inputs
const stripeFrame = this.page.frameLocator('iframe[name*="stripe"]')
  ?? this.page.frames().find(f => f.url().includes('stripe'));

// Use frame context for card input
const cardNumber = stripeFrame.locator('input[aria-label="Card number"]');
```

### Step 3: Fix StripeCheckoutPage.expectEmailPrefilled

Replace `waitForLoadState('networkidle')` with waiting for a specific element:
```typescript
// Instead of networkidle (never resolves on Stripe):
await this.page.waitForSelector('[data-testid="email-input"], .ReadOnlyFormField', { timeout: 30000 });
```

### Step 4: Verify

Run targeted tests:
```bash
npx playwright test tests/checkout/stripe-checkout.spec.ts --headed
npx playwright test tests/e2e/Checkout.spec.ts --headed
```

## Tests This Fixes (20)

- Checkout.spec.ts: Logged In Success, Logged In 3DS Fail, Guest 3DS Fail
- GuestOrders.spec.ts: Visa, Mastercard, Card Declined, Insufficient Funds, 3DS Fail, Expired Card, Incorrect CVC, Coupon+payment fails
- PaymentEdgeCases.spec.ts: Card Declined, Insufficient Funds, 3DS Success, 3DS Fail, Visa Success, Mastercard Success
- stripe-checkout.spec.ts: Complete checkout flow (networkidle fix)