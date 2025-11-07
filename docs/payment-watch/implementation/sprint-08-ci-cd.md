# Sprint 8: JavaScript SDK CI/CD

**Duration:** 1 week
**Team:** 1-2 developers (1 DevOps engineer)
**Prerequisites:** Sprint 7 complete (TypeScript SDK with 33+ tests)

---

## Sprint Overview

### Goal
Set up **Continuous Integration and Continuous Deployment (CI/CD)** for the JavaScript SDK with:
- **GitHub Actions workflows** for automated testing
- **NPM publishing automation** with semantic versioning
- **Codecov integration** for coverage reporting
- **Multi-version Node.js testing** (Node 16, 18, 20)
- **README and documentation** for NPM package

### Key Deliverables
1. `.github/workflows/ci.yml` - Continuous Integration
2. `.github/workflows/release.yml` - Automated NPM publishing
3. `README.md` - Package documentation with examples
4. Codecov configuration
5. NPM package published to `@oxid-esales/paymentwatch-client`

---

## Task 8.1: GitHub Actions CI Workflow

**Time Estimate:** 2 hours

### Create CI Workflow

```bash
mkdir -p .github/workflows
cat > .github/workflows/ci.yml << 'EOF'
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]

jobs:
  lint:
    name: Lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npm run lint

  test:
    name: Test (Node ${{ matrix.node-version }})
    runs-on: ubuntu-latest
    strategy:
      matrix:
        node-version: [16, 18, 20]
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup Node.js ${{ matrix.node-version }}
        uses: actions/setup-node@v4
        with:
          node-version: ${{ matrix.node-version }}
      
      - name: Install dependencies
        run: npm ci
      
      - name: Run tests
        run: npm test
      
      - name: Generate coverage
        if: matrix.node-version == 20
        run: npm run test:coverage
      
      - name: Upload coverage to Codecov
        if: matrix.node-version == 20
        uses: codecov/codecov-action@v3
        with:
          token: ${{ secrets.CODECOV_TOKEN }}
          files: ./coverage/coverage-final.json
          flags: unittests
          name: codecov-umbrella
          fail_ci_if_error: true

  build:
    name: Build
    runs-on: ubuntu-latest
    needs: [lint, test]
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npm run build
      
      - name: Verify build artifacts
        run: |
          test -f dist/index.js
          test -f dist/index.mjs
          test -f dist/index.d.ts
          echo "✅ All build artifacts present"
      
      - name: Upload build artifacts
        uses: actions/upload-artifact@v3
        with:
          name: dist
          path: dist/
EOF
```

---

## Task 8.2: Release Workflow

**Time Estimate:** 3 hours

### Create Release Workflow

```bash
cat > .github/workflows/release.yml << 'EOF'
name: Release

on:
  push:
    tags:
      - 'v*.*.*'

permissions:
  contents: write
  packages: write

jobs:
  release:
    name: Release to NPM
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
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
          tag_name: ${{ github.ref_name }}
          release_name: Release ${{ github.ref_name }}
          body: |
            ## Changes in ${{ github.ref_name }}
            
            See [CHANGELOG.md](CHANGELOG.md) for details.
            
            ## Installation
            
            ```bash
            npm install @oxid-esales/paymentwatch-client@${{ github.ref_name }}
            ```
          draft: false
          prerelease: false
EOF
```

---

## Task 8.3: README Documentation

**Time Estimate:** 3 hours

### Create Comprehensive README

```bash
cat > README.md << 'EOF'
# PaymentWatch Client

[![npm version](https://badge.fury.io/js/@oxid-esales%2Fpaymentwatch-client.svg)](https://www.npmjs.com/package/@oxid-esales/paymentwatch-client)
[![CI Status](https://github.com/OXID-eSales/paymentwatch-client/workflows/CI/badge.svg)](https://github.com/OXID-eSales/paymentwatch-client/actions)
[![codecov](https://codecov.io/gh/OXID-eSales/paymentwatch-client/branch/main/graph/badge.svg)](https://codecov.io/gh/OXID-eSales/paymentwatch-client)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

TypeScript/JavaScript client for PaymentWatch E2E testing framework.

PaymentWatch allows you to assert database state during E2E payment tests, eliminating sleep() calls and flaky tests.

---

## Installation

```bash
npm install @oxid-esales/paymentwatch-client
```

---

## Quick Start

### Playwright Example

```typescript
import { test, expect } from '@playwright/test';
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

const client = new PaymentWatchClient({
  baseUrl: 'http://localhost/paymentwatch',
  apiKey: process.env.PAYMENTWATCH_API_KEY!
});

test('complete payment creates order', async ({ page }) => {
  // 1. Complete checkout
  await page.goto('/checkout');
  await page.fill('#email', 'test@example.com');
  await page.click('#submit-payment');

  // 2. Wait for order to be created in database
  await client.waitFor({
    table: 'oxorder',
    field: 'oxbillemail',
    value: 'test@example.com',
    operator: '==',
    timeout: 30000  // 30 seconds
  });

  // 3. Assert payment transaction completed
  await client.assertExists({
    table: 'oepaypal_order',
    field: 'oxtransactionstatus',
    value: 'completed',
    where: {
      oxbillemail: 'test@example.com'
    }
  });

  // 4. Assert order is paid
  await client.assertExists({
    table: 'oxorder',
    field: 'oxpaid',
    value: '0000-00-00 00:00:00',
    operator: '!='
  });
});
```

---

## API Reference

### Constructor

```typescript
const client = new PaymentWatchClient(config: ClientConfig);
```

**ClientConfig:**
```typescript
interface ClientConfig {
  baseUrl: string;        // PaymentWatch API URL
  apiKey: string;         // Authentication API key
  timeout?: number;       // Request timeout (default: 30000ms)
  retry?: {
    maxRetries?: number;      // Max retries (default: 3)
    initialDelay?: number;    // Initial delay (default: 1000ms)
    maxDelay?: number;        // Max delay (default: 10000ms)
    backoffFactor?: number;   // Backoff multiplier (default: 2)
  };
}
```

---

### Methods

#### `assume(options)`

Make a single assumption check (returns immediately).

```typescript
const result = await client.assume({
  table: 'oxorder',
  field: 'oxordernr',
  value: '12345',
  operator: '=='
});

if (result.success) {
  console.log('Order exists!');
}
```

**Returns:** `AssumptionResult`
```typescript
interface AssumptionResult {
  success: boolean;
  message: string;
  requestId: string;
}
```

---

#### `waitFor(options)`

Poll until assumption passes or timeout.

```typescript
await client.waitFor({
  table: 'oxorder',
  field: 'oxpaid',
  value: '0000-00-00 00:00:00',
  operator: '!=',
  timeout: 30000,   // Max wait time
  interval: 1000    // Poll every 1 second
});
```

**Throws:** `TimeoutError` if timeout exceeded

---

#### `assertExists(options)`

Assert assumption passes (throws on failure).

```typescript
await client.assertExists({
  table: 'oxorder',
  field: 'oxordernr',
  value: '12345'
});
```

**Throws:** `AssertionError` if assumption fails

---

#### `assertChain(assumptions)`

Assert multiple assumptions in sequence.

```typescript
await client.assertChain([
  { table: 'oxorder', field: 'oxordernr', value: '12345' },
  { table: 'oxorder', field: 'oxstorno', value: 0 },
  { table: 'oepaypal_order', field: 'oxtransactionstatus', value: 'completed' }
]);
```

**Throws:** `AssertionError` on first failure

---

## Supported Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `==` | Equals (default) | `value: '12345'` |
| `!=` | Not equals | `value: 0` |
| `>` | Greater than | `value: 100.00` |
| `<` | Less than | `value: 50.00` |
| `>=` | Greater or equal | `value: 99.99` |
| `<=` | Less or equal | `value: 0.01` |
| `%like%` | Contains | `value: '@example.com'` |
| `like%` | Starts with | `value: '2024'` |
| `%like` | Ends with | `value: '.pdf'` |
| `IS NULL` | Is null | `value: null` |
| `IS NOT NULL` | Is not null | `value: null` |
| `IN` | In array | `value: ['paypal', 'stripe']` |
| `NOT IN` | Not in array | `value: [0, 1]` |

---

## Error Handling

```typescript
import {
  TimeoutError,
  AssertionError,
  ValidationError,
  AuthenticationError
} from '@oxid-esales/paymentwatch-client';

try {
  await client.waitFor({ ... }, { timeout: 5000 });
} catch (error) {
  if (error instanceof TimeoutError) {
    console.error('Timeout:', error.timeout);
  } else if (error instanceof AssertionError) {
    console.error('Assertion failed:', error.details);
  } else if (error instanceof AuthenticationError) {
    console.error('Auth failed:', error.statusCode);
  }
}
```

---

## Integration Examples

### Cypress

```typescript
describe('Payment Flow', () => {
  const client = new PaymentWatchClient({
    baseUrl: Cypress.env('PAYMENTWATCH_URL'),
    apiKey: Cypress.env('PAYMENTWATCH_API_KEY')
  });

  it('completes payment', () => {
    cy.visit('/checkout');
    cy.get('#submit').click();

    cy.wrap(null).then(async () => {
      await client.waitFor({
        table: 'oxorder',
        field: 'oxpaid',
        value: '0000-00-00 00:00:00',
        operator: '!='
      });
    });
  });
});
```

### Jest

```typescript
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

describe('Order Service', () => {
  const client = new PaymentWatchClient({
    baseUrl: process.env.PAYMENTWATCH_URL!,
    apiKey: process.env.PAYMENTWATCH_API_KEY!
  });

  test('creates order in database', async () => {
    // Trigger order creation
    await orderService.createOrder({ ... });

    // Assert order exists
    await client.assertExists({
      table: 'oxorder',
      field: 'oxordernr',
      value: '12345'
    });
  });
});
```

---

## Environment Variables

Create `.env` file:

```bash
PAYMENTWATCH_URL=http://localhost/paymentwatch
PAYMENTWATCH_API_KEY=your-secret-api-key-here
```

---

## Requirements

- **Node.js**: >= 16.0.0
- **PaymentWatch Server**: >= 1.0.0

---

## License

MIT

---

## Contributing

Pull requests are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Links

- [Documentation](https://docs.oxid-esales.com/paymentwatch)
- [GitHub Repository](https://github.com/OXID-eSales/paymentwatch-client)
- [NPM Package](https://www.npmjs.com/package/@oxid-esales/paymentwatch-client)
- [Issue Tracker](https://github.com/OXID-eSales/paymentwatch-client/issues)

---

**Made with ❤️ by OXID eSales**
EOF
```

---

## Task 8.4: Package Configuration

**Time Estimate:** 1 hour

### Update package.json with Repository Links

```bash
cat > package.json << 'EOF'
{
  "name": "@oxid-esales/paymentwatch-client",
  "version": "1.0.0",
  "description": "TypeScript/JavaScript client for PaymentWatch E2E testing",
  "main": "dist/index.js",
  "module": "dist/index.mjs",
  "types": "dist/index.d.ts",
  "exports": {
    ".": {
      "import": "./dist/index.mjs",
      "require": "./dist/index.js",
      "types": "./dist/index.d.ts"
    }
  },
  "files": [
    "dist",
    "README.md",
    "LICENSE"
  ],
  "scripts": {
    "build": "tsup",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:ui": "vitest --ui",
    "test:coverage": "vitest run --coverage",
    "lint": "tsc --noEmit",
    "prepublishOnly": "npm run lint && npm run test && npm run build"
  },
  "keywords": [
    "oxid",
    "e2e",
    "testing",
    "playwright",
    "cypress",
    "jest",
    "paymentwatch",
    "database",
    "assertions",
    "typescript"
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
  "engines": {
    "node": ">=16.0.0"
  },
  "devDependencies": {
    "@types/node": "^20.0.0",
    "@vitest/coverage-v8": "^1.0.0",
    "@vitest/ui": "^1.0.0",
    "tsup": "^8.0.0",
    "typescript": "^5.3.0",
    "vitest": "^1.0.0"
  }
}
EOF
```

### Create LICENSE

```bash
cat > LICENSE << 'EOF'
MIT License

Copyright (c) 2025 OXID eSales AG

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
EOF
```

### Create CHANGELOG.md

```bash
cat > CHANGELOG.md << 'EOF'
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-01-15

### Added
- Initial release of PaymentWatch Client
- TypeScript/JavaScript client for E2E testing
- Methods: `assume()`, `waitFor()`, `assertExists()`, `assertChain()`
- Support for all SQL operators (==, !=, >, <, LIKE, IS NULL, IN, etc.)
- Retry logic with exponential backoff
- Custom error classes (TimeoutError, AssertionError, etc.)
- Dual module build (ESM + CommonJS)
- Comprehensive test suite (>= 90% coverage)
- GitHub Actions CI/CD
- NPM package publishing automation

[Unreleased]: https://github.com/OXID-eSales/paymentwatch-client/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/OXID-eSales/paymentwatch-client/releases/tag/v1.0.0
EOF
```

---

## Task 8.5: Codecov Configuration

**Time Estimate:** 30 minutes

### Create codecov.yml

```bash
cat > .codecov.yml << 'EOF'
coverage:
  status:
    project:
      default:
        target: 90%
        threshold: 2%
    patch:
      default:
        target: 90%

comment:
  layout: "reach, diff, flags, files"
  behavior: default
  require_changes: false

ignore:
  - "test/**/*"
  - "**/*.test.ts"
  - "**/*.spec.ts"
EOF
```

---

## Task 8.6: NPM Publishing Test (Dry Run)

**Time Estimate:** 1 hour

### Test Package Locally

```bash
# Build package
npm run build

# Test package locally
npm pack

# This creates a tarball: oxid-esales-paymentwatch-client-1.0.0.tgz

# Install in another project to test
cd /tmp/test-project
npm init -y
npm install /path/to/oxid-esales-paymentwatch-client-1.0.0.tgz

# Test import
node -e "const { PaymentWatchClient } = require('@oxid-esales/paymentwatch-client'); console.log('✅ Import works');"
```

### Publish to NPM (Production)

```bash
# Login to NPM
npm login

# Publish (requires NPM token)
npm publish --access public --dry-run

# If dry-run succeeds, publish for real:
npm publish --access public
```

---

## Sprint 8 Deliverables

### GitHub Actions Workflows
```
.github/workflows/
├── ci.yml          # Lint, test on Node 16/18/20, coverage
└── release.yml     # Automated NPM publishing
```

### Documentation
```
README.md           # Comprehensive package documentation
LICENSE             # MIT license
CHANGELOG.md        # Version history
.codecov.yml        # Codecov configuration
```

### NPM Package
```
@oxid-esales/paymentwatch-client
├── dist/index.js       # CommonJS
├── dist/index.mjs      # ESM
├── dist/index.d.ts     # TypeScript types
└── README.md           # Documentation
```

---

## Acceptance Criteria

### CI/CD
- ✅ GitHub Actions CI workflow runs on push/PR
- ✅ Tests run on Node 16, 18, 20
- ✅ Coverage uploaded to Codecov
- ✅ Build artifacts verified
- ✅ Automated NPM publishing on tags

### Documentation
- ✅ README with installation instructions
- ✅ API reference with examples
- ✅ Integration examples (Playwright, Cypress, Jest)
- ✅ Error handling documented
- ✅ CHANGELOG maintained

### Publishing
- ✅ Package published to NPM
- ✅ Version follows semver
- ✅ GitHub releases created automatically
- ✅ Package installable via npm

---

## Verify Sprint Completion

### Check CI Status

```bash
# Push to GitHub and verify CI runs
git push origin main

# Check CI status in GitHub Actions tab
# All jobs should pass: Lint, Test (Node 16/18/20), Build
```

### Test NPM Package

```bash
# Install from NPM
npm install @oxid-esales/paymentwatch-client

# Test import
node -e "
const { PaymentWatchClient } = require('@oxid-esales/paymentwatch-client');
const client = new PaymentWatchClient({
  baseUrl: 'http://localhost/paymentwatch',
  apiKey: 'test-key'
});
console.log('✅ Package works!');
"
```

### Verify Coverage Report

Visit: `https://codecov.io/gh/OXID-eSales/paymentwatch-client`

**Expected:** >= 90% coverage

---

## Sprint Review

### Demo Checklist
- [ ] Show CI workflow running on GitHub Actions
- [ ] Demonstrate multi-version Node.js testing
- [ ] Show Codecov coverage report
- [ ] Install package from NPM
- [ ] Run example code from README
- [ ] Show automated release process

### Retrospective Questions
1. Should we add more Node.js versions to test matrix?
2. Should we publish to GitHub Packages as well?
3. Should we add automated changelog generation?
4. Should we add dependabot for dependency updates?

---

## Next Sprint

**Ready for [Sprint 9: Documentation & Examples](sprint-09-docs.md)**

Sprint 9 will create:
- Playwright integration example
- Cypress integration example
- Jest integration example
- Example E2E test repository
- Troubleshooting guide
- Video tutorial (optional)

---

**Sprint 8 Complete! 🎉**
**CI/CD:** GitHub Actions workflows
**NPM:** Package published (@oxid-esales/paymentwatch-client)
**Coverage:** Codecov integration
**Next:** Documentation & Examples (Week 11)
