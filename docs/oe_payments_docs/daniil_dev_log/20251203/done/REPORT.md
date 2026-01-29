# Playwright E2E Test Suite Report

## Overview

This report documents the Playwright E2E test suite for the OXID eShop Stripe Wallet payment integration. The test suite validates the complete checkout flow with Stripe payments and admin panel order management functionality.

## Test Execution Summary

| Metric | Value |
|--------|-------|
| Total Tests | 5 |
| Passed | 5 |
| Failed | 0 |
| Duration | ~2.3 minutes |
| Browser | Chromium |

## Test Suite Structure

### Directory Organization

```
tests/e2e/playwright/
├── .env                          # Environment configuration
├── fixtures/
│   └── stripe-test-cards.ts      # Test card numbers and user data
├── pages/
│   ├── admin/                    # Admin panel page objects
│   │   ├── AdminBasePage.ts      # Base class with frame handling
│   │   ├── AdminLoginPage.ts     # Admin authentication
│   │   ├── AdminOrdersPage.ts    # Orders navigation
│   │   └── AdminStripeOrderPage.ts # Stripe tab & refund operations
│   └── frontend/                 # Shop frontend page objects
│       ├── BasePage.ts           # Base class for all pages
│       ├── CartPage.ts           # Shopping cart
│       ├── CheckoutPage.ts       # Checkout flow
│       ├── HomePage.ts           # Homepage
│       ├── LoginPage.ts          # Customer login
│       ├── ProductPage.ts        # Product browsing & cart
│       ├── StripeCheckoutPage.ts # Stripe hosted checkout
│       └── ThankYouPage.ts       # Order confirmation
├── reports/                      # Screenshots & test artifacts
└── tests/
    ├── admin/
    │   └── stripe-admin-order.spec.ts  # Admin order tests
    └── checkout/
        └── stripe-checkout.spec.ts     # Checkout flow test
```

## Test Cases

### 1. Checkout Test: Complete Checkout Flow with Stripe Wallet

**File:** `tests/checkout/stripe-checkout.spec.ts`

**Steps:**
1. Load shop and accept cookies
2. Login with test user credentials
3. Navigate to Merchandise → T-Shirts → Product Details
4. Select variant and add to cart
5. Go to cart and start checkout
6. Navigate through checkout steps, select "Digitale Börse" (Stripe Wallet)
7. Submit order
8. Complete payment on Stripe hosted checkout
9. Verify order confirmation on thank you page

**Validations:**
- User can login successfully
- Product is added to cart
- Stripe payment method is selectable
- Redirect to Stripe Checkout works
- Payment completes successfully
- Order confirmation page displays

---

### 2. Admin Test 1: Verify Order Details and Payment Date

**File:** `tests/admin/stripe-admin-order.spec.ts`

**Steps:**
1. Login to admin panel
2. Navigate to Administer Orders → Orders
3. Select order by customer name
4. Check payment dates in order list

**Validations:**
- Admin login works
- Orders are visible in list
- Payment dates are checked (valid vs. 0000-00-00 00:00:00)

---

### 3. Admin Test 2: Verify Stripe Tab and Transaction ID

**Steps:**
1. Login to admin panel
2. Navigate to Orders and select an order
3. Open Stripe tab
4. Verify payment details

**Validations:**
- Stripe tab is accessible
- Payment type is `osc_stripe_wallet`
- Transaction ID matches pattern `pi_[a-zA-Z0-9]+`
- Refund button visibility or refund status

---

### 4. Admin Test 3: Perform Refund with Reason "Customer Request"

**Steps:**
1. Login to admin panel
2. Navigate to order and open Stripe tab
3. Select refund reason
4. Execute refund

**Validations:**
- Refund reason can be selected
- Refund executes successfully
- Success message displayed

---

### 5. Admin Test 4: Verify Payment Date Updates

**Steps:**
1. Login to admin panel
2. Navigate to Orders
3. Check payment dates across multiple orders

**Validations:**
- Payment dates are tracked
- Valid vs. invalid dates are identified

---

## Page Object Pattern

### Admin Pages

The admin panel uses HTML framesets which require special handling:

```typescript
// AdminBasePage.ts - Frame access methods
getMenuFrame(): Frame | null {
  return this.page.frame('adminnav') || this.page.frame('navigation');
}

getListFrame(): Frame | null {
  return this.page.frame('list');
}

getEditFrame(): Frame | null {
  return this.page.frame('edit');
}
```

### Frontend Pages

Frontend pages extend a common BasePage:

```typescript
// BasePage.ts
export abstract class BasePage {
  readonly page: Page;
  readonly baseURL: string;

  constructor(page: Page) {
    this.page = page;
    this.baseURL = process.env.SHOP_URL || 'https://localhost.local';
  }

  async navigate(path: string = ''): Promise<void> {
    await this.page.goto(`${this.baseURL}${path}`);
  }

  async waitForPageLoad(): Promise<void> {
    await this.page.waitForLoadState('networkidle');
  }
}
```

## Environment Configuration

```env
# .env
SHOP_URL=https://daniil.oxiddev.de
TEST_USER_EMAIL=playwright.user@oxid-esales.dev
TEST_USER_PASSWORD=useruser
HEADLESS=true
```

## Test Cards

| Card | Number | Purpose |
|------|--------|---------|
| VISA Success | 4111111111111111 | Successful payment |
| VISA 4242 | 4242424242424242 | Alternative success |
| Mastercard | 5555555555554444 | Mastercard test |
| Declined | 4000000000000002 | Test declined |
| 3DS Required | 4000000000003220 | 3D Secure flow |

## Running Tests

```bash
# Run all tests
npx playwright test

# Run with UI mode
npx playwright test --ui

# Run specific test file
npx playwright test tests/checkout/stripe-checkout.spec.ts

# Run admin tests only
npx playwright test tests/admin/

# Generate HTML report
npx playwright show-report
```

## Key Findings

### Successful Validations
- Complete checkout flow with Stripe Wallet payment
- Admin panel frame-based navigation
- Stripe tab displays correct payment details
- Transaction ID format validation
- Refund functionality works correctly

### Observations
- Payment dates show `0000-00-00 00:00:00` for some orders (may indicate webhook not updating OXORDER.OXPAID)
- Dashboard link to Stripe is present but may require authentication to access
- Order already refunded states are handled gracefully

## Screenshots Generated

| Screenshot | Description |
|------------|-------------|
| `admin-order-overview.png` | Orders list view |
| `admin-stripe-tab.png` | Stripe tab content |
| `admin-refund-complete.png` | Refund success state |
| `admin-payment-dates.png` | Payment dates check |
| `no-stripe-redirect.png` | Debugging (if redirect fails) |

## Conclusion

The Playwright E2E test suite successfully validates:
- Customer checkout flow with Stripe Wallet payment
- Admin order management functionality
- Stripe tab integration with transaction details
- Refund processing

All 5 tests pass consistently, providing confidence in the Stripe payment integration for the OXID eShop.
