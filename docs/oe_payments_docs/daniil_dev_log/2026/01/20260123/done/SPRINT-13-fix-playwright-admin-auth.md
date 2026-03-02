# SPRINT 13: Fix Playwright Admin Auth Setup

**Date Created:** 2026-01-23
**Status:** COMPLETED ✓
**Priority:** HIGH
**Estimated Effort:** 30 minutes
**Category:** Test Infrastructure

---

## Problem Statement

The `[admin-tests]` Playwright project fails with:
```
Error: Error reading storage state from reports/admin-auth.json:
ENOENT: no such file or directory, open 'reports/admin-auth.json'
```

**Root Cause:** The `admin-setup` project is configured with `testMatch: /auth\.setup\.ts/`, but the config has `testDir: './tests'`. Since `auth.setup.ts` is in the root directory (not in `tests/`), the setup project never runs, and the auth state file is never created.

---

## Current Configuration (Broken)

```typescript
// playwright.config.ts
export default defineConfig({
  testDir: './tests',  // <-- Only looks in ./tests

  projects: [
    {
      name: 'admin-setup',
      testMatch: /auth\.setup\.ts/,  // <-- But auth.setup.ts is in root!
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'admin-tests',
      testMatch: /tests\/admin\/.*.spec.ts/,
      dependencies: ['admin-setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'reports/admin-auth.json',  // <-- File never created
      },
    },
    // ...
  ],
});
```

---

## Solution

Move `auth.setup.ts` to the `tests/` directory so it's found by the `testDir` configuration.

### Option A: Move file to tests directory (Recommended)

1. Move `auth.setup.ts` to `tests/auth.setup.ts`
2. Update `testMatch` pattern if needed

### Option B: Override testDir for setup project

```typescript
{
  name: 'admin-setup',
  testDir: './',  // Override to look in root
  testMatch: /auth\.setup\.ts/,
  use: { ...devices['Desktop Chrome'] },
},
```

---

## Implementation Plan

### Phase 1: Move auth.setup.ts

```bash
mv tests/e2e/playwright/auth.setup.ts tests/e2e/playwright/tests/auth.setup.ts
```

### Phase 2: Update playwright.config.ts (if needed)

The `testMatch` pattern should still work since it just matches the filename.

### Phase 3: Verify fix

```bash
cd tests/e2e/playwright
npx playwright test --headed
```

Expected:
- `[admin-setup]` project runs first
- `reports/admin-auth.json` is created
- `[admin-tests]` project runs with saved auth state
- All tests pass

---

## Acceptance Criteria

- [ ] `auth.setup.ts` is in correct location
- [ ] `admin-setup` project runs when executing tests
- [ ] `reports/admin-auth.json` is created after setup
- [ ] `[admin-tests]` project tests pass
- [ ] All 38 Playwright tests pass

---

## Files to Modify

| File | Change |
|------|--------|
| `auth.setup.ts` | Move to `tests/` directory |
| `playwright.config.ts` | Update testMatch if needed |

---

## Test Commands

```bash
# Run all tests
npx playwright test --headed

# Run only admin-setup to verify it works
npx playwright test --project=admin-setup

# Run admin tests
npx playwright test --project=admin-tests
```

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| File path issues in auth.setup.ts | Low | Medium | Verify imports after move |
| CI/CD pipeline affected | Low | Low | Test locally first |

---

**Sprint Owner:** TBD
**Review Required:** No (simple file move)
