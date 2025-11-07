# PaymentWatch JavaScript/TypeScript SDK

**Official Node.js Client for PaymentWatch API**

Version: 1.0.0
Date: 2025-11-11
Package: `@oxid-esales/paymentwatch-client`

---

## Overview

The PaymentWatch JavaScript SDK provides a type-safe, promise-based client for interacting with the PaymentWatch API in E2E tests. It supports both JavaScript and TypeScript projects and integrates seamlessly with popular testing frameworks like Playwright, Cypress, Jest, and Mocha.

### Features

✅ **TypeScript Support** - Full type definitions included
✅ **Promise-based API** - Modern async/await syntax
✅ **Retry Logic** - Built-in exponential backoff
✅ **Framework Agnostic** - Works with any Node.js test framework
✅ **Zero Dependencies** - Lightweight package
✅ **ESM & CommonJS** - Dual module format support

---

## Installation

### Via npm

```bash
npm install --save-dev @oxid-esales/paymentwatch-client
```

### Via yarn

```bash
yarn add --dev @oxid-esales/paymentwatch-client
```

### Via pnpm

```bash
pnpm add -D @oxid-esales/paymentwatch-client
```

---

## Quick Start

### TypeScript Example

```typescript
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});

// Wait for a condition
await client.waitFor(
  'osc_payment_contract.OXSTATE',
  'committed',
  {
    whereClause: { 'osc_payment_contract.OXID': contractId },
    timeout: 15000
  }
);

// Check a single assumption
const result = await client.assume(
  'osc_payment_transaction.OXSTATUS',
  'completed',
  {
    whereClause: { 'osc_payment_transaction.OXID': txnId }
  }
);

console.log(result.assumption); // true or false
```

### JavaScript Example (CommonJS)

```javascript
const { PaymentWatchClient } = require('@oxid-esales/paymentwatch-client');

const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: process.env.PAYMENTWATCH_API_KEY
});

async function testPayment() {
  const result = await client.assume(
    'oxorder.OXTRANSSTATUS',
    'OK',
    { whereClause: { 'oxorder.OXORDERNR': orderNumber } }
  );

  if (result.assumption) {
    console.log('Order completed successfully!');
  }
}

testPayment().catch(console.error);
```

---

## API Reference

### Constructor

```typescript
new PaymentWatchClient(config: ClientConfig)
```

**Parameters:**

```typescript
interface ClientConfig {
  baseUrl: string;           // PaymentWatch endpoint base URL
  apiKey: string;            // 64-character hex API key
  timeout?: number;          // Request timeout in ms (default: 30000)
  requestId?: string;        // Optional request ID for tracing
  fetch?: typeof fetch;      // Optional custom fetch implementation
}
```

**Example:**

```typescript
const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd',
  timeout: 20000,
  requestId: 'test-run-12345'
});
```

---

### `assume(field, expectedValue, options?)`

Check a single assumption against the database.

**Signature:**

```typescript
async assume(
  field: string,
  expectedValue: any,
  options?: AssumptionOptions
): Promise<AssumptionResult>
```

**Parameters:**

```typescript
interface AssumptionOptions {
  operator?: Operator;                    // Comparison operator
  whereClause?: Record<string, any>;      // WHERE clause filters
}

type Operator =
  | '==' | '!='
  | '>' | '<' | '>=' | '<='
  | '%like%' | 'like%' | '%like'
  | 'IS NULL' | 'IS NOT NULL';

interface AssumptionResult {
  assumption: boolean;           // True if condition met
  query_time_ms: number;         // Query execution time
  matched_rows: number;          // Number of matching rows
  actual_value?: any;            // Actual value (if assumption false)
  expected_value?: any;          // Expected value (if assumption false)
}
```

**Examples:**

```typescript
// Simple equality check
const result = await client.assume(
  'osc_payment_contract.OXSTATE',
  'committed',
  {
    whereClause: { 'osc_payment_contract.OXID': contractId }
  }
);

// Greater than comparison
const amountCheck = await client.assume(
  'osc_payment_transaction.OXAMOUNT',
  '100.00',
  {
    operator: '>',
    whereClause: { 'osc_payment_transaction.OXID': txnId }
  }
);

// LIKE pattern matching
const emailCheck = await client.assume(
  'oxorder.OXBILLEMAIL',
  '@example.com',
  {
    operator: '%like%',
    whereClause: { 'oxorder.OXID': orderId }
  }
);

// NULL check
const noOrderCheck = await client.assume(
  'osc_payment_contract.OXORDERID',
  null,
  {
    operator: 'IS NULL',
    whereClause: { 'osc_payment_contract.OXID': contractId }
  }
);
```

---

### `waitFor(field, expectedValue, options?)`

Poll for a condition with retry logic until it's met or timeout expires.

**Signature:**

```typescript
async waitFor(
  field: string,
  expectedValue: any,
  options?: WaitForOptions
): Promise<void>
```

**Parameters:**

```typescript
interface WaitForOptions extends AssumptionOptions {
  timeout?: number;          // Max wait time in ms (default: 30000)
  interval?: number;         // Poll interval in ms (default: 500)
  backoff?: boolean;         // Use exponential backoff (default: false)
  maxInterval?: number;      // Max backoff interval (default: 5000)
}
```

**Examples:**

```typescript
// Wait for contract state change
await client.waitFor(
  'osc_payment_contract.OXSTATE',
  'ready_to_commit',
  {
    whereClause: { 'osc_payment_contract.OXID': contractId },
    timeout: 15000,
    interval: 500
  }
);

// Wait with exponential backoff
await client.waitFor(
  'osc_payment_transaction.OXSTATUS',
  'completed',
  {
    whereClause: { 'osc_payment_transaction.OXPROVIDERORDERID': providerId },
    timeout: 30000,
    interval: 100,
    backoff: true,    // Enable exponential backoff
    maxInterval: 5000 // Cap at 5 seconds
  }
);
```

**Throws:** `TimeoutError` if condition not met within timeout

---

### `assertExists(field, whereClause)`

Assert that a field has a non-NULL value.

**Signature:**

```typescript
async assertExists(
  field: string,
  whereClause: Record<string, any>
): Promise<void>
```

**Examples:**

```typescript
// Assert order ID is set on contract
await client.assertExists(
  'osc_payment_contract.OXORDERID',
  { 'osc_payment_contract.OXID': contractId }
);
```

**Throws:** `AssertionError` if field is NULL

---

### `assertNotExists(field, whereClause)`

Assert that a field has a NULL value.

**Signature:**

```typescript
async assertNotExists(
  field: string,
  whereClause: Record<string, any>
): Promise<void>
```

**Examples:**

```typescript
// Assert no order created yet
await client.assertNotExists(
  'osc_payment_contract.OXORDERID',
  { 'osc_payment_contract.OXID': contractId }
);
```

**Throws:** `AssertionError` if field is NOT NULL

---

### `assertChain(assumptions)`

Check multiple assumptions in sequence, failing fast on first mismatch.

**Signature:**

```typescript
async assertChain(
  assumptions: Array<{
    field: string;
    value: any;
    operator?: Operator;
    whereClause?: Record<string, any>;
  }>
): Promise<void>
```

**Examples:**

```typescript
// Verify complete payment flow
await client.assertChain([
  {
    field: 'osc_payment_contract.OXSTATE',
    value: 'committed',
    whereClause: { 'osc_payment_contract.OXID': contractId }
  },
  {
    field: 'osc_payment_contract.OXORDERID',
    value: null,
    operator: 'IS NOT NULL',
    whereClause: { 'osc_payment_contract.OXID': contractId }
  },
  {
    field: 'oxorder.OXTRANSSTATUS',
    value: 'OK',
    whereClause: { 'oxorder.OXID': orderId }
  }
]);
```

**Throws:** `AssertionError` on first failed assumption

---

## Framework Integration Examples

### Playwright

```typescript
// playwright.config.ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  use: {
    baseURL: 'https://shop.example.com',
  },
  // ... other config
});

// tests/payment-flow.spec.ts
import { test, expect } from '@playwright/test';
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

test.describe('Payment Flow', () => {
  let paymentWatch: PaymentWatchClient;

  test.beforeEach(() => {
    paymentWatch = new PaymentWatchClient({
      baseUrl: 'https://shop.example.com',
      apiKey: process.env.PAYMENTWATCH_API_KEY!
    });
  });

  test('completes Stripe payment successfully', async ({ page }) => {
    // Navigate and trigger payment
    await page.goto('/checkout');
    await page.click('#payment-method-stripe');
    await page.click('#place-order');

    // Extract contract ID from redirect
    await page.waitForURL(/stripe\.com/);
    const contractId = await extractContractIdFromUrl(page);

    // Verify contract created
    const contractResult = await paymentWatch.assume(
      'osc_payment_contract.OXSTATE',
      'pending',
      { whereClause: { 'osc_payment_contract.OXID': contractId } }
    );
    expect(contractResult.assumption).toBe(true);

    // Complete payment
    await completeStripePayment(page);

    // Wait for authorization
    await paymentWatch.waitFor(
      'osc_payment_contract.OXSTATE',
      'ready_to_commit',
      {
        whereClause: { 'osc_payment_contract.OXID': contractId },
        timeout: 15000
      }
    );

    // Verify order created
    await paymentWatch.assertExists(
      'osc_payment_contract.OXORDERID',
      { 'osc_payment_contract.OXID': contractId }
    );
  });
});
```

---

### Cypress

```javascript
// cypress/support/commands.js
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

let paymentWatchClient;

before(() => {
  paymentWatchClient = new PaymentWatchClient({
    baseUrl: Cypress.env('SHOP_URL'),
    apiKey: Cypress.env('PAYMENTWATCH_API_KEY')
  });
});

Cypress.Commands.add('pwAssume', (field, value, options) => {
  return paymentWatchClient.assume(field, value, options);
});

Cypress.Commands.add('pwWaitFor', (field, value, options) => {
  return paymentWatchClient.waitFor(field, value, options);
});

// cypress/e2e/payment-flow.cy.js
describe('Payment Flow', () => {
  it('completes payment and creates order', () => {
    cy.visit('/checkout');
    cy.get('#payment-method-stripe').click();
    cy.get('#place-order').click();

    // Store contract ID
    cy.location('href').then((url) => {
      const contractId = extractContractId(url);

      // Verify contract state
      cy.pwWaitFor(
        'osc_payment_contract.OXSTATE',
        'pending',
        {
          whereClause: { 'osc_payment_contract.OXID': contractId },
          timeout: 10000
        }
      ).then(() => {
        cy.log('Contract created successfully');
      });
    });
  });
});
```

---

### Jest

```typescript
// __tests__/payment-flow.test.ts
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

describe('Payment Flow Integration', () => {
  let paymentWatch: PaymentWatchClient;

  beforeAll(() => {
    paymentWatch = new PaymentWatchClient({
      baseUrl: process.env.SHOP_URL!,
      apiKey: process.env.PAYMENTWATCH_API_KEY!
    });
  });

  it('should create contract and complete payment', async () => {
    // Trigger payment creation via API
    const response = await fetch('https://shop.example.com/api/payment/create', {
      method: 'POST',
      body: JSON.stringify({ amount: 99.99, currency: 'EUR' })
    });

    const { contractId } = await response.json();

    // Wait for contract to be ready
    await paymentWatch.waitFor(
      'osc_payment_contract.OXSTATE',
      'pending',
      {
        whereClause: { 'osc_payment_contract.OXID': contractId },
        timeout: 5000
      }
    );

    // Verify contract details
    const result = await paymentWatch.assume(
      'osc_payment_contract.OXBASKETAMOUNT',
      '99.99',
      {
        whereClause: { 'osc_payment_contract.OXID': contractId }
      }
    );

    expect(result.assumption).toBe(true);
  }, 30000); // 30 second timeout
});
```

---

### Mocha

```javascript
// test/payment-flow.test.js
const { PaymentWatchClient } = require('@oxid-esales/paymentwatch-client');
const { expect } = require('chai');

describe('Payment Flow', function() {
  this.timeout(30000); // 30 second timeout

  let paymentWatch;

  before(() => {
    paymentWatch = new PaymentWatchClient({
      baseUrl: process.env.SHOP_URL,
      apiKey: process.env.PAYMENTWATCH_API_KEY
    });
  });

  it('should verify transaction completed', async () => {
    const contractId = 'test-contract-123';

    // Wait for transaction
    await paymentWatch.waitFor(
      'osc_payment_transaction.OXSTATUS',
      'completed',
      {
        whereClause: {
          'osc_payment_transaction.OXCONTRACTID': contractId
        },
        timeout: 15000
      }
    );

    // Verify transaction details
    const result = await paymentWatch.assume(
      'osc_payment_transaction.OXAMOUNT',
      '99.99',
      {
        operator: '>=',
        whereClause: {
          'osc_payment_transaction.OXCONTRACTID': contractId
        }
      }
    );

    expect(result.assumption).to.be.true;
  });
});
```

---

## Advanced Usage

### Custom Fetch Implementation

```typescript
import fetch from 'node-fetch';
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: process.env.PAYMENTWATCH_API_KEY!,
  fetch: fetch as any  // Use node-fetch in Node.js < 18
});
```

---

### Request ID Tracing

```typescript
import { v4 as uuidv4 } from 'uuid';

const requestId = uuidv4();

const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: process.env.PAYMENTWATCH_API_KEY!,
  requestId: requestId  // Included in X-Request-ID header
});

// All requests will include this ID for tracing
await client.assume(...);
```

---

### Error Handling

```typescript
import {
  PaymentWatchClient,
  TimeoutError,
  AssertionError,
  ValidationError,
  AuthenticationError
} from '@oxid-esales/paymentwatch-client';

const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});

try {
  await client.waitFor(
    'osc_payment_contract.OXSTATE',
    'committed',
    {
      whereClause: { 'osc_payment_contract.OXID': contractId },
      timeout: 10000
    }
  );
} catch (error) {
  if (error instanceof TimeoutError) {
    console.error('Condition not met within timeout:', error.message);
    console.error('Last known value:', error.lastValue);
  } else if (error instanceof AssertionError) {
    console.error('Assertion failed:', error.message);
    console.error('Expected:', error.expected);
    console.error('Actual:', error.actual);
  } else if (error instanceof ValidationError) {
    console.error('Invalid request:', error.message);
  } else if (error instanceof AuthenticationError) {
    console.error('Auth failed:', error.message);
  } else {
    console.error('Unexpected error:', error);
  }
}
```

---

### Retry Configuration

```typescript
const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});

// Custom retry logic with exponential backoff
await client.waitFor(
  'osc_payment_transaction.OXSTATUS',
  'completed',
  {
    whereClause: { 'osc_payment_transaction.OXID': txnId },
    timeout: 30000,       // Max 30 seconds
    interval: 200,        // Start with 200ms
    backoff: true,        // Enable exponential backoff
    maxInterval: 5000     // Cap at 5 seconds
  }
);
// Polls at: 200ms, 400ms, 800ms, 1600ms, 3200ms, 5000ms, 5000ms, ...
```

---

## Package Structure

### package.json

```json
{
  "name": "@oxid-esales/paymentwatch-client",
  "version": "1.0.0",
  "description": "Official JavaScript/TypeScript client for PaymentWatch API",
  "main": "./dist/index.cjs",
  "module": "./dist/index.mjs",
  "types": "./dist/index.d.ts",
  "exports": {
    ".": {
      "types": "./dist/index.d.ts",
      "import": "./dist/index.mjs",
      "require": "./dist/index.cjs"
    }
  },
  "files": [
    "dist",
    "README.md",
    "LICENSE"
  ],
  "scripts": {
    "build": "tsup src/index.ts --format cjs,esm --dts",
    "test": "vitest",
    "typecheck": "tsc --noEmit",
    "lint": "eslint src --ext .ts"
  },
  "keywords": [
    "paymentwatch",
    "oxid",
    "e2e-testing",
    "payment-testing",
    "test-automation"
  ],
  "author": "OXID eSales AG",
  "license": "MIT",
  "repository": {
    "type": "git",
    "url": "https://github.com/OXID-eSales/paymentwatch-client"
  },
  "devDependencies": {
    "@types/node": "^20.0.0",
    "tsup": "^8.0.0",
    "typescript": "^5.3.0",
    "vitest": "^1.0.0"
  },
  "engines": {
    "node": ">=16.0.0"
  }
}
```

---

### TypeScript Configuration

```json
// tsconfig.json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "lib": ["ES2022"],
    "moduleResolution": "bundler",
    "declaration": true,
    "outDir": "./dist",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true
  },
  "include": ["src"],
  "exclude": ["node_modules", "dist"]
}
```

---

## Environment Variables

### Recommended Setup

```bash
# .env.test
SHOP_URL=https://shop.example.com
PAYMENTWATCH_API_KEY=a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd
```

### Load in Tests

```typescript
// Load environment variables
import * as dotenv from 'dotenv';
dotenv.config({ path: '.env.test' });

const client = new PaymentWatchClient({
  baseUrl: process.env.SHOP_URL!,
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});
```

---

## TypeScript Type Definitions

```typescript
// Full type definitions included

export interface ClientConfig {
  baseUrl: string;
  apiKey: string;
  timeout?: number;
  requestId?: string;
  fetch?: typeof fetch;
}

export type Operator =
  | '=='
  | '!='
  | '>'
  | '<'
  | '>='
  | '<='
  | '%like%'
  | 'like%'
  | '%like'
  | 'IS NULL'
  | 'IS NOT NULL';

export interface AssumptionOptions {
  operator?: Operator;
  whereClause?: Record<string, any>;
}

export interface WaitForOptions extends AssumptionOptions {
  timeout?: number;
  interval?: number;
  backoff?: boolean;
  maxInterval?: number;
}

export interface AssumptionResult {
  assumption: boolean;
  query_time_ms: number;
  matched_rows: number;
  actual_value?: any;
  expected_value?: any;
}

export class PaymentWatchClient {
  constructor(config: ClientConfig);

  assume(
    field: string,
    expectedValue: any,
    options?: AssumptionOptions
  ): Promise<AssumptionResult>;

  waitFor(
    field: string,
    expectedValue: any,
    options?: WaitForOptions
  ): Promise<void>;

  assertExists(
    field: string,
    whereClause: Record<string, any>
  ): Promise<void>;

  assertNotExists(
    field: string,
    whereClause: Record<string, any>
  ): Promise<void>;

  assertChain(
    assumptions: Array<{
      field: string;
      value: any;
      operator?: Operator;
      whereClause?: Record<string, any>;
    }>
  ): Promise<void>;
}

export class TimeoutError extends Error {
  constructor(message: string, public lastValue?: any);
}

export class AssertionError extends Error {
  constructor(
    message: string,
    public expected: any,
    public actual: any
  );
}

export class ValidationError extends Error {}
export class AuthenticationError extends Error {}
```

---

## Security Best Practices

### 1. API Key Management

```typescript
// ✅ Good: Use environment variables
const client = new PaymentWatchClient({
  baseUrl: process.env.SHOP_URL!,
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});

// ❌ Bad: Hardcoded API key
const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: 'a1b2c3d4e5f6...'  // Never commit this!
});
```

### 2. HTTPS Only

```typescript
// ✅ Good: HTTPS
const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',  // Secure
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});

// ❌ Bad: HTTP
const client = new PaymentWatchClient({
  baseUrl: 'http://shop.example.com',  // Insecure!
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});
```

### 3. Separate API Keys per Environment

```typescript
// Different keys for different environments
const apiKey = process.env.NODE_ENV === 'production'
  ? process.env.PROD_PAYMENTWATCH_API_KEY
  : process.env.TEST_PAYMENTWATCH_API_KEY;

const client = new PaymentWatchClient({
  baseUrl: process.env.SHOP_URL!,
  apiKey: apiKey!
});
```

---

## Troubleshooting

### Issue: "401 Unauthorized"

**Cause:** Invalid API key or IP not whitelisted

**Solution:**
```typescript
// Verify API key format (64-char hex)
console.log('API Key length:', process.env.PAYMENTWATCH_API_KEY?.length);
console.log('API Key format:', /^[a-f0-9]{64}$/i.test(process.env.PAYMENTWATCH_API_KEY!));

// Check IP whitelisting on server
```

---

### Issue: "TimeoutError"

**Cause:** Condition not met within timeout period

**Solution:**
```typescript
try {
  await client.waitFor(
    'osc_payment_contract.OXSTATE',
    'committed',
    {
      whereClause: { 'osc_payment_contract.OXID': contractId },
      timeout: 30000  // Increase timeout
    }
  );
} catch (error) {
  if (error instanceof TimeoutError) {
    console.error('Last value:', error.lastValue);
    // Check if state is stuck or progressing
  }
}
```

---

### Issue: "Connection Refused"

**Cause:** PaymentWatch module not activated or wrong URL

**Solution:**
```typescript
// Verify base URL
console.log('Base URL:', process.env.SHOP_URL);

// Test endpoint manually
const response = await fetch(`${process.env.SHOP_URL}/paymentwatch/assume`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-API-Key': process.env.PAYMENTWATCH_API_KEY!
  },
  body: JSON.stringify({
    assumption: {
      'oxorder.OXID': 'test'
    }
  })
});

console.log('Status:', response.status);
```

---

## Performance Tips

### 1. Reduce Poll Interval for Fast Operations

```typescript
// For quick operations (< 1 second)
await client.waitFor(
  'osc_payment_contract.OXSTATE',
  'pending',
  {
    whereClause: { 'osc_payment_contract.OXID': contractId },
    timeout: 5000,
    interval: 100  // Poll every 100ms
  }
);
```

### 2. Use Exponential Backoff for Slow Operations

```typescript
// For slow operations (webhooks, external APIs)
await client.waitFor(
  'osc_payment_transaction.OXSTATUS',
  'completed',
  {
    whereClause: { 'osc_payment_transaction.OXID': txnId },
    timeout: 60000,     // 1 minute
    interval: 500,      // Start at 500ms
    backoff: true,      // Enable exponential backoff
    maxInterval: 10000  // Cap at 10 seconds
  }
);
```

### 3. Batch Assumptions with assertChain

```typescript
// Instead of multiple assume() calls
await client.assertChain([
  { field: 'osc_payment_contract.OXSTATE', value: 'committed', whereClause: { ... } },
  { field: 'oxorder.OXTRANSSTATUS', value: 'OK', whereClause: { ... } },
  { field: 'osc_payment_transaction.OXSTATUS', value: 'completed', whereClause: { ... } }
]);
// Fails fast on first mismatch
```

---

## Changelog

### v1.0.0 (2025-11-11)

- Initial release
- TypeScript support with full type definitions
- Promise-based API with async/await
- Retry logic with exponential backoff
- Support for all PaymentWatch operators
- Framework integration examples (Playwright, Cypress, Jest, Mocha)
- Comprehensive error handling
- ESM and CommonJS dual module support

---

## Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Write tests for new functionality
4. Ensure all tests pass (`npm test`)
5. Commit with conventional commits (`feat:`, `fix:`, etc.)
6. Push to your fork and submit a Pull Request

---

## License

MIT License - See LICENSE file in repository root.

---

## Support

- **Issues:** https://github.com/OXID-eSales/paymentwatch-client/issues
- **Documentation:** https://docs.oxid-esales.com/paymentwatch
- **Email:** support@oxid-esales.com

---

## Related Documentation

- **[README.md](README.md)** - PaymentWatch API overview
- **[01-implementation-guide.md](01-implementation-guide.md)** - Server-side implementation
- **[02-test-scenarios.md](02-test-scenarios.md)** - E2E test patterns
- **[tdd/INDEX.md](tdd/INDEX.md)** - TDD guide for PaymentWatch development

---

**Happy Testing with PaymentWatch JavaScript SDK!** 🚀
