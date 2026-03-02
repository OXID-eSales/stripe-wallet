# Sprint 5: Playwright E2E Test Setup - Implementation Plan

**Date:** December 2, 2025
**Status:** PLANNED
**Priority:** High
**Estimated Effort:** 3-4 hours

---

## Objective

Set up a complete Playwright E2E testing infrastructure for the Stripe payment module with TypeScript support, following the patterns established in the `e2e-agent` project.

---

## Current State Analysis

### Existing Test Infrastructure

| Component | Status | Location |
|-----------|--------|----------|
| PHPUnit Tests | EXISTS | `tests/Unit/`, `tests/Integration/` |
| Pre-commit Script | EXISTS | `bin/pre-commit-check.sh` |
| Makefile | EXISTS | `Makefile` |
| package.json | EXISTS | Frontend build only (esbuild) |
| E2E Tests | MISSING | Need to create `tests/e2e/playwright/` |

### Reference Implementation

The `e2e-agent` project at `/home/dtkachev/osc/strpwt7-nov26/e2e-agent/` provides excellent patterns:
- TypeScript configuration
- Playwright setup
- Test organization
- Reporting configuration

---

## Architecture Design

### Directory Structure

```
source/extensions/stripe/
├── tests/
│   ├── e2e/
│   │   └── playwright/
│   │       ├── playwright.config.ts      # Playwright configuration
│   │       ├── tsconfig.json             # TypeScript config for e2e
│   │       ├── package.json              # E2E-specific dependencies
│   │       ├── .env.example              # Environment variables template
│   │       ├── tests/
│   │       │   ├── checkout/
│   │       │   │   ├── stripe-checkout.spec.ts
│   │       │   │   ├── payment-element.spec.ts
│   │       │   │   └── order-confirmation.spec.ts
│   │       │   ├── admin/
│   │       │   │   ├── refund.spec.ts
│   │       │   │   └── configuration.spec.ts
│   │       │   ├── webhooks/
│   │       │   │   └── webhook-handling.spec.ts
│   │       │   └── fixtures/
│   │       │       ├── test-products.ts
│   │       │       ├── test-users.ts
│   │       │       └── stripe-test-cards.ts
│   │       ├── helpers/
│   │       │   ├── shop-helpers.ts       # OXID shop interactions
│   │       │   ├── stripe-helpers.ts     # Stripe-specific helpers
│   │       │   ├── admin-helpers.ts      # Admin panel helpers
│   │       │   └── api-helpers.ts        # API interaction helpers
│   │       ├── pages/
│   │       │   ├── BasePage.ts           # Base page object
│   │       │   ├── HomePage.ts
│   │       │   ├── ProductPage.ts
│   │       │   ├── CartPage.ts
│   │       │   ├── CheckoutPage.ts
│   │       │   ├── PaymentPage.ts
│   │       │   ├── ThankYouPage.ts
│   │       │   └── admin/
│   │       │       ├── AdminLoginPage.ts
│   │       │       ├── OrderListPage.ts
│   │       │       └── OrderRefundPage.ts
│   │       └── reports/
│   │           └── .gitkeep
│   ├── Unit/                             # Existing PHP unit tests
│   └── Integration/                      # Existing PHP integration tests
├── bin/
│   ├── pre-commit-check.sh               # Existing
│   └── test_e2e_run.sh                   # NEW - E2E test runner
└── package.json                          # Update with e2e scripts
```

---

## Configuration Files

### 1. playwright.config.ts

```typescript
import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

// Load environment variables
dotenv.config({ path: path.resolve(__dirname, '.env') });

export default defineConfig({
  // Test directory
  testDir: './tests',
  testMatch: '**/*.spec.ts',

  // Timeouts
  timeout: 60000,
  expect: {
    timeout: 10000,
  },

  // Parallel execution
  fullyParallel: false,  // Sequential for payment tests
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : 1,

  // Reporters
  reporter: [
    ['html', { outputFolder: './reports/html-report', open: 'never' }],
    ['list'],
    ['junit', { outputFile: './reports/junit-results.xml' }],
  ],

  // Global setup/teardown
  globalSetup: require.resolve('./helpers/global-setup.ts'),
  globalTeardown: require.resolve('./helpers/global-teardown.ts'),

  // Shared settings
  use: {
    // Base URL from environment
    baseURL: process.env.SHOP_URL || 'https://daniil.oxiddev.de',

    // Tracing and screenshots
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',

    // Timeouts
    actionTimeout: 15000,
    navigationTimeout: 30000,

    // Browser context options
    viewport: { width: 1280, height: 720 },
    ignoreHTTPSErrors: true,

    // Locale
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
  },

  // Browser projects
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
    // Mobile testing
    {
      name: 'mobile-chrome',
      use: { ...devices['Pixel 5'] },
    },
  ],

  // Output directory
  outputDir: './reports/test-results',

  // Web server (optional - for local testing)
  // webServer: {
  //   command: 'docker compose up',
  //   url: 'https://daniil.oxiddev.de',
  //   reuseExistingServer: !process.env.CI,
  // },
});
```

### 2. tsconfig.json

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "commonjs",
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "outDir": "./dist",
    "rootDir": ".",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "resolveJsonModule": true,
    "moduleResolution": "node",
    "declaration": true,
    "declarationMap": true,
    "sourceMap": true,
    "noImplicitAny": true,
    "noImplicitReturns": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "strictNullChecks": true,
    "types": ["node", "@playwright/test"]
  },
  "include": [
    "**/*.ts"
  ],
  "exclude": [
    "node_modules",
    "dist",
    "reports"
  ]
}
```

### 3. package.json (E2E specific)

```json
{
  "name": "stripe-e2e-tests",
  "version": "1.0.0",
  "description": "Playwright E2E tests for OXID Stripe Payment Module",
  "scripts": {
    "test": "playwright test",
    "test:headed": "playwright test --headed",
    "test:debug": "playwright test --debug",
    "test:ui": "playwright test --ui",
    "test:chromium": "playwright test --project=chromium",
    "test:firefox": "playwright test --project=firefox",
    "test:webkit": "playwright test --project=webkit",
    "test:mobile": "playwright test --project=mobile-chrome",
    "test:checkout": "playwright test tests/checkout/",
    "test:admin": "playwright test tests/admin/",
    "test:webhooks": "playwright test tests/webhooks/",
    "report": "playwright show-report reports/html-report",
    "codegen": "playwright codegen",
    "install-browsers": "playwright install --with-deps"
  },
  "devDependencies": {
    "@playwright/test": "^1.56.1",
    "@types/node": "^24.10.1",
    "dotenv": "^16.5.0",
    "typescript": "^5.9.3"
  },
  "engines": {
    "node": ">=18.0.0"
  }
}
```

### 4. .env.example

```bash
# Shop Configuration
SHOP_URL=https://daniil.oxiddev.de
SHOP_ADMIN_URL=https://daniil.oxiddev.de/admin

# Admin Credentials
ADMIN_USERNAME=admin
ADMIN_PASSWORD=admin

# Test User Credentials
TEST_USER_EMAIL=playwright.user@oxid-esales.dev
TEST_USER_PASSWORD=testpassword123

# Stripe Test Keys (for API verification)
STRIPE_PUBLISHABLE_KEY=pk_test_xxx
STRIPE_SECRET_KEY=sk_test_xxx

# Test Configuration
HEADLESS=true
SLOW_MO=0
TIMEOUT=60000

# CI/CD
CI=false
```

---

## Page Object Model

### BasePage.ts

```typescript
import { Page, Locator, expect } from '@playwright/test';

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
    const cookieButton = this.page.locator('[data-testid="cookie-accept"]');
    if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cookieButton.click();
    }
  }

  async getNotificationMessage(): Promise<string> {
    const notification = this.page.locator('.alert-message, .notification');
    return await notification.textContent() || '';
  }
}
```

### CheckoutPage.ts

```typescript
import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class CheckoutPage extends BasePage {
  // Locators
  readonly paymentMethodStripe: Locator;
  readonly stripePayButton: Locator;
  readonly orderSummary: Locator;
  readonly totalAmount: Locator;

  constructor(page: Page) {
    super(page);
    this.paymentMethodStripe = page.locator('input[value*="stripe"]');
    this.stripePayButton = page.locator('[data-stripe-checkout], .stripe-pay-button');
    this.orderSummary = page.locator('.order-summary, #orderSummary');
    this.totalAmount = page.locator('.total-amount, .grand-total');
  }

  async selectStripePayment(): Promise<void> {
    await this.paymentMethodStripe.check();
    await this.waitForPageLoad();
  }

  async clickPayWithStripe(): Promise<void> {
    await this.stripePayButton.click();
  }

  async getTotal(): Promise<string> {
    return await this.totalAmount.textContent() || '';
  }

  async waitForStripeRedirect(): Promise<void> {
    await this.page.waitForURL(/checkout\.stripe\.com/, { timeout: 30000 });
  }
}
```

### StripeCheckoutPage.ts

```typescript
import { Page, Locator, expect, FrameLocator } from '@playwright/test';

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
    // Fill card details
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
    await this.page.waitForURL(new RegExp(shopUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), {
      timeout: 60000
    });
  }
}
```

---

## Test Examples

### stripe-checkout.spec.ts

```typescript
import { test, expect } from '@playwright/test';
import { HomePage } from '../pages/HomePage';
import { ProductPage } from '../pages/ProductPage';
import { CartPage } from '../pages/CartPage';
import { CheckoutPage } from '../pages/CheckoutPage';
import { StripeCheckoutPage } from '../pages/StripeCheckoutPage';
import { ThankYouPage } from '../pages/ThankYouPage';
import { STRIPE_TEST_CARDS } from '../fixtures/stripe-test-cards';

test.describe('Stripe Checkout Flow', () => {
  test.beforeEach(async ({ page }) => {
    const homePage = new HomePage(page);
    await homePage.navigate();
    await homePage.acceptCookies();
  });

  test('should complete checkout with Stripe using valid card', async ({ page }) => {
    // 1. Add product to cart
    const productPage = new ProductPage(page);
    await productPage.navigate('/Kiteboarding/Kiteboards/Kiteboard-CABRINHA-CALIBER-2011.html');
    await productPage.addToCart();

    // 2. Go to cart and proceed to checkout
    const cartPage = new CartPage(page);
    await cartPage.navigate();
    await cartPage.proceedToCheckout();

    // 3. Complete checkout steps (address, shipping)
    const checkoutPage = new CheckoutPage(page);
    await checkoutPage.selectStripePayment();
    await checkoutPage.clickPayWithStripe();

    // 4. Wait for Stripe redirect
    await checkoutPage.waitForStripeRedirect();

    // 5. Complete payment on Stripe
    const stripePage = new StripeCheckoutPage(page);
    await stripePage.completePayment(
      process.env.TEST_USER_EMAIL!,
      STRIPE_TEST_CARDS.VISA_SUCCESS
    );

    // 6. Wait for redirect back to shop
    await stripePage.waitForRedirectBack(process.env.SHOP_URL!);

    // 7. Verify thank you page
    const thankYouPage = new ThankYouPage(page);
    await expect(thankYouPage.orderConfirmation).toBeVisible();
    await expect(thankYouPage.orderNumber).toBeVisible();

    // 8. Verify order number format
    const orderNumber = await thankYouPage.getOrderNumber();
    expect(orderNumber).toMatch(/^\d+$/);
  });

  test('should handle declined card gracefully', async ({ page }) => {
    // Setup and navigate to Stripe
    // ... (similar steps as above)

    const stripePage = new StripeCheckoutPage(page);
    await stripePage.completePayment(
      process.env.TEST_USER_EMAIL!,
      STRIPE_TEST_CARDS.CARD_DECLINED
    );

    // Verify error message on Stripe
    await expect(page.locator('.ErrorMessage')).toBeVisible();
    await expect(page.locator('.ErrorMessage')).toContainText(/declined/i);
  });

  test('should handle 3D Secure authentication', async ({ page }) => {
    // ... navigate to Stripe checkout

    const stripePage = new StripeCheckoutPage(page);
    await stripePage.completePayment(
      process.env.TEST_USER_EMAIL!,
      STRIPE_TEST_CARDS.REQUIRES_3DS
    );

    // Handle 3DS iframe
    const frame = page.frameLocator('iframe[name*="stripe"]');
    await frame.locator('#test-source-authorize-3ds').click();

    // Continue with verification
    await stripePage.waitForRedirectBack(process.env.SHOP_URL!);

    const thankYouPage = new ThankYouPage(page);
    await expect(thankYouPage.orderConfirmation).toBeVisible();
  });
});
```

### stripe-test-cards.ts (Fixtures)

```typescript
export const STRIPE_TEST_CARDS = {
  // Successful payments
  VISA_SUCCESS: '4242424242424242',
  MASTERCARD_SUCCESS: '5555555555554444',
  AMEX_SUCCESS: '378282246310005',

  // Declined cards
  CARD_DECLINED: '4000000000000002',
  INSUFFICIENT_FUNDS: '4000000000009995',
  EXPIRED_CARD: '4000000000000069',
  INCORRECT_CVC: '4000000000000127',

  // 3D Secure
  REQUIRES_3DS: '4000000000003220',
  REQUIRES_3DS_2: '4000000000003063',

  // Special cases
  PROCESSING_ERROR: '4000000000000119',
  RATE_LIMIT: '4000000000006975',
};

export const TEST_CARD_DETAILS = {
  EXPIRY: '12/30',
  CVC: '123',
  CVC_AMEX: '1234',
  ZIP: '12345',
};
```

---

## bin/test_e2e_run.sh

```bash
#!/bin/bash
#
# E2E Test Runner for Stripe Payment Module
# Usage: ./bin/test_e2e_run.sh [options]
#
# Options:
#   --headed      Run tests with browser visible
#   --debug       Run in debug mode
#   --ui          Open Playwright UI
#   --chromium    Run only in Chromium
#   --firefox     Run only in Firefox
#   --webkit      Run only in Safari
#   --checkout    Run only checkout tests
#   --admin       Run only admin tests
#   --report      Show HTML report after tests
#   --install     Install browsers before running
#   -h, --help    Show this help message
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
E2E_DIR="$PROJECT_ROOT/tests/e2e/playwright"

# Default options
HEADED=""
DEBUG=""
UI=""
PROJECT=""
TEST_PATH=""
SHOW_REPORT=false
INSTALL_BROWSERS=false

# Parse arguments
while [[ $# -gt 0 ]]; do
  case $1 in
    --headed)
      HEADED="--headed"
      shift
      ;;
    --debug)
      DEBUG="--debug"
      shift
      ;;
    --ui)
      UI="--ui"
      shift
      ;;
    --chromium)
      PROJECT="--project=chromium"
      shift
      ;;
    --firefox)
      PROJECT="--project=firefox"
      shift
      ;;
    --webkit)
      PROJECT="--project=webkit"
      shift
      ;;
    --checkout)
      TEST_PATH="tests/checkout/"
      shift
      ;;
    --admin)
      TEST_PATH="tests/admin/"
      shift
      ;;
    --webhooks)
      TEST_PATH="tests/webhooks/"
      shift
      ;;
    --report)
      SHOW_REPORT=true
      shift
      ;;
    --install)
      INSTALL_BROWSERS=true
      shift
      ;;
    -h|--help)
      head -25 "$0" | tail -22
      exit 0
      ;;
    *)
      echo -e "${RED}Unknown option: $1${NC}"
      exit 1
      ;;
  esac
done

echo -e "${BLUE}======================================"
echo -e "  Stripe E2E Test Runner"
echo -e "======================================${NC}"
echo ""

# Check if e2e directory exists
if [ ! -d "$E2E_DIR" ]; then
  echo -e "${RED}Error: E2E directory not found at $E2E_DIR${NC}"
  echo -e "${YELLOW}Run 'npm install' in the e2e directory first.${NC}"
  exit 1
fi

cd "$E2E_DIR"

# Check for node_modules
if [ ! -d "node_modules" ]; then
  echo -e "${YELLOW}>>> Installing dependencies...${NC}"
  npm install
fi

# Install browsers if requested
if [ "$INSTALL_BROWSERS" = true ]; then
  echo -e "${YELLOW}>>> Installing Playwright browsers...${NC}"
  npx playwright install --with-deps
fi

# Check for .env file
if [ ! -f ".env" ]; then
  if [ -f ".env.example" ]; then
    echo -e "${YELLOW}>>> Creating .env from .env.example...${NC}"
    cp .env.example .env
    echo -e "${YELLOW}>>> Please configure .env with your settings${NC}"
  else
    echo -e "${RED}Warning: No .env file found. Using defaults.${NC}"
  fi
fi

# Build command
CMD="npx playwright test"

if [ -n "$HEADED" ]; then
  CMD="$CMD $HEADED"
fi

if [ -n "$DEBUG" ]; then
  CMD="$CMD $DEBUG"
fi

if [ -n "$UI" ]; then
  CMD="$CMD $UI"
fi

if [ -n "$PROJECT" ]; then
  CMD="$CMD $PROJECT"
fi

if [ -n "$TEST_PATH" ]; then
  CMD="$CMD $TEST_PATH"
fi

echo -e "${GREEN}>>> Running: $CMD${NC}"
echo ""

# Run tests
$CMD
TEST_EXIT_CODE=$?

# Show report if requested and tests completed
if [ "$SHOW_REPORT" = true ] && [ $TEST_EXIT_CODE -eq 0 ]; then
  echo ""
  echo -e "${GREEN}>>> Opening HTML report...${NC}"
  npx playwright show-report reports/html-report
fi

# Exit with test exit code
if [ $TEST_EXIT_CODE -eq 0 ]; then
  echo ""
  echo -e "${GREEN}======================================"
  echo -e "  All E2E tests passed!"
  echo -e "======================================${NC}"
else
  echo ""
  echo -e "${RED}======================================"
  echo -e "  E2E tests failed!"
  echo -e "======================================${NC}"
fi

exit $TEST_EXIT_CODE
```

---

## Implementation Steps

### Step 1: Create Directory Structure (15 min)
```bash
mkdir -p tests/e2e/playwright/{tests/{checkout,admin,webhooks,fixtures},helpers,pages,reports}
```

### Step 2: Create Configuration Files (30 min)
- `playwright.config.ts`
- `tsconfig.json`
- `package.json`
- `.env.example`
- `.gitignore`

### Step 3: Create Base Page Objects (45 min)
- `BasePage.ts`
- `HomePage.ts`
- `ProductPage.ts`
- `CartPage.ts`
- `CheckoutPage.ts`
- `PaymentPage.ts`
- `ThankYouPage.ts`
- `StripeCheckoutPage.ts`

### Step 4: Create Admin Page Objects (30 min)
- `AdminLoginPage.ts`
- `OrderListPage.ts`
- `OrderRefundPage.ts`

### Step 5: Create Test Fixtures (20 min)
- `stripe-test-cards.ts`
- `test-products.ts`
- `test-users.ts`

### Step 6: Create Helper Functions (30 min)
- `shop-helpers.ts`
- `stripe-helpers.ts`
- `admin-helpers.ts`
- `global-setup.ts`
- `global-teardown.ts`

### Step 7: Create Test Files (1 hour)
- `stripe-checkout.spec.ts`
- `payment-element.spec.ts`
- `order-confirmation.spec.ts`
- `refund.spec.ts`
- `configuration.spec.ts`

### Step 8: Create Runner Script (20 min)
- `bin/test_e2e_run.sh`
- Update root `package.json` with e2e scripts
- Update `Makefile` with e2e targets

### Step 9: Documentation (15 min)
- Update `README.md` with e2e instructions
- Add inline documentation to key files

### Step 10: Test & Verify (30 min)
- Run `npm install` in e2e directory
- Install browsers with `playwright install`
- Run sample tests
- Verify report generation

---

## Makefile Additions

```makefile
# E2E Testing
.PHONY: e2e e2e-install e2e-headed e2e-debug e2e-report e2e-checkout e2e-admin

e2e-install:
	cd tests/e2e/playwright && npm install && npx playwright install --with-deps

e2e:
	./bin/test_e2e_run.sh

e2e-headed:
	./bin/test_e2e_run.sh --headed

e2e-debug:
	./bin/test_e2e_run.sh --debug

e2e-ui:
	./bin/test_e2e_run.sh --ui

e2e-report:
	./bin/test_e2e_run.sh --report

e2e-checkout:
	./bin/test_e2e_run.sh --checkout

e2e-admin:
	./bin/test_e2e_run.sh --admin

# Full test suite
test-all: test e2e
```

---

## Root package.json Updates

```json
{
  "scripts": {
    "build": "npm run build:prod",
    "build:prod": "node resources/build.js production",
    "build:dev": "node resources/build.js development",
    "watch": "node resources/build.js watch",
    "dev": "npm run build:dev",
    "postinstall": "npm run build:prod",
    "e2e": "cd tests/e2e/playwright && npm test",
    "e2e:headed": "cd tests/e2e/playwright && npm run test:headed",
    "e2e:debug": "cd tests/e2e/playwright && npm run test:debug",
    "e2e:ui": "cd tests/e2e/playwright && npm run test:ui",
    "e2e:install": "cd tests/e2e/playwright && npm install && npx playwright install --with-deps"
  }
}
```

---

## Acceptance Criteria

### Functional Requirements
- [ ] Playwright installed and configured
- [ ] TypeScript compilation working
- [ ] Basic checkout test passing
- [ ] Admin refund test passing
- [ ] HTML report generated
- [ ] JUnit XML report generated

### Technical Requirements
- [ ] TypeScript strict mode enabled
- [ ] Page Object Model implemented
- [ ] Test fixtures organized
- [ ] Helper functions reusable
- [ ] Environment variables configurable
- [ ] CI/CD compatible

### Script Requirements
- [ ] `test_e2e_run.sh` executable
- [ ] All CLI options working
- [ ] Colored output
- [ ] Error handling
- [ ] Help documentation

---

## CI/CD Integration

### GitHub Actions Example

```yaml
name: E2E Tests

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Install dependencies
        working-directory: ./source/extensions/stripe/tests/e2e/playwright
        run: npm ci

      - name: Install Playwright browsers
        working-directory: ./source/extensions/stripe/tests/e2e/playwright
        run: npx playwright install --with-deps

      - name: Run E2E tests
        working-directory: ./source/extensions/stripe/tests/e2e/playwright
        run: npx playwright test
        env:
          CI: true
          SHOP_URL: ${{ secrets.SHOP_URL }}
          ADMIN_USERNAME: ${{ secrets.ADMIN_USERNAME }}
          ADMIN_PASSWORD: ${{ secrets.ADMIN_PASSWORD }}

      - name: Upload test results
        uses: actions/upload-artifact@v4
        if: always()
        with:
          name: playwright-report
          path: source/extensions/stripe/tests/e2e/playwright/reports/
          retention-days: 30
```

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Stripe Checkout UI changes | Medium | High | Use resilient selectors, update regularly |
| Flaky tests | Medium | Medium | Proper waits, retry logic, stable selectors |
| Environment differences | Medium | Medium | Docker-based testing, env variables |
| Browser compatibility | Low | Medium | Test in multiple browsers |
| Test data cleanup | Medium | Low | Global teardown, isolated test data |

---

## Dependencies

### NPM Packages
- `@playwright/test` ^1.56.1
- `typescript` ^5.9.3
- `@types/node` ^24.10.1
- `dotenv` ^16.5.0

### External
- Node.js >= 18.0.0
- Chromium, Firefox, WebKit browsers
- Running OXID shop instance
- Stripe test mode API keys

---

## Definition of Done

1. All configuration files created
2. Page objects implemented
3. At least 3 checkout tests passing
4. At least 1 admin test passing
5. Runner script working
6. Documentation complete
7. CI/CD workflow defined

---

**Created:** 2025-12-02
**Author:** Claude Code Assistant
