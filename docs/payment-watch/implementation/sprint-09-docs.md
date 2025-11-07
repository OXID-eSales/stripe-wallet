# Sprint 9: Documentation & Integration Examples

**Duration:** 1 week
**Team:** 2 developers (1 technical writer)
**Prerequisites:** Sprint 8 complete (NPM package published)

---

## Sprint Overview

### Goal
Create comprehensive **documentation and integration examples** for PaymentWatch to help developers integrate it into their E2E testing workflows.

### Key Deliverables
1. **Playwright Integration Example** - Complete working example
2. **Cypress Integration Example** - Complete working example
3. **Jest Integration Example** - Unit/integration testing
4. **Example Repository** - Reference implementation with all examples
5. **Troubleshooting Guide** - Common issues and solutions
6. **Video Tutorial** (Optional) - Quick start screencast

---

## Task 9.1: Playwright Integration Example

**Time Estimate:** 3 hours

### Create Example Project Structure

```bash
mkdir -p examples/playwright-example
cd examples/playwright-example

# Initialize project
npm init -y
npm install --save-dev @playwright/test
npm install @oxid-esales/paymentwatch-client
```

### Create Playwright Config

```bash
cat > playwright.config.ts << 'EOF'
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 60000,
  expect: {
    timeout: 30000
  },
  use: {
    baseURL: 'http://localhost',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { browserName: 'chromium' },
    },
  ],
});
EOF
```

### Create Example Test

```bash
mkdir -p tests
cat > tests/payment-flow.spec.ts << 'EOF'
import { test, expect } from '@playwright/test';
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

const client = new PaymentWatchClient({
  baseUrl: process.env.PAYMENTWATCH_URL || 'http://localhost/paymentwatch',
  apiKey: process.env.PAYMENTWATCH_API_KEY!,
  timeout: 30000
});

test.describe('Payment Flow E2E', () => {
  const testEmail = `test-${Date.now()}@example.com`;
  const testOrderNr = `TEST-${Date.now()}`;

  test('complete PayPal payment flow', async ({ page }) => {
    // Step 1: Add product to cart
    await page.goto('/');
    await page.click('[data-test="add-to-cart"]');
    
    // Step 2: Go to checkout
    await page.goto('/checkout');
    await page.fill('#email', testEmail);
    await page.fill('#firstName', 'John');
    await page.fill('#lastName', 'Doe');
    
    // Step 3: Select PayPal payment
    await page.click('[data-test="payment-paypal"]');
    
    // Step 4: Submit order
    await page.click('[data-test="submit-order"]');
    
    // Step 5: Wait for order to be created in database
    // ❌ OLD WAY (flaky):
    // await page.waitForTimeout(5000); // Hope order is created...
    
    // ✅ NEW WAY (reliable):
    await client.waitFor({
      table: 'oxorder',
      field: 'oxbillemail',
      value: testEmail,
      operator: '==',
      timeout: 30000,
      interval: 1000
    });
    
    // Step 6: Simulate PayPal payment completion
    // (In real test, you'd interact with PayPal's sandbox)
    await page.goto('/mock-paypal-callback?status=completed');
    
    // Step 7: Assert PayPal transaction is completed
    await client.waitFor({
      table: 'oepaypal_order',
      field: 'oxtransactionstatus',
      value: 'completed',
      operator: '==',
      where: {
        oxbillemail: testEmail
      },
      timeout: 30000
    });
    
    // Step 8: Assert order is marked as paid
    await client.waitFor({
      table: 'oxorder',
      field: 'oxpaid',
      value: '0000-00-00 00:00:00',
      operator: '!=',
      where: {
        oxbillemail: testEmail
      },
      timeout: 10000
    });
    
    // Step 9: Verify success page is shown
    await expect(page.locator('[data-test="order-success"]')).toBeVisible();
  });

  test('failed payment scenario', async ({ page }) => {
    await page.goto('/checkout');
    await page.fill('#email', testEmail);
    await page.click('[data-test="payment-paypal"]');
    await page.click('[data-test="submit-order"]');
    
    // Simulate failed payment
    await page.goto('/mock-paypal-callback?status=failed');
    
    // Assert transaction is marked as failed
    await client.waitFor({
      table: 'oepaypal_order',
      field: 'oxtransactionstatus',
      value: 'failed',
      operator: '==',
      timeout: 30000
    });
    
    // Assert order is NOT paid
    await client.assertExists({
      table: 'oxorder',
      field: 'oxpaid',
      value: '0000-00-00 00:00:00',
      operator: '==',
      where: {
        oxbillemail: testEmail
      }
    });
    
    // Verify error page is shown
    await expect(page.locator('[data-test="payment-error"]')).toBeVisible();
  });

  test('refund scenario', async ({ page }) => {
    // Create completed order first
    await page.goto('/checkout');
    await page.fill('#email', testEmail);
    await page.click('[data-test="submit-order"]');
    
    // Wait for payment completion
    await client.waitFor({
      table: 'oepaypal_order',
      field: 'oxtransactionstatus',
      value: 'completed',
      timeout: 30000
    });
    
    // Go to admin and issue refund
    await page.goto('/admin/orders');
    await page.click(`[data-order-email="${testEmail}"]`);
    await page.click('[data-test="refund-button"]');
    await page.click('[data-test="confirm-refund"]');
    
    // Assert transaction status is refunded
    await client.waitFor({
      table: 'oepaypal_order',
      field: 'oxtransactionstatus',
      value: 'refunded',
      operator: '==',
      where: {
        oxbillemail: testEmail
      },
      timeout: 30000
    });
  });
});
EOF
```

### Create README for Playwright Example

```bash
cat > README.md << 'EOF'
# Playwright + PaymentWatch Example

This example demonstrates how to use PaymentWatch with Playwright for reliable E2E payment testing.

## Setup

```bash
npm install
```

## Configuration

Create `.env` file:

```bash
PAYMENTWATCH_URL=http://localhost/paymentwatch
PAYMENTWATCH_API_KEY=your-secret-api-key-here
```

## Run Tests

```bash
npx playwright test
```

## Run Tests in UI Mode

```bash
npx playwright test --ui
```

## Key Benefits

### Before PaymentWatch (Flaky Tests)

```typescript
await page.click('#submit-order');
await page.waitForTimeout(5000); // ❌ Hope payment completes...
await expect(page.locator('.success')).toBeVisible(); // ❌ Might fail randomly
```

### After PaymentWatch (Reliable Tests)

```typescript
await page.click('#submit-order');
await client.waitFor({
  table: 'oepaypal_order',
  field: 'oxtransactionstatus',
  value: 'completed',
  timeout: 30000
}); // ✅ Waits until actually completed
await expect(page.locator('.success')).toBeVisible(); // ✅ Always passes
```

## Troubleshooting

See [../TROUBLESHOOTING.md](../TROUBLESHOOTING.md)
EOF
```

---

## Task 9.2: Cypress Integration Example

**Time Estimate:** 2 hours

### Create Cypress Example

```bash
mkdir -p examples/cypress-example
cd examples/cypress-example

# Initialize
npm init -y
npm install --save-dev cypress
npm install @oxid-esales/paymentwatch-client
```

### Create Cypress Config

```bash
cat > cypress.config.ts << 'EOF'
import { defineConfig } from 'cypress';

export default defineConfig({
  e2e: {
    baseUrl: 'http://localhost',
    setupNodeEvents(on, config) {
      // implement node event listeners here
    },
  },
  env: {
    PAYMENTWATCH_URL: process.env.PAYMENTWATCH_URL || 'http://localhost/paymentwatch',
    PAYMENTWATCH_API_KEY: process.env.PAYMENTWATCH_API_KEY
  }
});
EOF
```

### Create Cypress Test

```bash
mkdir -p cypress/e2e
cat > cypress/e2e/payment-flow.cy.ts << 'EOF'
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

const client = new PaymentWatchClient({
  baseUrl: Cypress.env('PAYMENTWATCH_URL'),
  apiKey: Cypress.env('PAYMENTWATCH_API_KEY')
});

describe('Payment Flow E2E', () => {
  const testEmail = `test-${Date.now()}@example.com`;

  it('completes PayPal payment', () => {
    // Navigate to checkout
    cy.visit('/checkout');
    cy.get('#email').type(testEmail);
    cy.get('[data-test="payment-paypal"]').click();
    cy.get('[data-test="submit-order"]').click();

    // Wait for order creation in database
    cy.wrap(null).then(async () => {
      await client.waitFor({
        table: 'oxorder',
        field: 'oxbillemail',
        value: testEmail,
        operator: '==',
        timeout: 30000
      });
    });

    // Simulate PayPal completion
    cy.visit('/mock-paypal-callback?status=completed');

    // Assert transaction completed
    cy.wrap(null).then(async () => {
      await client.waitFor({
        table: 'oepaypal_order',
        field: 'oxtransactionstatus',
        value: 'completed',
        timeout: 30000
      });
    });

    // Verify success page
    cy.get('[data-test="order-success"]').should('be.visible');
  });

  it('handles payment cancellation', () => {
    cy.visit('/checkout');
    cy.get('#email').type(testEmail);
    cy.get('[data-test="submit-order"]').click();

    // Simulate cancellation
    cy.visit('/mock-paypal-callback?status=cancelled');

    // Assert transaction cancelled
    cy.wrap(null).then(async () => {
      await client.assertExists({
        table: 'oepaypal_order',
        field: 'oxtransactionstatus',
        value: 'cancelled'
      });
    });

    // Verify error message
    cy.get('[data-test="payment-cancelled"]').should('be.visible');
  });
});
EOF
```

---

## Task 9.3: Jest Integration Example

**Time Estimate:** 2 hours

### Create Jest Example

```bash
mkdir -p examples/jest-example
cd examples/jest-example

# Initialize
npm init -y
npm install --save-dev jest ts-jest @types/jest
npm install @oxid-esales/paymentwatch-client
```

### Create Jest Config

```bash
cat > jest.config.js << 'EOF'
module.exports = {
  preset: 'ts-jest',
  testEnvironment: 'node',
  roots: ['<rootDir>/tests'],
  testMatch: ['**/*.test.ts'],
  collectCoverageFrom: ['src/**/*.ts'],
  coverageThreshold: {
    global: {
      branches: 80,
      functions: 80,
      lines: 80,
      statements: 80
    }
  }
};
EOF
```

### Create Jest Test

```bash
mkdir -p tests
cat > tests/order-service.test.ts << 'EOF'
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';
import { OrderService } from '../src/order-service';

const client = new PaymentWatchClient({
  baseUrl: process.env.PAYMENTWATCH_URL!,
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});

describe('OrderService', () => {
  let orderService: OrderService;

  beforeEach(() => {
    orderService = new OrderService();
  });

  describe('createOrder', () => {
    it('creates order in database', async () => {
      const orderData = {
        email: `test-${Date.now()}@example.com`,
        total: 99.99,
        items: [{ id: 1, name: 'Product', price: 99.99 }]
      };

      // Create order
      const orderId = await orderService.createOrder(orderData);

      // Assert order exists in database
      await client.assertExists({
        table: 'oxorder',
        field: 'oxid',
        value: orderId,
        operator: '=='
      });

      // Assert order details are correct
      await client.assertExists({
        table: 'oxorder',
        field: 'oxbillemail',
        value: orderData.email,
        where: {
          oxid: orderId
        }
      });

      await client.assertExists({
        table: 'oxorder',
        field: 'oxtotalordersum',
        value: orderData.total,
        where: {
          oxid: orderId
        }
      });
    });

    it('creates order with cancelled status', async () => {
      const orderData = {
        email: `test-${Date.now()}@example.com`,
        total: 50.00,
        status: 'cancelled'
      };

      const orderId = await orderService.createOrder(orderData);

      // Assert order is cancelled
      await client.assertExists({
        table: 'oxorder',
        field: 'oxstorno',
        value: 1, // 1 = cancelled
        where: {
          oxid: orderId
        }
      });
    });
  });

  describe('processPayment', () => {
    it('creates PayPal transaction', async () => {
      const paymentData = {
        orderId: 'test-order-123',
        amount: 99.99,
        provider: 'paypal'
      };

      const transactionId = await orderService.processPayment(paymentData);

      // Assert transaction exists
      await client.assertExists({
        table: 'oepaypal_order',
        field: 'oxproviderorderid',
        value: transactionId
      });

      // Assert transaction status is pending
      await client.assertExists({
        table: 'oepaypal_order',
        field: 'oxtransactionstatus',
        value: 'pending',
        where: {
          oxproviderorderid: transactionId
        }
      });
    });
  });

  describe('completePayment', () => {
    it('updates transaction status to completed', async () => {
      const transactionId = 'TXN-' + Date.now();

      // Create pending transaction
      await orderService.createTransaction({
        id: transactionId,
        status: 'pending'
      });

      // Complete payment
      await orderService.completePayment(transactionId);

      // Assert status is completed
      await client.waitFor({
        table: 'oepaypal_order',
        field: 'oxtransactionstatus',
        value: 'completed',
        where: {
          oxproviderorderid: transactionId
        },
        timeout: 10000
      });
    });
  });
});
EOF
```

---

## Task 9.4: Example Repository

**Time Estimate:** 2 hours

### Create Complete Example Repository

```bash
mkdir -p paymentwatch-examples
cd paymentwatch-examples

# Initialize monorepo
npm init -y

# Create workspace structure
mkdir -p examples/{playwright-example,cypress-example,jest-example}

# Create root package.json with workspaces
cat > package.json << 'EOF'
{
  "name": "paymentwatch-examples",
  "version": "1.0.0",
  "private": true,
  "workspaces": [
    "examples/*"
  ],
  "scripts": {
    "test:playwright": "npm run test --workspace=examples/playwright-example",
    "test:cypress": "npm run test --workspace=examples/cypress-example",
    "test:jest": "npm run test --workspace=examples/jest-example",
    "test:all": "npm run test:playwright && npm run test:cypress && npm run test:jest"
  },
  "repository": {
    "type": "git",
    "url": "https://github.com/OXID-eSales/paymentwatch-examples.git"
  }
}
EOF
```

### Create Main README

```bash
cat > README.md << 'EOF'
# PaymentWatch Integration Examples

This repository contains complete, working examples of PaymentWatch integration with popular E2E testing frameworks.

## Examples

### 1. Playwright Example
Location: `examples/playwright-example/`

Complete E2E payment flow testing with Playwright.

[View Example →](examples/playwright-example/)

### 2. Cypress Example
Location: `examples/cypress-example/`

E2E payment testing with Cypress.

[View Example →](examples/cypress-example/)

### 3. Jest Example
Location: `examples/jest-example/`

Integration testing with Jest for backend services.

[View Example →](examples/jest-example/)

## Quick Start

### 1. Clone Repository

```bash
git clone https://github.com/OXID-eSales/paymentwatch-examples.git
cd paymentwatch-examples
```

### 2. Install Dependencies

```bash
npm install
```

### 3. Configure Environment

Create `.env` in each example directory:

```bash
PAYMENTWATCH_URL=http://localhost/paymentwatch
PAYMENTWATCH_API_KEY=your-secret-api-key-here
```

### 4. Run Examples

```bash
# Run Playwright example
npm run test:playwright

# Run Cypress example
npm run test:cypress

# Run Jest example
npm run test:jest

# Run all examples
npm run test:all
```

## What is PaymentWatch?

PaymentWatch eliminates flaky E2E payment tests by providing reliable database assertions.

### The Problem

```typescript
// ❌ Flaky test with arbitrary waits
await page.click('#submit-payment');
await page.waitForTimeout(5000); // Hope payment completes...
await expect(page.locator('.success')).toBeVisible();
```

**Issues:**
- Tests fail randomly when payment takes > 5 seconds
- Tests are slow (always wait full 5 seconds)
- No visibility into actual payment status

### The Solution

```typescript
// ✅ Reliable test with PaymentWatch
await page.click('#submit-payment');
await client.waitFor({
  table: 'oepaypal_order',
  field: 'oxtransactionstatus',
  value: 'completed',
  timeout: 30000
});
await expect(page.locator('.success')).toBeVisible();
```

**Benefits:**
- Tests pass reliably
- Tests are fast (return as soon as condition is met)
- Full visibility into payment status

## Requirements

- Node.js >= 16
- PaymentWatch Server >= 1.0.0
- OXID eShop with payment module

## Documentation

- [PaymentWatch Documentation](https://docs.oxid-esales.com/paymentwatch)
- [NPM Package](https://www.npmjs.com/package/@oxid-esales/paymentwatch-client)
- [Troubleshooting Guide](TROUBLESHOOTING.md)

## License

MIT
EOF
```

---

## Task 9.5: Troubleshooting Guide

**Time Estimate:** 2 hours

### Create Comprehensive Troubleshooting Guide

```bash
cat > TROUBLESHOOTING.md << 'EOF'
# PaymentWatch Troubleshooting Guide

Common issues and solutions when using PaymentWatch.

---

## Authentication Errors

### Error: "Authentication failed: Invalid API key"

**Cause:** API key is incorrect or missing.

**Solution:**
1. Verify API key in `.env` file:
   ```bash
   PAYMENTWATCH_API_KEY=your-actual-key-here
   ```

2. Check API key matches server configuration:
   ```bash
   # On server
   grep PAYMENTWATCH_API_KEY .env
   ```

3. Ensure API key is loaded in your test:
   ```typescript
   const client = new PaymentWatchClient({
     baseUrl: process.env.PAYMENTWATCH_URL!,
     apiKey: process.env.PAYMENTWATCH_API_KEY!  // ✅
   });
   ```

---

### Error: "IP address not allowed: 192.168.1.100"

**Cause:** Client IP is not in server's allowlist.

**Solution:**
1. Check server IP allowlist:
   ```bash
   # In server .env
   PAYMENTWATCH_ALLOWED_IPS=127.0.0.1,192.168.1.0/24
   ```

2. Add your IP to allowlist:
   ```bash
   # Single IP
   PAYMENTWATCH_ALLOWED_IPS=127.0.0.1,192.168.1.100

   # CIDR range
   PAYMENTWATCH_ALLOWED_IPS=192.168.1.0/24
   ```

3. For Docker: Use host network or add Docker subnet:
   ```bash
   PAYMENTWATCH_ALLOWED_IPS=172.17.0.0/16
   ```

---

## Timeout Errors

### Error: "TimeoutError: Assumption did not pass within 30000ms"

**Cause:** Database condition was not met within timeout period.

**Debugging Steps:**

1. **Increase timeout:**
   ```typescript
   await client.waitFor({
     table: 'oxorder',
     field: 'oxpaid',
     value: '0000-00-00 00:00:00',
     operator: '!=',
     timeout: 60000  // 60 seconds
   });
   ```

2. **Check if data exists:**
   ```typescript
   try {
     await client.waitFor({ ... }, { timeout: 10000 });
   } catch (error) {
     // Check database manually
     console.log('Timeout - checking database...');
     const result = await client.assume({ ... });
     console.log('Result:', result);
   }
   ```

3. **Verify SQL query:**
   - Check PaymentWatch server logs
   - Look for SQL query that was executed
   - Run query manually in database

4. **Common causes:**
   - Payment webhook not triggered
   - Background job not processing
   - Database transaction not committed

---

## Validation Errors

### Error: "Invalid table name: OxOrder"

**Cause:** Table name does not match OXID naming conventions.

**Solution:**
Use lowercase table names:
```typescript
// ❌ Wrong
{ table: 'OxOrder', field: 'oxordernr', value: '123' }

// ✅ Correct
{ table: 'oxorder', field: 'oxordernr', value: '123' }
```

---

### Error: "Invalid operator: ="

**Cause:** Using SQL operator instead of PaymentWatch operator.

**Solution:**
Use PaymentWatch operators:
```typescript
// ❌ Wrong
{ operator: '=' }

// ✅ Correct
{ operator: '==' }
```

**Supported operators:**
- Equality: `==`, `!=`
- Comparison: `>`, `<`, `>=`, `<=`
- LIKE: `%like%`, `like%`, `%like`
- NULL: `IS NULL`, `IS NOT NULL`
- IN: `IN`, `NOT IN`

---

## Connection Errors

### Error: "fetch failed" or "ECONNREFUSED"

**Cause:** Cannot connect to PaymentWatch server.

**Debugging Steps:**

1. **Verify server is running:**
   ```bash
   curl http://localhost/paymentwatch/assume
   # Should return 401 (missing API key) not connection error
   ```

2. **Check baseUrl:**
   ```typescript
   const client = new PaymentWatchClient({
     baseUrl: 'http://localhost/paymentwatch',  // ✅ Correct
     // NOT: http://localhost/paymentwatch/assume  // ❌ Wrong
   });
   ```

3. **For Docker: Use correct host:**
   ```typescript
   // From host machine
   baseUrl: 'http://localhost/paymentwatch'

   // From inside Docker
   baseUrl: 'http://host.docker.internal/paymentwatch'
   ```

---

## Assertion Failures

### Error: "AssertionError: Assumption failed: No rows matched"

**Cause:** Expected data does not exist in database.

**Debugging Steps:**

1. **Check data was created:**
   ```sql
   SELECT * FROM oxorder WHERE oxordernr = '12345';
   ```

2. **Check WHERE clause:**
   ```typescript
   // Make sure WHERE conditions are correct
   await client.assertExists({
     table: 'oxorder',
     field: 'oxpaid',
     value: '0000-00-00 00:00:00',
     operator: '!=',
     where: {
       oxordernr: '12345',  // ✅ Correct order number
       oxstorno: 0          // ✅ Not cancelled
     }
   });
   ```

3. **Use `assume()` for debugging:**
   ```typescript
   const result = await client.assume({
     table: 'oxorder',
     field: 'oxordernr',
     value: '12345'
   });
   console.log('Exists:', result.success);
   console.log('Message:', result.message);
   ```

---

## Performance Issues

### Issue: Tests are slow

**Causes & Solutions:**

1. **Missing database indexes:**
   ```sql
   -- Add indexes for frequently queried fields
   CREATE INDEX idx_paymentwatch_order_nr ON oxorder(oxordernr);
   CREATE INDEX idx_paymentwatch_transaction ON oepaypal_order(oxproviderorderid, oxtransactionstatus);
   ```

2. **Long polling intervals:**
   ```typescript
   await client.waitFor({
     table: 'oxorder',
     field: 'oxpaid',
     value: '0000-00-00 00:00:00',
     operator: '!=',
     timeout: 30000,
     interval: 500  // Poll every 500ms instead of 1000ms
   });
   ```

3. **Use `assume()` for one-time checks:**
   ```typescript
   // ❌ Slow - polls unnecessarily
   await client.waitFor({ table: 'oxorder', ... }, { timeout: 5000 });

   // ✅ Fast - single check
   const result = await client.assume({ table: 'oxorder', ... });
   if (!result.success) throw new Error('Not found');
   ```

---

## Playwright-Specific Issues

### Issue: "cy.wrap() is not a function"

**Cause:** Using Cypress syntax in Playwright.

**Solution:**
Use Playwright's async/await directly:
```typescript
// ❌ Cypress syntax
cy.wrap(null).then(async () => {
  await client.waitFor({ ... });
});

// ✅ Playwright - just use await
await client.waitFor({ ... });
```

---

## Cypress-Specific Issues

### Issue: Cypress command queue issues

**Solution:**
Always wrap async calls in `cy.wrap()`:
```typescript
// ✅ Correct
cy.wrap(null).then(async () => {
  await client.waitFor({ ... });
});

// ❌ Wrong - breaks Cypress queue
await client.waitFor({ ... });
```

---

## TypeScript Errors

### Error: "Type 'string | undefined' is not assignable"

**Cause:** Environment variable might be undefined.

**Solution:**
1. Use non-null assertion if you're sure it exists:
   ```typescript
   apiKey: process.env.PAYMENTWATCH_API_KEY!
   ```

2. Or provide fallback:
   ```typescript
   apiKey: process.env.PAYMENTWATCH_API_KEY || 'default-key'
   ```

3. Or check explicitly:
   ```typescript
   const apiKey = process.env.PAYMENTWATCH_API_KEY;
   if (!apiKey) throw new Error('PAYMENTWATCH_API_KEY not set');
   
   const client = new PaymentWatchClient({ baseUrl: '...', apiKey });
   ```

---

## Getting Help

### Still having issues?

1. **Check logs:**
   - PaymentWatch server logs
   - Your test framework logs
   - Browser console (for E2E tests)

2. **Enable debug mode:**
   ```typescript
   const client = new PaymentWatchClient({
     baseUrl: '...',
     apiKey: '...',
     // Add custom logging
   });
   ```

3. **Create minimal reproduction:**
   - Isolate the failing test
   - Remove unrelated code
   - Share reproduction in GitHub issue

4. **Open GitHub issue:**
   - [PaymentWatch Client Issues](https://github.com/OXID-eSales/paymentwatch-client/issues)
   - [PaymentWatch Server Issues](https://github.com/OXID-eSales/payment-component/issues)

5. **Community support:**
   - OXID Community Forum
   - Stack Overflow (tag: `oxid-esales`, `paymentwatch`)

---

## Useful Commands

### Test connection to PaymentWatch server
```bash
curl -X POST http://localhost/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-key" \
  -d '{"table":"oxorder","field":"oxid","value":"test","operator":"=="}'
```

### Check database directly
```bash
docker compose exec php bash -c "mysql -u root -p oxid -e 'SELECT * FROM oxorder LIMIT 5;'"
```

### View PaymentWatch server logs
```bash
docker compose logs php | grep PaymentWatch
```

### Test API key validity
```bash
curl -v http://localhost/paymentwatch/assume \
  -H "X-API-Key: test-key" \
  -d '{}'
# Should return 400 (bad request) not 401 (unauthorized)
```

---

**Last Updated:** 2025-01-15
EOF
```

---

## Sprint 9 Deliverables

### Examples
```
examples/
├── playwright-example/
│   ├── tests/payment-flow.spec.ts
│   ├── playwright.config.ts
│   └── README.md
├── cypress-example/
│   ├── cypress/e2e/payment-flow.cy.ts
│   ├── cypress.config.ts
│   └── README.md
└── jest-example/
    ├── tests/order-service.test.ts
    ├── jest.config.js
    └── README.md
```

### Documentation
```
TROUBLESHOOTING.md    # Comprehensive troubleshooting guide
README.md             # Main example repository README
```

---

## Acceptance Criteria

### Examples
- ✅ Playwright example with 3 complete tests
- ✅ Cypress example with 2 complete tests
- ✅ Jest example with 3 service tests
- ✅ All examples runnable out-of-the-box
- ✅ README for each example

### Documentation
- ✅ Troubleshooting guide with 15+ common issues
- ✅ Solutions for authentication, timeout, validation errors
- ✅ Framework-specific troubleshooting
- ✅ Debugging commands and tools

---

## Verify Sprint Completion

### Run All Examples

```bash
# Clone examples repo
git clone https://github.com/OXID-eSales/paymentwatch-examples.git
cd paymentwatch-examples

# Install dependencies
npm install

# Run Playwright example
cd examples/playwright-example
npx playwright test

# Run Cypress example
cd ../cypress-example
npx cypress run

# Run Jest example
cd ../jest-example
npm test
```

**Expected:** ✅ All tests passing

---

## Sprint Review

### Demo Checklist
- [ ] Show Playwright example running
- [ ] Show Cypress example running
- [ ] Show Jest example running
- [ ] Walk through troubleshooting guide
- [ ] Demonstrate debugging techniques

### Retrospective Questions
1. Are examples clear enough for beginners?
2. Should we add more framework examples (WebdriverIO, TestCafe)?
3. Should we create video tutorials?
4. Are troubleshooting solutions comprehensive?

---

## Next Sprint

**Ready for [Sprint 10: Production Release](sprint-10-release.md)**

Sprint 10 will complete:
- Security audit (penetration testing)
- Performance optimization
- Load testing (100 concurrent requests)
- Deployment guide
- v1.0.0 release
- Announcement and promotion

---

**Sprint 9 Complete! 🎉**
**Examples:** Playwright, Cypress, Jest
**Documentation:** Comprehensive troubleshooting guide
**Repository:** Complete example repository
**Next:** Production Release (Week 12)
