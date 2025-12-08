# Playwright E2E Tests for Stripe Payment Module

End-to-end tests for the OXID eShop Stripe Payment Module using Playwright.

## Quick Start

```bash
# Install dependencies
npm install

# Install Chromium browser
npx playwright install chromium

# Run all tests
npm test
```

## Prerequisites

- Node.js 18+
- npm 9+
- Access to a running OXID eShop instance

## Installation

```bash
cd tests/e2e/playwright
npm install
npx playwright install --with-deps chromium
```

## Configuration

### Environment Variables

Copy `.env.dist` to `.env` and configure:

```bash
SHOP_URL=https://your-shop.example.com
TEST_USER_EMAIL=playwright.user@oxid-esales.dev
TEST_USER_PASSWORD=useruser
HEADLESS=true
```

| Variable | Description | Default |
|----------|-------------|---------|
| `SHOP_URL` | Base URL of the OXID shop | `https://localhost.local` |
| `TEST_USER_EMAIL` | Test user email | `playwright.user@oxid-esales.dev` |
| `TEST_USER_PASSWORD` | Test user password | `useruser` |
| `HEADLESS` | Run browser in headless mode | `true` |

### Test User Setup

Before running tests, ensure the test user exists in the shop:
- Email: `playwright.user@oxid-esales.dev`
- Password: `useruser`
- Name: `Marc Muster`

## Running Tests

### NPM Scripts

```bash
npm test                    # Run all tests (headless)
npm run test:headed         # Run with visible browser
npm run test:debug          # Run in debug mode
npm run test:checkout       # Run only checkout tests
npm run report              # Open HTML test report
npm run install-browsers    # Install Chromium
```

### Direct Playwright Commands

```bash
# Run all tests
npx playwright test

# Run specific test file
npx playwright test tests/checkout/stripe-checkout.spec.ts

# Run tests matching a pattern
npx playwright test --grep "checkout"

# Run with visible browser
npx playwright test --headed

# Run in debug mode (step through)
npx playwright test --debug

# Run in UI mode (interactive)
npx playwright test --ui

# Generate test code interactively
npx playwright codegen https://your-shop.example.com
```

## Project Structure

```
tests/e2e/playwright/
├── playwright.config.ts      # Playwright configuration
├── tsconfig.json             # TypeScript configuration
├── package.json              # Dependencies and scripts
├── .env                      # Environment variables (gitignored)
├── .env.dist                 # Environment template
│
├── pages/                    # Page Object Model
│   ├── frontend/
│   │   ├── BasePage.ts       # Base page with common methods
│   │   ├── HomePage.ts       # Shop homepage
│   │   ├── LoginPage.ts      # Login page
│   │   ├── ProductPage.ts    # Product detail page
│   │   ├── CartPage.ts       # Shopping cart
│   │   ├── CheckoutPage.ts   # Checkout steps
│   │   ├── StripeCheckoutPage.ts  # Stripe hosted checkout
│   │   └── ThankYouPage.ts   # Order confirmation
│   └── admin/
│       ├── AdminBasePage.ts  # Admin base page
│       ├── AdminLoginPage.ts # Admin login
│       ├── AdminOrdersPage.ts    # Orders list
│       └── AdminStripeOrderPage.ts  # Stripe order details
│
├── tests/                    # Test specifications
│   ├── checkout/
│   │   └── stripe-checkout.spec.ts  # Checkout flow tests
│   └── admin/
│       ├── stripe-admin-order.spec.ts  # Admin order tests
│       └── payment-date-validation.spec.ts
│
├── fixtures/                 # Test data
│   └── stripe-test-cards.ts  # Stripe test card numbers
│
└── reports/                  # Test output
    ├── html-report/          # HTML test report
    └── test-results/         # Screenshots, videos, traces
```

## Test Coverage

### Frontend Tests

| Test | Description |
|------|-------------|
| `stripe-checkout.spec.ts` | Complete checkout flow with Stripe Wallet payment |

### Admin Tests

| Test | Description |
|------|-------------|
| `stripe-admin-order.spec.ts` | Admin order verification and refund processing |
| `payment-date-validation.spec.ts` | Payment date validation in admin panel |

## Stripe Test Cards

Use these test card numbers for different scenarios:

| Card Number | Scenario |
|-------------|----------|
| `4111111111111111` | Successful payment |
| `4242424242424242` | Successful payment (alternate) |
| `5555555555554444` | Mastercard success |
| `4000000000000002` | Card declined |
| `4000000000009995` | Insufficient funds |
| `4000000000003220` | Requires 3D Secure |
| `4000000000000069` | Expired card |
| `4000000000000127` | Incorrect CVC |

**Default test card details:**
- Expiry: `12/30`
- CVC: `111`
- Name: `Marc Muster`

## Test Reports

After running tests, view the HTML report:

```bash
npm run report
# or
npx playwright show-report reports/html-report
```

Reports include:
- Test results summary
- Screenshots (captured on failure)
- Videos (recorded on failure)
- Traces (enabled on retry)

## Configuration Details

### playwright.config.ts

Key settings:

| Setting | Value | Description |
|---------|-------|-------------|
| `timeout` | 120000ms | Test timeout (2 minutes) |
| `workers` | 1 | Sequential execution |
| `fullyParallel` | false | Prevents payment race conditions |
| `actionTimeout` | 30000ms | Element interaction timeout |
| `navigationTimeout` | 60000ms | Page load timeout |
| `viewport` | 1280x720 | Browser window size |
| `trace` | on-first-retry | Record traces on failure |
| `screenshot` | only-on-failure | Capture screenshots on failure |
| `video` | retain-on-failure | Record video on failure |

## Troubleshooting

### Tests fail with "net::ERR_NAME_NOT_RESOLVED"

The browser cannot resolve the shop URL. Ensure:
1. `SHOP_URL` in `.env` is correct and accessible
2. If using local development, use `localhost` or add hostname to `/etc/hosts`

### Stripe redirect not detected

If tests fail at Stripe checkout redirect:
1. Verify Stripe payment method is activated in shop admin
2. Check that test mode is enabled in Stripe configuration
3. Ensure the product has a valid price

### Admin tests fail with frame errors

OXID admin uses framesets. The tests handle this with:
```typescript
const navFrame = page.frameLocator('frame[name="navigation"]');
const baseFrame = page.frameLocator('frame[name="basefrm"]');
```

### Login fails

Ensure the test user exists in the shop database with correct credentials.

## CI/CD Integration

For CI environments:

```bash
# Install with locked dependencies
npm ci

# Install browsers with system dependencies
npx playwright install --with-deps

# Run tests
CI=true npx playwright test
```

Environment variables for CI:
```
CI=true
SHOP_URL=https://test-shop.example.com
TEST_USER_EMAIL=test@example.com
TEST_USER_PASSWORD=testpass
```

## Writing New Tests

1. Create a new `.spec.ts` file in appropriate `tests/` subdirectory
2. Use Page Object Model pattern with classes from `pages/`
3. Use fixtures from `fixtures/` for test data

Example:

```typescript
import { test, expect } from '@playwright/test';
import { LoginPage, TEST_USER } from '../../pages/frontend/LoginPage';

test.describe('My Feature', () => {
  test('should work correctly', async ({ page }) => {
    const shopUrl = process.env.SHOP_URL || 'https://localhost.local';

    // Navigate and interact
    await page.goto(shopUrl);

    // Assert
    expect(page.url()).toContain('expected');
  });
});
```

## Related Documentation

- [Playwright Documentation](https://playwright.dev/docs/intro)
- [OXID eShop Documentation](https://docs.oxid-esales.com/)
- [Stripe Testing Documentation](https://stripe.com/docs/testing)
