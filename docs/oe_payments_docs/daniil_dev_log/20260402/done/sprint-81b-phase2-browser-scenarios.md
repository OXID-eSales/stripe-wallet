# Sprint 81b: Phase 2 — Browser Scenarios (k6 + Chromium)

**Parent:** Sprint 81 — High-Load Testing
**Date:** 2026-04-02
**Status:** done (code written, needs dry-run validation)

## Objective

Implement 5 load test scenarios using k6's browser module (Chromium). Each scenario replicates a Playwright e2e flow using the exact same selectors and test data from `stripe-test-cards.ts`.

## Principles

- **SRP** — Each exported function = one user journey
- **DRY** — Shared helpers extracted: `loginAndSetup()`, `addProductsToCart()`, `fillStripeCard()`, etc.
- **LSP** — All scenarios follow identical lifecycle: `browser.newPage()` → actions → metrics → `page.close()`
- **DIP** — Scenarios depend on helper functions, not raw selectors

## Test Data (from Playwright fixtures)

```
Cards:     4242424242424242 (success), 4000000000000002 (declined),
           4000000000003220 (3DS), 5200000000001096 (default fill)
Expiry:    03/33
CVC:       640
Name:      Test Stripe
Coupons:   E2E_10PCT, E2E_50PCT, E2E_5FLAT
Users:     loadtest_user_001..200@oxid-esales.dev / useruser
```

## Subtasks

### 2.1 — Happy Path Checkout (40% traffic)

| # | Task | Status | Details |
|---|------|--------|---------|
| 2.1.1 | Implement `loginAndSetup(page, user)` | done | Mirrors `Helper.setupLoggedInSession()`: navigate to account, fill `input[name="lgn_usr"]`, accept cookies, switch to English |
| 2.1.2 | Implement `addProductsToCart(page)` | done | Mirrors `Helper.goToAxlePartsFromMenu()` + `addFirstTwoProductsToCartFromList()`: hover Spare parts → click Axle parts → click 2x "To cart" buttons |
| 2.1.3 | Implement `navigateToCheckout(page)` | done | Mirrors `Helper.startCheckoutViaMiniBasket()`: click `button[data-bs-target="#basketModal"]` → click "Checkout" link in modal |
| 2.1.4 | Implement `selectStripeAndOrder(page)` | done | Mirrors `selectDigitalWalletPayment()` + `clickNext()` + `clickOrderNow()`: check `#payment_oe_payments_stripe_wallet`, click Next, click `#stripe-checkout-btn`, wait for `checkout.stripe.com` redirect |
| 2.1.5 | Implement `fillStripeCard(page, card)` | done | Mirrors `Helper.fillStripeCardDetailsWithCard()`: select Card radio, fill `#cardNumber`/`input[aria-label="Card number"]`, fill expiry, CVC, name |
| 2.1.6 | Implement `clickStripePay(page)` | done | Mirrors `Helper.clickPayOnStripeCheckout()`: click `[data-testid="hosted-payment-submit-button"]` |
| 2.1.7 | Implement `handle3DS(page, action)` | done | Mirrors `Helper.click3DSButton()`: Strategy 1: `iframe#challengeFrame` → `#test-source-authorize-3ds`. Strategy 2: search all frames for `three-ds-2-challenge` |
| 2.1.8 | Implement `verifyThankYou(page)` | done | Mirrors `Helper.assertThankYouPageAndGetOrderNumber()`: wait for URL `/thankyou`, find `#thankyouPage`, extract order number via regex `/order with number\s+(\d+)/` |
| 2.1.9 | Wire happy_path() scenario | done | Full flow: login → products → checkout → Stripe → card → pay → 3DS → thank you. Records `checkout_success_rate`, `checkout_duration`, `orders_created` |

**Playwright source:** `tests/e2e/Checkout.spec.ts`, `tests/checkout/stripe-checkout.spec.ts`

### 2.2 — Mid-Flow Cancellation (20% traffic)

| # | Task | Status | Details |
|---|------|--------|---------|
| 2.2.1 | Implement cancellation flow | done | Same as happy path up to Stripe Checkout page, then `page.goBack()` instead of paying |
| 2.2.2 | Verify return to shop | done | Assert `!page.url().includes('stripe.com')` after back navigation |
| 2.2.3 | Record `contract_state_valid` metric | done | Contract should be in cancellable state |

**Playwright source:** `tests/checkout/coupon-survives-back-navigation.spec.ts`

**What this tests under load:**
- Contract cleanup when user abandons at Stripe
- No orphan orders from abandoned checkouts
- Session/basket preservation after return

### 2.3 — Guest Checkout with Coupon (15% traffic)

| # | Task | Status | Details |
|---|------|--------|---------|
| 2.3.1 | Implement guest browse (no login) | done | Direct navigation to `/Spare-parts/Axle-parts/` |
| 2.3.2 | Add product to cart as guest | done | Click first "To cart" button on product list |
| 2.3.3 | Implement `applyCoupon(page, code)` | done | Navigate to cart, fill `input[name="voucherNr"]`, click submit |
| 2.3.4 | Navigate to user step | done | Click "Checkout" link → reach user registration page |
| 2.3.5 | Randomly pick coupon per iteration | done | `pickRandom([E2E_10PCT, E2E_50PCT, E2E_5FLAT])` |

**Playwright source:** `tests/e2e/GuestOrders.spec.ts`, `tests/e2e/CouponsDiscounts.spec.ts`

**Scope limitation:** Guest can't complete Stripe payment without filling the full address form. For load testing, reaching user step with coupon applied validates:
- Coupon application under concurrent load
- Basket/session handling for anonymous users
- No coupon race conditions (double-use detection)

### 2.4 — Payment Failure & Retry (15% traffic)

| # | Task | Status | Details |
|---|------|--------|---------|
| 2.4.1 | First attempt with declined card | done | Fill `4000000000000002`, click Pay, wait for error `[role="alert"]` |
| 2.4.2 | Retry with valid card | done | Fill `4242424242424242`, click Pay, handle 3DS, verify thank you |
| 2.4.3 | Record both attempts in metrics | done | First fail doesn't count against `checkout_success_rate`; retry success does |

**Playwright source:** `tests/e2e/PaymentEdgeCases.spec.ts`

**What this tests under load:**
- Stripe error display under concurrent requests
- Card form re-fill after failure (form state management)
- `oe_payments_idempotency` table integrity — no duplicate charges from retries
- Contract state recovery: failed → new contract on retry

### 2.5 — 3D Secure Authentication (10% traffic)

| # | Task | Status | Details |
|---|------|--------|---------|
| 2.5.1 | Pay with 3DS card | done | Fill `4000000000003220`, click Pay |
| 2.5.2 | Complete 3DS challenge | done | `handle3DS(page, 'Complete')` — click `#test-source-authorize-3ds` in nested iframe |
| 2.5.3 | Verify order created | done | `verifyThankYou(page)` extracts order number |

**Playwright source:** `tests/e2e/Checkout.spec.ts` (3DS tests)

**What this tests under load:**
- 3DS iframe rendering under concurrent requests
- Stripe webhook delivery delay with 3DS (takes longer than direct capture)
- Contract state transition timing: payment_authorized condition fulfillment

## Shared Helper Functions (DRY)

All helpers are in `tests/load/k6.config.js`. Selector-to-helper mapping:

| k6 Helper | Playwright Method | Key Selectors |
|-----------|-------------------|---------------|
| `loginAndSetup(page, user)` | `setupLoggedInSession()` | `input[name="lgn_usr"]`, `input[name="lgn_pwd"]`, cookie accept button |
| `addProductsToCart(page)` | `goToAxlePartsFromMenu()` + `addFirstTwoProductsToCartFromList()` | `#navigation .nav-item.has-subs`, `#productList button.btn.btn-highlight` |
| `navigateToCheckout(page)` | `startCheckoutViaMiniBasket()` | `button[data-bs-target="#basketModal"]`, `#basketModal a.btn.btn-highlight` |
| `selectStripeAndOrder(page)` | `selectDigitalWalletPayment()` + `clickNext()` + `clickOrderNow()` | `#payment_oe_payments_stripe_wallet`, `button:has-text("Next")`, `#stripe-checkout-btn` |
| `fillStripeCard(page, card, exp, cvc, name)` | `fillStripeCardDetailsWithCard()` | Card radio, `#cardNumber` / `input[aria-label="Card number"]`, `#cardExpiry`, `#cardCvc`, `#billingName` |
| `clickStripePay(page)` | `clickPayOnStripeCheckout()` | `[data-testid="hosted-payment-submit-button"]` |
| `handle3DS(page, action)` | `click3DSButton()` | `iframe#challengeFrame`, `#test-source-authorize-3ds` / `#test-source-fail-3ds` |
| `verifyThankYou(page)` | `assertThankYouPageAndGetOrderNumber()` | `#thankyouPage`, `h2.blockHead:has-text("Thank you")`, regex `/order with number\s+(\d+)/` |
| `applyCoupon(page, code)` | `applyCoupon()` | `input[name="voucherNr"]`, submit button |
| `getRandomUser()` | N/A (Playwright uses single user) | `loadtest_user_{001..200}@oxid-esales.dev` |
| `pickRandom(arr)` | N/A | Random coupon/card selection per iteration |

## Error Handling Pattern

Every scenario follows the same pattern:

```javascript
export async function scenario_name() {
  const page = await browser.newPage();
  try {
    // ... browser interactions ...
    checkoutSuccess.add(1);
    contractValid.add(1);
  } catch (e) {
    checkoutSuccess.add(0);
    stripeErrors.add(1);
    console.error(`[scenario] ${user.email}: ${e.message}`);
  } finally {
    await page.close();  // ALWAYS close — prevents browser leak
  }
}
```

## Known Risks

1. **100 concurrent Chromium instances** — each uses ~150-300MB RAM. At peak (100 VU/min, ~30s per flow), ~50 concurrent browsers = ~10-15GB RAM. Runner must have sufficient memory.
2. **Stripe rate limits** — 25 req/s in test mode. With browser flows (not API calls), effective rate is lower. Monitor for 429 responses.
3. **Selector brittleness** — if OXID theme or Stripe Checkout UI changes, selectors break. Same risk as Playwright e2e, same selectors.
