# Sprint 81c: Phase 3 — Data Seeding

**Parent:** Sprint 81 — High-Load Testing
**Date:** 2026-04-02
**Status:** done

## Objective

Prepare `pay1.oxid.dev` with sufficient test data for 100 VU/min load: users, product stock, and coupons. All seeding is idempotent and runs automatically as a CI job before k6.

## Principles

- **DI** — All test data values injected via CI env vars, not hardcoded in k6
- **Idempotent** — Running seed job twice produces same result (no duplicates)
- **DRY** — Reuse existing Playwright coupon codes, don't create new ones

## Subtasks

### 3.1 — Test Users (200 accounts)

| # | Task | Status | Details |
|---|------|--------|---------|
| 3.1.1 | SQL: create 200 users with `INSERT ... ON DUPLICATE KEY` pattern | done | `loadtest_user_001..200@oxid-esales.dev` |
| 3.1.2 | User fields: name, address, country | done | First: "Load", Last: "Test001..200", Street: "Bertoldstr.", City: "Freiburg", Country: Germany (`a7c40f631fc920687.20179984`) |
| 3.1.3 | Password: `useruser` (hashed with `password_hash()`) | done | Same as Playwright `TEST_USER.PASSWORD` |
| 3.1.4 | Skip existing users (idempotent) | done | `SELECT COUNT(*) ... WHERE OXUSERNAME = ?` before INSERT |
| 3.1.5 | Verify: `SELECT COUNT(*) FROM oxuser WHERE OXUSERNAME LIKE 'loadtest_user_%'` = 200 | todo | Post-seed validation |

**Why 200 users?** At 100 VU/min, each iteration takes ~30-60s. With 200 users and random selection, collision probability (two VUs using same user simultaneously) is ~25%. This is intentional — it tests session handling under concurrent access with the same account.

### 3.2 — Product Stock

| # | Task | Status | Details |
|---|------|--------|---------|
| 3.2.1 | Set stock to 99999 for all active products | done | `UPDATE oxarticles SET OXSTOCK = 99999 WHERE OXACTIVE = 1 AND OXSTOCK < 99999` |
| 3.2.2 | Verify Axle parts category has products | todo | k6 navigates to `/Spare-parts/Axle-parts/` — must have at least 2 products with "To cart" button |

**Why 99999?** Load test creates real orders that decrement stock. With 100 VU/min × 10 min × 40% happy path = ~400 orders. 99999 provides comfortable headroom for repeated runs.

### 3.3 — Coupon Pool

| # | Task | Status | Details |
|---|------|--------|---------|
| 3.3.1 | Reuse Playwright coupons | done | `E2E_10PCT`, `E2E_50PCT`, `E2E_5FLAT` — already seeded by Playwright e2e setup |
| 3.3.2 | Verify coupons exist on server | todo | `SELECT OXVOUCHERNR FROM oxvouchers WHERE OXVOUCHERNR IN ('E2E_10PCT', 'E2E_50PCT', 'E2E_5FLAT')` |
| 3.3.3 | Verify coupon series allow reuse | todo | `SELECT OXALLOWUSEDAGAIN FROM oxvoucherseries` — multi-use coupons must have `OXALLOWUSEDAGAIN = 1` for load test |

**Decision: Reuse vs. Dedicated Coupons**

Chose to reuse Playwright coupons because:
- Already seeded and validated by e2e test infrastructure
- Reduces maintenance (one source of truth for coupon codes)
- Load test and Playwright never run simultaneously (both manual-trigger)
- If coupons get consumed, Playwright re-seeds them on next e2e run

**Risk:** If someone runs both Playwright e2e and load test at the same time, coupon conflicts may occur. Acceptable for now — documented in workflow description.

## Seeding Implementation

All seeding runs via `appleboy/ssh-action` in the `seed-data` CI job:

```
seed-data job:
  ├── SSH: Create 200 test users (PHP script via make php CMD="...")
  └── SSH: Set product stock to 99999 (PHP script via make php CMD="...")
```

Coupon seeding is NOT in the load test pipeline — it's handled by the Playwright e2e infrastructure. If coupons are missing, the `guest_coupon` scenario will fail with a descriptive error.

## Data Cleanup

**Not automated.** After load tests, `pay1.oxid.dev` will have:
- ~400+ test orders per run
- 200 test user accounts (persistent)
- Modified product stock (persistent at 99999)

Cleanup is manual and optional. Test orders can be identified by:
```sql
SELECT * FROM oxorder
WHERE OXUSERID IN (SELECT OXID FROM oxuser WHERE OXUSERNAME LIKE 'loadtest_user_%')
ORDER BY OXORDERDATE DESC;
```

Future improvement: add a `cleanup` job that deletes test orders older than 7 days.
