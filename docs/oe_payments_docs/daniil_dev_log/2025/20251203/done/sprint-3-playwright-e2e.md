# Sprint 3: Playwright E2E Tests Setup

**Priority:** MEDIUM
**Estimated Scope:** Infrastructure + Core Tests
**Status:** PLANNED
**Continues From:** `../20251202/todo/sprint-5-playwright-e2e-setup.md`

---

## Objective

Implement the Playwright E2E testing infrastructure designed yesterday. Focus on getting the critical checkout flow working first.

---

Use this as an example of general plan and login/password datasouorce
/home/oxidshop/osc/strpwt7-nov26/e2e-agent/demo/_generated_prob_654/paypal-payment-test.spec.ts
This is from previous version for paypal now adapt it for stripe. 

Use https://daniil.oxddev.de/ as the test shop URL.
Switch to english
Then login 
Then add product to cart
Then go to checkout
Then select stripe payment
Then click pay with stripe button
Then fill in stripe test card details
Then submit payment
Then verify thank you page is shown



## TDD Approach for E2E

```
┌─────────────────────────────────────────────────────────────────┐
│  E2E TDD CYCLE                                                  │
│                                                                 │
│  1. DEFINE  → Write test scenario (spec file)                   │
│  2. CREATE  → Build page objects needed by test                 │
│  3. EXECUTE → Run test, expect failure                          │
│  4. FIX     → Fix page objects/selectors                        │
│  5. VERIFY  → Test passes, commit                               │
│                                                                 │
│  Focus on critical paths first!                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: Directory Setup

### 1.1 Create Directory Structure

```bash
# Execute from project root
mkdir -p source/extensions/stripe/tests/e2e/playwright/{tests/{checkout,admin,webhooks},pages,helpers,fixtures,reports}
```

### 1.2 Files to Create

| File | Priority | Status |
|------|----------|--------|
| `playwright.config.ts` | HIGH | TODO |
| `tsconfig.json` | HIGH | TODO |
| `package.json` | HIGH | TODO |
| `.env.example` | HIGH | TODO |
| `.gitignore` | HIGH | TODO |
| `pages/BasePage.ts` | HIGH | TODO |
| `pages/CheckoutPage.ts` | HIGH | TODO |
| `pages/StripeCheckoutPage.ts` | HIGH | TODO |
| `pages/ThankYouPage.ts` | HIGH | TODO |
| `fixtures/stripe-test-cards.ts` | HIGH | TODO |
| `tests/checkout/stripe-checkout.spec.ts` | HIGH | TODO |

---

## Phase 2: Core Configuration

### 2.1 playwright.config.ts

```typescript
import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(__dirname, '.env') });

export default defineConfig({
  testDir: './tests',
  testMatch: '**/*.spec.ts',
  timeout: 60000,
  expect: { timeout: 10000 },

  // Sequential for payment tests (avoid race conditions)
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 2 : 0,

  reporter: [
    ['html', { outputFolder: './reports/html-report', open: 'never' }],
    ['list'],
  ],

  use: {
    baseURL: process.env.SHOP_URL || 'https://daniil.oxiddev.de',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15000,
    navigationTimeout: 30000,
    viewport: { width: 1280, height: 720 },
    ignoreHTTPSErrors: true,
    locale: 'de-DE',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  outputDir: './reports/test-results',
});
```

### 2.2 package.json

```json
{
  "name": "stripe-e2e-tests",
  "version": "1.0.0",
  "scripts": {
    "test": "playwright test",
    "test:headed": "playwright test --headed",
    "test:debug": "playwright test --debug",
    "test:checkout": "playwright test tests/checkout/",
    "report": "playwright show-report reports/html-report",
    "install-browsers": "playwright install --with-deps chromium"
  },
  "devDependencies": {
    "@playwright/test": "^1.56.1",
    "@types/node": "^24.10.1",
    "dotenv": "^16.5.0",
    "typescript": "^5.9.3"
  }
}
```

### 2.3 .env.example

```bash
SHOP_URL=https://daniil.oxiddev.de
TEST_USER_EMAIL=playwright.user@oxid-esales.dev
TEST_USER_PASSWORD=testpassword123
HEADLESS=true
```

---

## Phase 3: Page Objects

### 3.1 BasePage.ts

```typescript
import { Page } from '@playwright/test';

export abstract class BasePage {
  readonly page: Page;
  readonly baseURL: string;

  constructor(page: Page) {
    this.page = page;
    this.baseURL = process.env.SHOP_URL || 'https://daniil.oxiddev.de';
  }

  async navigate(path: string = ''): Promise<void> {
    await this.page.goto(`${this.baseURL}${path}`);
  }

  async waitForPageLoad(): Promise<void> {
    await this.page.waitForLoadState('networkidle');
  }

  async acceptCookies(): Promise<void> {
    const cookieButton = this.page.locator('[data-testid="cookie-accept"], .cookie-accept, #cookie-accept');
    if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cookieButton.click();
    }
  }
}
```

### 3.2 CheckoutPage.ts

```typescript
import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class CheckoutPage extends BasePage {
  readonly paymentMethodStripe: Locator;
  readonly stripePayButton: Locator;
  readonly orderButton: Locator;

  constructor(page: Page) {
    super(page);
    // Locators need to be determined by inspecting actual shop
    this.paymentMethodStripe = page.locator('input[value*="stripe"], #payment_stripe_card');
    this.stripePayButton = page.locator('[data-stripe-checkout], .stripe-pay-button');
    this.orderButton = page.locator('#orderConfirmAgbBottom, .submitOrder');
  }

  async selectStripePayment(): Promise<void> {
    await this.paymentMethodStripe.check();
    await this.waitForPageLoad();
  }

  async clickPayWithStripe(): Promise<void> {
    await this.stripePayButton.click();
  }

  async waitForStripeRedirect(): Promise<void> {
    await this.page.waitForURL(/checkout\.stripe\.com/, { timeout: 30000 });
  }
}
```

### 3.3 StripeCheckoutPage.ts

```typescript
import { Page, Locator } from '@playwright/test';

export class StripeCheckoutPage {
  readonly page: Page;
  readonly emailInput: Locator;
  readonly cardNumberInput: Locator;
  readonly expiryInput: Locator;
  readonly cvcInput: Locator;
  readonly payButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.emailInput = page.locator('#email');
    this.cardNumberInput = page.locator('#cardNumber');
    this.expiryInput = page.locator('#cardExpiry');
    this.cvcInput = page.locator('#cardCvc');
    this.payButton = page.locator('.SubmitButton');
  }

  async fillTestCard(cardNumber: string = '4242424242424242'): Promise<void> {
    await this.cardNumberInput.fill(cardNumber);
    await this.expiryInput.fill('12/30');
    await this.cvcInput.fill('123');
  }

  async fillEmail(email: string): Promise<void> {
    if (await this.emailInput.isVisible()) {
      await this.emailInput.fill(email);
    }
  }

  async submitPayment(): Promise<void> {
    await this.payButton.click();
  }

  async completePayment(email: string, cardNumber: string = '4242424242424242'): Promise<void> {
    await this.fillEmail(email);
    await this.fillTestCard(cardNumber);
    await this.submitPayment();
  }

  async waitForRedirectBack(shopUrl: string): Promise<void> {
    const escapedUrl = shopUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    await this.page.waitForURL(new RegExp(escapedUrl), { timeout: 60000 });
  }
}
```

### 3.4 ThankYouPage.ts

```typescript
import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class ThankYouPage extends BasePage {
  readonly orderConfirmation: Locator;
  readonly orderNumber: Locator;
  readonly thankYouMessage: Locator;

  constructor(page: Page) {
    super(page);
    // Locators need to be determined by inspecting actual shop
    this.orderConfirmation = page.locator('.thankyou, .order-confirmation, h1:has-text("Vielen Dank")');
    this.orderNumber = page.locator('.order-number, .ordernr, [data-order-number]');
    this.thankYouMessage = page.locator('.thankyou-message, .confirmation-message');
  }

  async getOrderNumber(): Promise<string> {
    const text = await this.orderNumber.textContent() || '';
    const match = text.match(/\d+/);
    return match ? match[0] : '';
  }

  async verifyOrderConfirmation(): Promise<void> {
    await expect(this.orderConfirmation).toBeVisible({ timeout: 30000 });
  }
}
```

---

## Phase 4: Test Fixtures

### 4.1 stripe-test-cards.ts

```typescript
export const STRIPE_TEST_CARDS = {
  // Success
  VISA_SUCCESS: '4242424242424242',
  MASTERCARD_SUCCESS: '5555555555554444',

  // Declined
  CARD_DECLINED: '4000000000000002',
  INSUFFICIENT_FUNDS: '4000000000009995',

  // 3D Secure
  REQUIRES_3DS: '4000000000003220',
};

export const TEST_CARD_DETAILS = {
  EXPIRY: '12/30',
  CVC: '123',
};
```

---

## Phase 5: Core Test Scenarios

### 5.1 stripe-checkout.spec.ts

```typescript
import { test, expect } from '@playwright/test';
import { CheckoutPage } from '../../pages/CheckoutPage';
import { StripeCheckoutPage } from '../../pages/StripeCheckoutPage';
import { ThankYouPage } from '../../pages/ThankYouPage';
import { STRIPE_TEST_CARDS } from '../../fixtures/stripe-test-cards';

test.describe('Stripe Checkout Flow', () => {

  test.beforeEach(async ({ page }) => {
    // Navigate to shop and accept cookies
    await page.goto(process.env.SHOP_URL || 'https://daniil.oxiddev.de');

    // Accept cookies if present
    const cookieButton = page.locator('[data-testid="cookie-accept"], .cookie-accept');
    if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cookieButton.click();
    }
  });

  test('should display Stripe payment option on checkout', async ({ page }) => {
    // Navigate to checkout (requires product in cart)
    // This test verifies Stripe is visible as payment option

    // TODO: Add product to cart first
    // TODO: Navigate to checkout step 3 (payment)
    // TODO: Verify Stripe option is visible

    await expect(page.locator('body')).toBeVisible();
  });

  test('should redirect to Stripe Checkout on payment click', async ({ page }) => {
    // TODO: Full checkout flow setup
    // TODO: Click Stripe payment button
    // TODO: Verify redirect to checkout.stripe.com

    await expect(page.locator('body')).toBeVisible();
  });

  test('should complete checkout with valid card', async ({ page }) => {
    // Full E2E flow:
    // 1. Add product to cart
    // 2. Go to checkout
    // 3. Fill address (if not logged in)
    // 4. Select Stripe payment
    // 5. Click pay button
    // 6. Fill card on Stripe page
    // 7. Submit payment
    // 8. Verify thank you page

    // TODO: Implement full flow

    await expect(page.locator('body')).toBeVisible();
  });

  test('should handle declined card', async ({ page }) => {
    // Similar to success flow but use CARD_DECLINED
    // Verify error message on Stripe page

    await expect(page.locator('body')).toBeVisible();
  });
});
```

---

## Phase 6: Runner Script

### 6.1 bin/test_e2e_run.sh

```bash
#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="$SCRIPT_DIR/../tests/e2e/playwright"

echo "========================================"
echo "  Stripe E2E Test Runner"
echo "========================================"

cd "$E2E_DIR"

# Install if needed
if [ ! -d "node_modules" ]; then
  echo "Installing dependencies..."
  npm install
  npx playwright install chromium
fi

# Run tests
npx playwright test "$@"
```

---

## Implementation Checklist

### Phase 1: Setup (30 min)
- [ ] Create directory structure
- [ ] Create `package.json`
- [ ] Create `tsconfig.json`
- [ ] Create `playwright.config.ts`
- [ ] Create `.env.example`
- [ ] Run `npm install`
- [ ] Run `playwright install chromium`

### Phase 2: Page Objects (45 min)
- [ ] Create `BasePage.ts`
- [ ] Create `CheckoutPage.ts`
- [ ] Create `StripeCheckoutPage.ts`
- [ ] Create `ThankYouPage.ts`

### Phase 3: Fixtures (15 min)
- [ ] Create `stripe-test-cards.ts`

### Phase 4: Tests (1 hour)
- [ ] Create `stripe-checkout.spec.ts`
- [ ] Run initial test (expect failures)
- [ ] Fix locators based on actual shop DOM
- [ ] Get at least one test passing

### Phase 5: Integration (15 min)
- [ ] Create `test_e2e_run.sh`
- [ ] Test runner script works

---

## Test Execution Commands

```bash
# From host machine
cd source/extensions/stripe/tests/e2e/playwright

# Install dependencies
npm install
npx playwright install chromium

# Run all tests
npm test

# Run headed (with browser visible)
npm run test:headed

# Run debug mode
npm run test:debug

# Run only checkout tests
npm run test:checkout

# View report
npm run report
```

---

## Critical Path Focus

### Priority Order
1. **Get infrastructure working** - npm install, playwright install
2. **Create minimal page objects** - BasePage, CheckoutPage
3. **Write one simple test** - Just verify shop loads
4. **Expand test** - Add Stripe checkout flow
5. **Fix selectors** - Based on actual shop DOM inspection

### What NOT to do
- Don't create all page objects upfront
- Don't write complex tests before simple ones pass
- Don't add multiple browser projects initially
- Don't add CI/CD config until local tests work

---

## Definition of Done

- [ ] Directory structure created
- [ ] npm install succeeds
- [ ] Playwright browsers installed
- [ ] At least 1 test runs (even if skeleton)
- [ ] Runner script works
- [ ] Pre-commit-check.sh passes (for PHP code)
- [ ] Move `todo/sprint-3-playwright-e2e.md` → `done/sprint-3-playwright-e2e.md`
- [ ] Create `done/sprint-3-playwright-e2e-REPORT.md`
- [ ] status.md updated
