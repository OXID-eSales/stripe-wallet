# Sprint 2: Fix E2E Admin Tests Browser Issue

**Sprint Goal:** Fix browser window reopening between serial tests in admin E2E tests
**Status:** PENDING
**Priority:** MEDIUM

---

## Problem Description

The admin E2E tests (`stripe-admin-capture.spec.ts`, `stripe-admin-refund.spec.ts`) reopen the browser window between each test in the `test.describe.serial()` block. This causes:

1. **Login required each time** - Admin must log in at start of every test
2. **Loss of test context** - State from previous tests not preserved
3. **Slow execution** - Browser startup overhead multiplied by test count
4. **Flaky tests** - Browser reopening may cause timing issues

---

## Current Implementation

```typescript
// stripe-admin-capture.spec.ts
test.describe.serial('Admin: Manual Capture Operations', () => {
  test('1. Set capture mode to MANUAL', async ({ page }) => {
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    await adminLogin.login();  // Login on every test!
    // ...
  });

  test('2. Verify order OXPAID is empty', async ({ page }) => {
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    await adminLogin.login();  // Login AGAIN!
    // ...
  });
  // ...same pattern repeats 6 times
});
```

---

## Root Cause

Playwright's `test.describe.serial()` ensures tests run sequentially but **does not share browser context** between tests. Each test receives a fresh `page` fixture, which means:
- New browser context per test
- Session/cookies not preserved
- Must re-login every time

---

## Solution Options

### Option A: Use `storageState` for Session Persistence (RECOMMENDED)

Save admin login session to file, reuse in subsequent tests.

```typescript
// Save session after first login
await page.context().storageState({ path: 'reports/admin-auth.json' });

// In playwright.config.ts or test fixture
use: {
  storageState: 'reports/admin-auth.json'
}
```

**Pros:**
- Clean, official Playwright approach
- Session persists across test runs
- Fast test execution

**Cons:**
- Requires setup project or beforeAll hook
- Session may expire

---

### Option B: Use Custom Fixture with Shared Page

Create a custom fixture that reuses the same page across serial tests.

```typescript
// fixtures.ts
import { test as base, Page } from '@playwright/test';

export const test = base.extend<{ adminPage: Page }>({
  adminPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    // Login once
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    await adminLogin.login();

    await use(page);
    await context.close();
  },
});
```

**Pros:**
- Full control over browser lifecycle
- Single login for all tests

**Cons:**
- More complex setup
- Tests must be truly serial (can't run in parallel)

---

### Option C: beforeAll/afterAll Hooks with Shared State

Use Playwright's hooks to manage login state.

```typescript
test.describe.serial('Admin: Manual Capture', () => {
  let savedContext: { cookies: Cookie[], origins: StorageOriginState[] };

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();

    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    await adminLogin.login();

    savedContext = await context.storageState();
    await context.close();
  });

  test('1. Set capture mode', async ({ browser }) => {
    const context = await browser.newContext({ storageState: savedContext });
    const page = await context.newPage();
    // Already logged in!
  });
});
```

**Pros:**
- Clean separation of setup and tests
- Session shared across all tests

**Cons:**
- Need to pass context manually

---

## Recommended Approach: Option A + Setup Project

### Implementation Plan

1. **Create admin-auth setup project**
2. **Save session state after login**
3. **Configure admin tests to use saved state**

---

## Tasks

### 2.1 Create Authentication Setup File

**Status:** [ ] NOT STARTED

**File:** `tests/e2e/playwright/auth.setup.ts`

```typescript
import { test as setup } from '@playwright/test';
import { AdminLoginPage } from './pages/admin/AdminLoginPage';

setup('admin authentication', async ({ page }) => {
  const adminLogin = new AdminLoginPage(page);
  await adminLogin.navigate();
  await adminLogin.login();

  // Wait for login to complete
  await page.waitForLoadState('networkidle');

  // Save signed-in state
  await page.context().storageState({ path: 'reports/admin-auth.json' });
});
```

---

### 2.2 Update Playwright Config

**Status:** [ ] NOT STARTED

**File:** `playwright.config.ts`

```typescript
export default defineConfig({
  // ...existing config...

  projects: [
    // Setup project runs first
    {
      name: 'admin-setup',
      testMatch: /auth\.setup\.ts/,
    },

    // Admin tests depend on setup
    {
      name: 'admin-tests',
      testMatch: /tests\/admin\/.*.spec.ts/,
      dependencies: ['admin-setup'],
      use: {
        storageState: 'reports/admin-auth.json',
      },
    },

    // Checkout tests (no auth needed)
    {
      name: 'checkout-tests',
      testMatch: /tests\/checkout\/.*.spec.ts/,
    },
  ],
});
```

---

### 2.3 Simplify Admin Tests

**Status:** [ ] NOT STARTED

**Files:**
- `tests/admin/stripe-admin-capture.spec.ts`
- `tests/admin/stripe-admin-refund.spec.ts`

**Remove login from each test:**

```typescript
// BEFORE
test('1. Set capture mode', async ({ page }) => {
  const adminLogin = new AdminLoginPage(page);
  await adminLogin.navigate();
  await adminLogin.login();  // REMOVE THIS
  // ...
});

// AFTER
test('1. Set capture mode', async ({ page }) => {
  // Already logged in via storageState!
  const settingsPage = new AdminModuleSettingsPage(page);
  // ...
});
```

---

### 2.4 Add .gitignore Entry

**Status:** [ ] NOT STARTED

**File:** `tests/e2e/playwright/.gitignore`

```
reports/admin-auth.json
```

---

## Definition of Done

- [ ] `auth.setup.ts` created and working
- [ ] `playwright.config.ts` updated with projects
- [ ] Admin tests don't require login in each test
- [ ] Tests run faster (single login)
- [ ] Browser doesn't reopen between serial tests
- [ ] All admin tests pass
- [ ] `.gitignore` excludes auth state file

---

## Test Commands

```bash
# Run setup only
npx playwright test --project=admin-setup

# Run admin tests (auto-runs setup first)
SHOP_URL=https://daniil.oxiddev.de npx playwright test --project=admin-tests

# Run all tests
SHOP_URL=https://daniil.oxiddev.de npx playwright test
```

---

## Files to Create/Modify

| File | Action |
|------|--------|
| `auth.setup.ts` | CREATE - Authentication setup |
| `playwright.config.ts` | MODIFY - Add projects |
| `stripe-admin-capture.spec.ts` | MODIFY - Remove login code |
| `stripe-admin-refund.spec.ts` | MODIFY - Remove login code |
| `.gitignore` | MODIFY - Exclude auth state |

---

## Alternative: Quick Fix Without Config Changes

If config changes are not desired, can use simpler approach:

```typescript
test.describe.serial('Admin Tests', () => {
  let authState: ReturnType<typeof page.context().storageState>;

  test('0. Login once', async ({ page }) => {
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    await adminLogin.login();
    authState = await page.context().storageState();
  });

  test('1. First actual test', async ({ browser }) => {
    const context = await browser.newContext({ storageState: authState });
    const page = await context.newPage();
    // Test code...
  });
});
```

---

## Development Principles

All changes must follow:

- **TDD** - Write failing tests first, then implementation
- **SOLID** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **Clean Code** - Meaningful names, small functions (15-25 lines), no else expressions (use early returns), DRY
- **Dependency Injection** - Depend on abstractions, not concretions
- **PSR-12** code style, **PHPStan level 6** compliance

---

## Commands Reference

```bash
# Run pre-commit check
./bin/pre-commit-check.sh           # Unit tests + style checks
./bin/pre-commit-check.sh --full    # Unit + Integration tests
./bin/pre-commit-check.sh --no-phpunit  # Style checks only

# Run E2E tests (all)
cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de npx playwright test

# Run admin setup project only
cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de npx playwright test --project=admin-setup

# Run admin tests
cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de npx playwright test tests/admin/

# Run specific admin test
cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de npx playwright test tests/admin/stripe-admin-capture.spec.ts

# Run with headed browser (visible)
cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de npx playwright test --headed

# Show Playwright report
cd tests/e2e/playwright && npx playwright show-report
```

---

## Notes

- The `fullyParallel: false` and `workers: 1` settings are already correct
- Serial tests guarantee order but not browser context sharing
- Session state includes cookies, localStorage, sessionStorage
- Consider session expiry - admin sessions may timeout
