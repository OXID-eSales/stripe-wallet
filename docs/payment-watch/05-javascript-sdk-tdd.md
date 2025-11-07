# PaymentWatch JavaScript SDK - TDD Implementation Guide

**Test-Driven Development for the PaymentWatch Node.js Client**

Version: 1.0.0
Date: 2025-11-11
Repository: `https://github.com/OXID-eSales/paymentwatch-client`

---

## Overview

This guide demonstrates how to build the PaymentWatch JavaScript SDK using Test-Driven Development (TDD). We'll follow the **RED-GREEN-REFACTOR** cycle and set up complete CI/CD automation with GitHub Actions.

---

## Repository Setup

### 1. Create New Repository

```bash
# Create directory
mkdir paymentwatch-client
cd paymentwatch-client

# Initialize Git
git init
git branch -M main

# Create GitHub repository (via GitHub CLI or web interface)
gh repo create OXID-eSales/paymentwatch-client --public --source=. --remote=origin
```

---

### 2. Initialize Node.js Project

```bash
# Initialize package.json
npm init -y

# Install TypeScript and build tools
npm install --save-dev typescript tsup @types/node

# Install testing framework (Vitest)
npm install --save-dev vitest @vitest/ui

# Install code quality tools
npm install --save-dev eslint @typescript-eslint/parser @typescript-eslint/eslint-plugin
npm install --save-dev prettier eslint-config-prettier

# Install type checking
npm install --save-dev @types/jest
```

---

### 3. Project Structure

```
paymentwatch-client/
├── src/
│   ├── index.ts                  # Public API exports
│   ├── client.ts                 # PaymentWatchClient class
│   ├── types.ts                  # TypeScript type definitions
│   ├── errors.ts                 # Custom error classes
│   └── utils/
│       ├── retry.ts              # Retry logic with backoff
│       └── validator.ts          # Input validation
├── tests/
│   ├── unit/
│   │   ├── client.test.ts
│   │   ├── errors.test.ts
│   │   ├── retry.test.ts
│   │   └── validator.test.ts
│   ├── integration/
│   │   └── paymentwatch-api.test.ts
│   └── fixtures/
│       └── mock-responses.ts
├── .github/
│   └── workflows/
│       ├── ci.yml                # CI pipeline
│       ├── release.yml           # Automated releases
│       └── publish.yml           # NPM publishing
├── package.json
├── tsconfig.json
├── vitest.config.ts
├── .eslintrc.json
├── .prettierrc
├── .gitignore
├── README.md
└── LICENSE
```

---

## Configuration Files

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
    "build": "tsup src/index.ts --format cjs,esm --dts --clean",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:ui": "vitest --ui",
    "test:coverage": "vitest run --coverage",
    "typecheck": "tsc --noEmit",
    "lint": "eslint src tests --ext .ts",
    "lint:fix": "eslint src tests --ext .ts --fix",
    "format": "prettier --write \"src/**/*.ts\" \"tests/**/*.ts\"",
    "format:check": "prettier --check \"src/**/*.ts\" \"tests/**/*.ts\"",
    "prepublishOnly": "npm run lint && npm run typecheck && npm run test && npm run build"
  },
  "keywords": [
    "paymentwatch",
    "oxid",
    "e2e-testing",
    "payment-testing",
    "test-automation",
    "api-client"
  ],
  "author": "OXID eSales AG",
  "license": "MIT",
  "repository": {
    "type": "git",
    "url": "https://github.com/OXID-eSales/paymentwatch-client.git"
  },
  "bugs": {
    "url": "https://github.com/OXID-eSales/paymentwatch-client/issues"
  },
  "homepage": "https://github.com/OXID-eSales/paymentwatch-client#readme",
  "devDependencies": {
    "@types/node": "^20.10.0",
    "@typescript-eslint/eslint-plugin": "^6.15.0",
    "@typescript-eslint/parser": "^6.15.0",
    "@vitest/coverage-v8": "^1.0.4",
    "@vitest/ui": "^1.0.4",
    "eslint": "^8.56.0",
    "eslint-config-prettier": "^9.1.0",
    "prettier": "^3.1.1",
    "tsup": "^8.0.1",
    "typescript": "^5.3.3",
    "vitest": "^1.0.4"
  },
  "engines": {
    "node": ">=16.0.0"
  }
}
```

---

### tsconfig.json

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "lib": ["ES2022"],
    "moduleResolution": "bundler",
    "declaration": true,
    "declarationMap": true,
    "sourceMap": true,
    "outDir": "./dist",
    "rootDir": "./src",
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "noImplicitReturns": true,
    "noFallthroughCasesInSwitch": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "resolveJsonModule": true
  },
  "include": ["src"],
  "exclude": ["node_modules", "dist", "tests"]
}
```

---

### vitest.config.ts

```typescript
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: true,
    environment: 'node',
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html', 'lcov'],
      include: ['src/**/*.ts'],
      exclude: [
        'src/**/*.test.ts',
        'src/**/*.spec.ts',
        'src/index.ts'
      ],
      thresholds: {
        lines: 90,
        functions: 90,
        branches: 85,
        statements: 90
      }
    },
    testTimeout: 10000
  }
});
```

---

### .eslintrc.json

```json
{
  "parser": "@typescript-eslint/parser",
  "extends": [
    "eslint:recommended",
    "plugin:@typescript-eslint/recommended",
    "prettier"
  ],
  "parserOptions": {
    "ecmaVersion": 2022,
    "sourceType": "module",
    "project": "./tsconfig.json"
  },
  "rules": {
    "@typescript-eslint/explicit-function-return-type": "warn",
    "@typescript-eslint/no-explicit-any": "warn",
    "@typescript-eslint/no-unused-vars": ["error", { "argsIgnorePattern": "^_" }],
    "no-console": ["warn", { "allow": ["warn", "error"] }]
  }
}
```

---

### .prettierrc

```json
{
  "semi": true,
  "trailingComma": "es5",
  "singleQuote": true,
  "printWidth": 100,
  "tabWidth": 2,
  "useTabs": false
}
```

---

### .gitignore

```
# Dependencies
node_modules/
package-lock.json
yarn.lock
pnpm-lock.yaml

# Build output
dist/
build/
*.tsbuildinfo

# Coverage
coverage/
.nyc_output/

# IDE
.vscode/
.idea/
*.swp
*.swo

# Environment
.env
.env.local
.env.test

# Logs
logs/
*.log
npm-debug.log*

# OS
.DS_Store
Thumbs.db
```

---

## TDD Implementation: Phase 1 - Error Classes

### RED: Write Failing Test

```typescript
// tests/unit/errors.test.ts
import { describe, it, expect } from 'vitest';
import {
  TimeoutError,
  AssertionError,
  ValidationError,
  AuthenticationError
} from '../../src/errors';

describe('TimeoutError', () => {
  it('should create TimeoutError with message', () => {
    const error = new TimeoutError('Operation timed out');

    expect(error).toBeInstanceOf(Error);
    expect(error).toBeInstanceOf(TimeoutError);
    expect(error.message).toBe('Operation timed out');
    expect(error.name).toBe('TimeoutError');
  });

  it('should store last value when provided', () => {
    const error = new TimeoutError('Timeout waiting for state', 'pending');

    expect(error.lastValue).toBe('pending');
  });
});

describe('AssertionError', () => {
  it('should create AssertionError with expected and actual values', () => {
    const error = new AssertionError('Value mismatch', 'completed', 'pending');

    expect(error).toBeInstanceOf(Error);
    expect(error).toBeInstanceOf(AssertionError);
    expect(error.message).toBe('Value mismatch');
    expect(error.expected).toBe('completed');
    expect(error.actual).toBe('pending');
  });
});

describe('ValidationError', () => {
  it('should create ValidationError', () => {
    const error = new ValidationError('Invalid field name');

    expect(error).toBeInstanceOf(Error);
    expect(error.message).toBe('Invalid field name');
    expect(error.name).toBe('ValidationError');
  });
});

describe('AuthenticationError', () => {
  it('should create AuthenticationError', () => {
    const error = new AuthenticationError('Invalid API key');

    expect(error).toBeInstanceOf(Error);
    expect(error.message).toBe('Invalid API key');
    expect(error.name).toBe('AuthenticationError');
  });
});
```

**Run tests:**
```bash
npm test
# ❌ FAIL - Module not found
```

---

### GREEN: Make Tests Pass

```typescript
// src/errors.ts
export class TimeoutError extends Error {
  public readonly name = 'TimeoutError';
  public readonly lastValue?: any;

  constructor(message: string, lastValue?: any) {
    super(message);
    this.lastValue = lastValue;
    Object.setPrototypeOf(this, TimeoutError.prototype);
  }
}

export class AssertionError extends Error {
  public readonly name = 'AssertionError';
  public readonly expected: any;
  public readonly actual: any;

  constructor(message: string, expected: any, actual: any) {
    super(message);
    this.expected = expected;
    this.actual = actual;
    Object.setPrototypeOf(this, AssertionError.prototype);
  }
}

export class ValidationError extends Error {
  public readonly name = 'ValidationError';

  constructor(message: string) {
    super(message);
    Object.setPrototypeOf(this, ValidationError.prototype);
  }
}

export class AuthenticationError extends Error {
  public readonly name = 'AuthenticationError';

  constructor(message: string) {
    super(message);
    Object.setPrototypeOf(this, AuthenticationError.prototype);
  }
}
```

**Run tests:**
```bash
npm test
# ✅ PASS - All error tests passing
```

---

### REFACTOR: Improve Implementation

```typescript
// src/errors.ts - Extract base class
abstract class PaymentWatchError extends Error {
  constructor(message: string) {
    super(message);
    Object.setPrototypeOf(this, new.target.prototype);
  }
}

export class TimeoutError extends PaymentWatchError {
  public readonly name = 'TimeoutError';

  constructor(
    message: string,
    public readonly lastValue?: any
  ) {
    super(message);
  }
}

export class AssertionError extends PaymentWatchError {
  public readonly name = 'AssertionError';

  constructor(
    message: string,
    public readonly expected: any,
    public readonly actual: any
  ) {
    super(message);
  }
}

export class ValidationError extends PaymentWatchError {
  public readonly name = 'ValidationError';
}

export class AuthenticationError extends PaymentWatchError {
  public readonly name = 'AuthenticationError';
}
```

**Run tests:**
```bash
npm test
# ✅ PASS - All tests still passing after refactor
```

---

## TDD Implementation: Phase 2 - Type Definitions

### RED: Write Failing Test

```typescript
// tests/unit/types.test.ts
import { describe, it, expect } from 'vitest';
import type { ClientConfig, Operator, AssumptionOptions } from '../../src/types';

describe('Type Definitions', () => {
  it('should allow valid ClientConfig', () => {
    const config: ClientConfig = {
      baseUrl: 'https://shop.example.com',
      apiKey: 'a'.repeat(64)
    };

    expect(config.baseUrl).toBeDefined();
    expect(config.apiKey).toBeDefined();
  });

  it('should allow optional ClientConfig fields', () => {
    const config: ClientConfig = {
      baseUrl: 'https://shop.example.com',
      apiKey: 'a'.repeat(64),
      timeout: 30000,
      requestId: 'test-123'
    };

    expect(config.timeout).toBe(30000);
    expect(config.requestId).toBe('test-123');
  });

  it('should accept all valid operators', () => {
    const operators: Operator[] = [
      '==', '!=', '>', '<', '>=', '<=',
      '%like%', 'like%', '%like',
      'IS NULL', 'IS NOT NULL'
    ];

    operators.forEach(op => {
      expect(op).toBeDefined();
    });
  });

  it('should allow AssumptionOptions', () => {
    const options: AssumptionOptions = {
      operator: '==',
      whereClause: { 'table.field': 'value' }
    };

    expect(options.operator).toBe('==');
    expect(options.whereClause).toBeDefined();
  });
});
```

**Run tests:**
```bash
npm test
# ❌ FAIL - Types not found
```

---

### GREEN: Define Types

```typescript
// src/types.ts
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

export interface AssumptionChainItem {
  field: string;
  value: any;
  operator?: Operator;
  whereClause?: Record<string, any>;
}
```

**Run tests:**
```bash
npm test
# ✅ PASS
```

---

## TDD Implementation: Phase 3 - Retry Logic

### RED: Write Failing Test

```typescript
// tests/unit/retry.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { retryWithBackoff } from '../../src/utils/retry';

describe('retryWithBackoff', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  it('should succeed on first attempt', async () => {
    const fn = vi.fn().mockResolvedValue('success');

    const resultPromise = retryWithBackoff(fn, {
      timeout: 5000,
      interval: 100,
      shouldRetry: () => true
    });

    await vi.runAllTimersAsync();
    const result = await resultPromise;

    expect(result).toBe('success');
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it('should retry on failure until success', async () => {
    const fn = vi.fn()
      .mockResolvedValueOnce('pending')  // First call
      .mockResolvedValueOnce('pending')  // Second call
      .mockResolvedValue('completed');    // Third call succeeds

    const resultPromise = retryWithBackoff(fn, {
      timeout: 10000,
      interval: 100,
      shouldRetry: (result) => result === 'pending'
    });

    await vi.runAllTimersAsync();
    const result = await resultPromise;

    expect(result).toBe('completed');
    expect(fn).toHaveBeenCalledTimes(3);
  });

  it('should timeout when condition never met', async () => {
    const fn = vi.fn().mockResolvedValue('pending');

    const resultPromise = retryWithBackoff(fn, {
      timeout: 1000,
      interval: 100,
      shouldRetry: () => true
    });

    await vi.runAllTimersAsync();

    await expect(resultPromise).rejects.toThrow('Timeout');
  });

  it('should use exponential backoff', async () => {
    const fn = vi.fn().mockResolvedValue('pending');
    const delays: number[] = [];

    vi.spyOn(global, 'setTimeout').mockImplementation((callback: any, delay: number) => {
      delays.push(delay);
      return setTimeout(callback, 0);
    });

    const resultPromise = retryWithBackoff(fn, {
      timeout: 5000,
      interval: 100,
      backoff: true,
      shouldRetry: () => true
    });

    await vi.runAllTimersAsync();
    await resultPromise.catch(() => {});

    // Should increase: 100, 200, 400, 800, ...
    expect(delays[0]).toBe(100);
    expect(delays[1]).toBe(200);
    expect(delays[2]).toBe(400);
  });

  it('should cap backoff at maxInterval', async () => {
    const fn = vi.fn().mockResolvedValue('pending');
    const delays: number[] = [];

    vi.spyOn(global, 'setTimeout').mockImplementation((callback: any, delay: number) => {
      delays.push(delay);
      return setTimeout(callback, 0);
    });

    const resultPromise = retryWithBackoff(fn, {
      timeout: 10000,
      interval: 1000,
      backoff: true,
      maxInterval: 3000,
      shouldRetry: () => true
    });

    await vi.runAllTimersAsync();
    await resultPromise.catch(() => {});

    // Should cap at 3000: 1000, 2000, 3000, 3000, 3000, ...
    expect(delays[0]).toBe(1000);
    expect(delays[1]).toBe(2000);
    expect(delays[2]).toBe(3000);
    expect(delays[3]).toBe(3000);
  });
});
```

**Run tests:**
```bash
npm test
# ❌ FAIL - Module not found
```

---

### GREEN: Implement Retry Logic

```typescript
// src/utils/retry.ts
import { TimeoutError } from '../errors';

export interface RetryOptions<T> {
  timeout: number;
  interval: number;
  backoff?: boolean;
  maxInterval?: number;
  shouldRetry: (result: T) => boolean;
}

export async function retryWithBackoff<T>(
  fn: () => Promise<T>,
  options: RetryOptions<T>
): Promise<T> {
  const { timeout, interval, backoff = false, maxInterval = 5000, shouldRetry } = options;

  const startTime = Date.now();
  let currentInterval = interval;
  let lastResult: T | undefined;

  while (Date.now() - startTime < timeout) {
    const result = await fn();
    lastResult = result;

    if (!shouldRetry(result)) {
      return result;
    }

    await sleep(currentInterval);

    if (backoff) {
      currentInterval = Math.min(currentInterval * 2, maxInterval);
    }
  }

  throw new TimeoutError(
    `Operation timed out after ${timeout}ms`,
    lastResult
  );
}

function sleep(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms));
}
```

**Run tests:**
```bash
npm test
# ✅ PASS
```

---

## TDD Implementation: Phase 4 - PaymentWatchClient

### RED: Write Client Tests

```typescript
// tests/unit/client.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { PaymentWatchClient } from '../../src/client';
import type { AssumptionResult } from '../../src/types';

describe('PaymentWatchClient', () => {
  let client: PaymentWatchClient;
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn();
    global.fetch = fetchMock;

    client = new PaymentWatchClient({
      baseUrl: 'https://shop.example.com',
      apiKey: 'a'.repeat(64)
    });
  });

  describe('assume()', () => {
    it('should make correct API request', async () => {
      const mockResponse: AssumptionResult = {
        assumption: true,
        query_time_ms: 10.5,
        matched_rows: 1
      };

      fetchMock.mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => mockResponse
      });

      const result = await client.assume(
        'osc_payment_contract.OXSTATE',
        'committed',
        {
          whereClause: { 'osc_payment_contract.OXID': 'contract-123' }
        }
      );

      expect(result.assumption).toBe(true);
      expect(fetchMock).toHaveBeenCalledWith(
        'https://shop.example.com/paymentwatch/assume',
        expect.objectContaining({
          method: 'POST',
          headers: expect.objectContaining({
            'Content-Type': 'application/json',
            'X-API-Key': 'a'.repeat(64)
          }),
          body: JSON.stringify({
            assumption: {
              'osc_payment_contract.OXSTATE': 'committed',
              where: {
                'osc_payment_contract.OXID': 'contract-123'
              }
            }
          })
        })
      );
    });

    it('should include operator when provided', async () => {
      fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ assumption: true, query_time_ms: 5, matched_rows: 1 })
      });

      await client.assume(
        'osc_payment_transaction.OXAMOUNT',
        '100.00',
        {
          operator: '>=',
          whereClause: { 'osc_payment_transaction.OXID': 'txn-123' }
        }
      );

      const requestBody = JSON.parse(fetchMock.mock.calls[0][1].body);
      expect(requestBody.assumption.op).toBe('>=');
    });

    it('should throw AuthenticationError on 401', async () => {
      fetchMock.mockResolvedValue({
        ok: false,
        status: 401,
        json: async () => ({ error: 'Unauthorized' })
      });

      await expect(
        client.assume('oxorder.OXID', 'test')
      ).rejects.toThrow('Unauthorized');
    });

    it('should throw ValidationError on 400', async () => {
      fetchMock.mockResolvedValue({
        ok: false,
        status: 400,
        json: async () => ({ error: 'Invalid field name' })
      });

      await expect(
        client.assume('invalid.field', 'test')
      ).rejects.toThrow('Invalid field name');
    });
  });

  describe('waitFor()', () => {
    beforeEach(() => {
      vi.useFakeTimers();
    });

    it('should resolve when condition met', async () => {
      fetchMock
        .mockResolvedValueOnce({
          ok: true,
          json: async () => ({ assumption: false, query_time_ms: 5, matched_rows: 0 })
        })
        .mockResolvedValueOnce({
          ok: true,
          json: async () => ({ assumption: true, query_time_ms: 5, matched_rows: 1 })
        });

      const waitPromise = client.waitFor(
        'osc_payment_contract.OXSTATE',
        'committed',
        {
          whereClause: { 'osc_payment_contract.OXID': 'contract-123' },
          timeout: 10000,
          interval: 100
        }
      );

      await vi.runAllTimersAsync();
      await waitPromise;

      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('should timeout when condition not met', async () => {
      fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ assumption: false, query_time_ms: 5, matched_rows: 0 })
      });

      const waitPromise = client.waitFor(
        'osc_payment_contract.OXSTATE',
        'committed',
        {
          timeout: 1000,
          interval: 100
        }
      );

      await vi.runAllTimersAsync();

      await expect(waitPromise).rejects.toThrow('Timeout');
    });
  });

  describe('assertExists()', () => {
    it('should pass when field is not NULL', async () => {
      fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ assumption: true, query_time_ms: 5, matched_rows: 1 })
      });

      await expect(
        client.assertExists(
          'osc_payment_contract.OXORDERID',
          { 'osc_payment_contract.OXID': 'contract-123' }
        )
      ).resolves.toBeUndefined();
    });

    it('should throw AssertionError when field is NULL', async () => {
      fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ assumption: false, query_time_ms: 5, matched_rows: 0 })
      });

      await expect(
        client.assertExists(
          'osc_payment_contract.OXORDERID',
          { 'osc_payment_contract.OXID': 'contract-123' }
        )
      ).rejects.toThrow('Expected field to exist (NOT NULL)');
    });
  });

  describe('assertChain()', () => {
    it('should check all assumptions in sequence', async () => {
      fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ assumption: true, query_time_ms: 5, matched_rows: 1 })
      });

      await client.assertChain([
        {
          field: 'osc_payment_contract.OXSTATE',
          value: 'committed',
          whereClause: { 'osc_payment_contract.OXID': 'contract-123' }
        },
        {
          field: 'oxorder.OXTRANSSTATUS',
          value: 'OK',
          whereClause: { 'oxorder.OXID': 'order-123' }
        }
      ]);

      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('should fail fast on first mismatch', async () => {
      fetchMock
        .mockResolvedValueOnce({
          ok: true,
          json: async () => ({ assumption: true, query_time_ms: 5, matched_rows: 1 })
        })
        .mockResolvedValueOnce({
          ok: true,
          json: async () => ({
            assumption: false,
            query_time_ms: 5,
            matched_rows: 0,
            actual_value: 'pending',
            expected_value: 'committed'
          })
        });

      await expect(
        client.assertChain([
          { field: 'field1', value: 'value1' },
          { field: 'field2', value: 'value2' },
          { field: 'field3', value: 'value3' }
        ])
      ).rejects.toThrow();

      // Should only call twice (stops at second failure)
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });
  });
});
```

**Run tests:**
```bash
npm test
# ❌ FAIL - Client not implemented
```

---

### GREEN: Implement Client (Simplified)

```typescript
// src/client.ts
import type {
  ClientConfig,
  AssumptionOptions,
  WaitForOptions,
  AssumptionResult,
  AssumptionChainItem
} from './types';
import {
  TimeoutError,
  AssertionError,
  ValidationError,
  AuthenticationError
} from './errors';
import { retryWithBackoff } from './utils/retry';

export class PaymentWatchClient {
  private readonly baseUrl: string;
  private readonly apiKey: string;
  private readonly timeout: number;
  private readonly requestId?: string;
  private readonly fetchFn: typeof fetch;

  constructor(config: ClientConfig) {
    this.baseUrl = config.baseUrl;
    this.apiKey = config.apiKey;
    this.timeout = config.timeout ?? 30000;
    this.requestId = config.requestId;
    this.fetchFn = config.fetch ?? fetch;
  }

  async assume(
    field: string,
    expectedValue: any,
    options: AssumptionOptions = {}
  ): Promise<AssumptionResult> {
    const { operator, whereClause } = options;

    const payload: any = {
      assumption: {
        [field]: expectedValue
      }
    };

    if (operator && operator !== '==') {
      payload.assumption.op = operator;
    }

    if (whereClause) {
      payload.assumption.where = whereClause;
    }

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'X-API-Key': this.apiKey
    };

    if (this.requestId) {
      headers['X-Request-ID'] = this.requestId;
    }

    const response = await this.fetchFn(
      `${this.baseUrl}/paymentwatch/assume`,
      {
        method: 'POST',
        headers,
        body: JSON.stringify(payload)
      }
    );

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));
      const errorMessage = errorData.error || `Request failed with status ${response.status}`;

      if (response.status === 401) {
        throw new AuthenticationError(errorMessage);
      } else if (response.status === 400) {
        throw new ValidationError(errorMessage);
      } else {
        throw new Error(errorMessage);
      }
    }

    return await response.json();
  }

  async waitFor(
    field: string,
    expectedValue: any,
    options: WaitForOptions = {}
  ): Promise<void> {
    const {
      operator,
      whereClause,
      timeout = 30000,
      interval = 500,
      backoff = false,
      maxInterval = 5000
    } = options;

    await retryWithBackoff(
      async () => {
        return await this.assume(field, expectedValue, { operator, whereClause });
      },
      {
        timeout,
        interval,
        backoff,
        maxInterval,
        shouldRetry: (result) => !result.assumption
      }
    );
  }

  async assertExists(
    field: string,
    whereClause: Record<string, any>
  ): Promise<void> {
    const result = await this.assume(field, null, {
      operator: 'IS NOT NULL',
      whereClause
    });

    if (!result.assumption) {
      throw new AssertionError(
        `Expected field ${field} to exist (NOT NULL)`,
        'NOT NULL',
        'NULL'
      );
    }
  }

  async assertNotExists(
    field: string,
    whereClause: Record<string, any>
  ): Promise<void> {
    const result = await this.assume(field, null, {
      operator: 'IS NULL',
      whereClause
    });

    if (!result.assumption) {
      throw new AssertionError(
        `Expected field ${field} to not exist (NULL)`,
        'NULL',
        'NOT NULL'
      );
    }
  }

  async assertChain(assumptions: AssumptionChainItem[]): Promise<void> {
    for (const item of assumptions) {
      const result = await this.assume(item.field, item.value, {
        operator: item.operator,
        whereClause: item.whereClause
      });

      if (!result.assumption) {
        throw new AssertionError(
          `Chained assumption failed: ${item.field}`,
          item.value,
          result.actual_value
        );
      }
    }
  }
}
```

---

### REFACTOR: Extract to index.ts

```typescript
// src/index.ts
export { PaymentWatchClient } from './client';
export type {
  ClientConfig,
  Operator,
  AssumptionOptions,
  WaitForOptions,
  AssumptionResult,
  AssumptionChainItem
} from './types';
export {
  TimeoutError,
  AssertionError,
  ValidationError,
  AuthenticationError
} from './errors';
```

**Run tests:**
```bash
npm test
# ✅ PASS - All tests passing!

npm run build
# ✅ Build successful

npm run typecheck
# ✅ No type errors
```

---

## GitHub CI/CD Setup

### .github/workflows/ci.yml

```yaml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]

jobs:
  test:
    name: Test on Node ${{ matrix.node }}
    runs-on: ubuntu-latest

    strategy:
      matrix:
        node: [16, 18, 20]

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: ${{ matrix.node }}
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: Lint code
        run: npm run lint

      - name: Check formatting
        run: npm run format:check

      - name: Type check
        run: npm run typecheck

      - name: Run tests
        run: npm run test:coverage

      - name: Upload coverage to Codecov
        uses: codecov/codecov-action@v3
        if: matrix.node == '20'
        with:
          files: ./coverage/lcov.info
          flags: unittests
          name: codecov-umbrella

      - name: Build package
        run: npm run build

      - name: Test build output
        run: |
          test -f dist/index.cjs
          test -f dist/index.mjs
          test -f dist/index.d.ts

  integration-test:
    name: Integration Tests
    runs-on: ubuntu-latest

    services:
      paymentwatch:
        image: ghcr.io/oxid-esales/paymentwatch-test-server:latest
        ports:
          - 8080:80
        env:
          PAYMENTWATCH_API_KEY: ${{ secrets.TEST_API_KEY }}

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: Run integration tests
        run: npm run test:integration
        env:
          PAYMENTWATCH_BASE_URL: http://localhost:8080
          PAYMENTWATCH_API_KEY: ${{ secrets.TEST_API_KEY }}
```

---

### .github/workflows/release.yml

```yaml
name: Release

on:
  push:
    tags:
      - 'v*'

jobs:
  release:
    name: Create Release
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          registry-url: 'https://registry.npmjs.org'

      - name: Install dependencies
        run: npm ci

      - name: Run tests
        run: npm test

      - name: Build package
        run: npm run build

      - name: Publish to NPM
        run: npm publish --access public
        env:
          NODE_AUTH_TOKEN: ${{ secrets.NPM_TOKEN }}

      - name: Create GitHub Release
        uses: actions/create-release@v1
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        with:
          tag_name: ${{ github.ref }}
          release_name: Release ${{ github.ref }}
          draft: false
          prerelease: false
```

---

### .github/workflows/publish.yml

```yaml
name: Publish Package

on:
  release:
    types: [published]

jobs:
  build:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          registry-url: 'https://registry.npmjs.org'

      - run: npm ci
      - run: npm test
      - run: npm run build

      - run: npm publish --access public
        env:
          NODE_AUTH_TOKEN: ${{ secrets.NPM_TOKEN }}
```

---

## Development Workflow

### 1. Feature Development (TDD)

```bash
# Create feature branch
git checkout -b feature/add-custom-headers

# RED: Write failing test
npm run test:watch  # Keep running

# Edit tests/unit/client.test.ts
it('should include custom headers', async () => {
  // ... test code
});

# GREEN: Implement feature
# Edit src/client.ts

# REFACTOR: Improve code
npm run lint:fix
npm run format

# Verify
npm test
npm run typecheck
npm run build

# Commit
git add .
git commit -m "feat: add custom header support"
git push origin feature/add-custom-headers
```

---

### 2. Pull Request Flow

1. **Create PR** on GitHub
2. **CI runs automatically:**
   - Lint check
   - Type check
   - Unit tests (Node 16, 18, 20)
   - Coverage check (>90%)
   - Build verification
3. **Review & merge**
4. **Auto-deploy** on release tag

---

### 3. Release Process

```bash
# Update version
npm version patch  # or minor, major

# Push with tags
git push origin main --tags

# GitHub Actions automatically:
# - Runs all tests
# - Builds package
# - Publishes to NPM
# - Creates GitHub release
```

---

## Testing Strategy

### Test Coverage Goals

- **Unit tests:** 100% of business logic
- **Integration tests:** Real API calls with test server
- **E2E tests:** Full flow with Docker environment

### Test Organization

```
tests/
├── unit/                      # Fast, isolated tests
│   ├── client.test.ts         # 100% coverage target
│   ├── errors.test.ts         # All error classes
│   ├── retry.test.ts          # Retry logic
│   └── validator.test.ts      # Input validation
├── integration/               # Real API tests
│   └── paymentwatch-api.test.ts
└── fixtures/                  # Mock data
    └── mock-responses.ts
```

---

## Continuous Integration Benefits

### ✅ Automated Quality Checks

- **Linting:** Code style enforcement
- **Type checking:** Catch type errors before runtime
- **Tests:** Verify functionality
- **Coverage:** Ensure sufficient test coverage
- **Build:** Verify package can be built

### ✅ Multi-Node Version Testing

- **Node 16, 18, 20:** Ensure compatibility
- **Latest LTS:** Production target
- **Future versions:** Early compatibility check

### ✅ Automated Releases

- **Semantic versioning:** patch, minor, major
- **NPM publishing:** Automatic on tag push
- **GitHub releases:** Changelog and assets
- **Changelog:** Auto-generated from commits

---

## Next Steps

1. **Implement remaining features:**
   - Request validation
   - Custom headers support
   - Batch requests

2. **Add integration tests:**
   - Real PaymentWatch server
   - Docker test environment
   - E2E payment flows

3. **Documentation:**
   - API reference (TSDoc)
   - Usage examples
   - Migration guides

4. **Release:**
   - Publish to NPM
   - GitHub release
   - Announce to users

---

## Related Documentation

- **[04-javascript-sdk.md](04-javascript-sdk.md)** - JavaScript SDK API reference
- **[README.md](README.md)** - PaymentWatch API overview
- **[02-test-scenarios.md](02-test-scenarios.md)** - E2E test patterns
- **[tdd/INDEX.md](tdd/INDEX.md)** - TDD guide for server-side implementation

---

**Build with confidence using TDD!** 🧪✅
