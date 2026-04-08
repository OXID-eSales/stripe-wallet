# Sprint 78: E2E Test Suite — Coupon Database Seeding

**Date:** 2026-03-30
**Branch:** `b-7.4.x`
**Priority:** HIGH — Fixes 13 of 41 failures

---

## Problem

All coupon tests fail with `[Coupon] Coupon "E2E_10PCT" was rejected by the shop`. The test voucher codes (`E2E_10PCT`, `E2E_50PCT`, `E2E_50FLAT`, `E2E_5FLAT`, `E2E_100PCT`, `E2E_SINGLE`, `E2E_EXPIRED`, `E2E_FUTURE`, `E2E_MINORDER`, `E2E_NOSTACK`) are not present in the OXID database.

Note: `E2E_EXPIRED`, `E2E_FUTURE`, `E2E_MINORDER`, and `INVALIDCODE123` tests **pass** because they correctly expect error messages. The issue is only with coupons that should be **accepted**.

## Rejected Coupons (need DB seeding)

| Code | Type | Value | Special |
|------|------|-------|---------|
| E2E_10PCT | Percentage | 10% | — |
| E2E_50PCT | Percentage | 50% | — |
| E2E_100PCT | Percentage | 100% | — |
| E2E_50FLAT | Absolute | 50.00 | — |
| E2E_5FLAT | Absolute | 5.00 | — |
| E2E_SINGLE | Percentage | 10% | Single-use (1 voucher) |
| E2E_NOSTACK | Percentage | 15% | Non-stackable |

## Fix Plan

### Step 1: Create SQL Fixture

Create `tests/e2e/playwright/playwright/fixtures/seed-coupons.sql` with INSERT statements for:
- `oxvoucherseries` — voucher series definitions (discount type, value, dates, stacking rules)
- `oxvouchers` — individual voucher codes linked to each series

### Step 2: Add Global Setup

Add a Playwright global setup that runs the SQL fixture via the OXID database before the test suite.

Options:
- Direct MySQL connection in `globalSetup.ts`
- Docker exec: `docker compose exec -T php mysql ...`
- HTTP endpoint if available

### Step 3: Verify

```bash
npx playwright test tests/e2e/CouponsDiscounts.spec.ts --headed
```

## Tests This Fixes (13)

- CouponsDiscounts.spec.ts: Apply 10%, Apply 50%, Apply flat 50, Remove coupon, Checkout with coupon, Compare 10% vs 50%, Coupon with spaces, Apply flat 5, Stack coupons, 100% discount, Single-use, Verify percentage calc, Verify flat calc
- GuestOrders.spec.ts: Apply 10% coupon, Apply 50% coupon, Apply flat 50 coupon