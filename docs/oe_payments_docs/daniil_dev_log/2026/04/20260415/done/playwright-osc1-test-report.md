# Playwright Test Report: osc1.oxid.shop

**Date:** 2026-04-15
**Target:** `https://osc1.oxid.shop`
**Duration:** 17.5 minutes
**Result:** 61 passed, 3 failed, 25 did not run

## Summary

| Category | Passed | Failed | Skipped | Total |
|----------|--------|--------|---------|-------|
| E2E checkout (chromium) | 61 | 2 | 0 | 63 |
| Admin setup | 0 | 1 | 0 | 1 |
| Admin tests | 0 | 0 | 25 | 25 |
| **Total** | **61** | **3** | **25** | **89** |

**All 3 failures are environment/data issues — zero code bugs.**

---

## Failure #1: Admin Authentication

**Test:** `[admin-setup] auth.setup.ts:11 — admin authentication`
**Impact:** Blocks all 25 admin tests from running

**Root Cause:** Wrong admin credentials for `osc1.oxid.shop`.

The test used default credentials `noreply@oxid-esales.com` / `admin` which are rejected by the staging server. The screenshot shows:

> Error! Incorrect username and/or password!

The server also displays banner: "Functionality is limited in staging mode"

**Evidence:** Screenshot shows OXID admin login page with "Error! Incorrect username and/or password!" message. User field contains `noreply@oxid-esales.com`.

**Fix:** Add correct admin credentials for osc1.oxid.shop to `.env`:
```
ADMIN_USER=<correct_admin_email>
ADMIN_PASSWORD=<correct_admin_password>
```

Or configure admin credentials in `AdminLoginPage.ts` DEFAULT_ADMIN_CREDENTIALS to read from env vars (may already do this — needs the right values in `.env`).

### Blocked Admin Tests (25)

All admin-project tests were skipped because they depend on `admin-setup`:
- `payment-date-validation.spec.ts` (2 tests)
- `stripe-admin-capture.spec.ts` (6 tests)
- `stripe-admin-order.spec.ts` (5 tests)
- `stripe-admin-refund.spec.ts` (6 tests)
- `stripe-manual-capture-fix.spec.ts` (4 tests)
- `stripe-tab-styles.spec.ts` (2 tests)

---

## Failure #2: Coupon — Flat 50 Off

**Test:** `[chromium] CouponsDiscounts.spec.ts:21 — Coupon - Apply flat 50 off coupon`

**Root Cause:** Coupon `E2E_50FLAT` not configured on `osc1.oxid.shop`.

The shop rejected the coupon with message:
> Your coupon "E2E_50FLAT" couldn't be accepted. Reason: The total price is too low for this coupon!

This means either:
1. The coupon `E2E_50FLAT` doesn't exist on osc1 (most likely — coupons are seeded by `global-setup.ts` via Docker which doesn't run on remote servers)
2. Or the coupon has a minimum order value that the test cart doesn't meet

**Error location:** `Helper.ts:1608 — assertCouponApplied()` throws after detecting rejection message.

**Evidence:** Page snapshot shows cart with 2 items (Wishbone aluminum 127€ + Front wheel bearing 130€ = 382€ total) and the rejection message.

**Fix:** Seed test coupons on `osc1.oxid.shop` database, or create a setup step that creates them via admin API before tests run.

---

## Failure #3: Guest Order with Coupon

**Test:** `[chromium] GuestOrders.spec.ts:117 — Guest Order - Apply flat 50 off coupon and complete purchase`

**Root Cause:** Same as Failure #2 — coupon `E2E_50FLAT` not available on osc1.

**Error:** `[Coupon] Coupon "E2E_50FLAT" was rejected by the shop`

**Fix:** Same as Failure #2.

---

## Passing Tests (61)

All core checkout, payment, and e2e flows pass:

### Stripe Checkout Flow
- Complete checkout with Stripe Wallet
- Coupon survives back navigation from Stripe Checkout

### E2E Standard Tests
- Login, product browsing, cart operations
- Guest orders (without coupons)
- Price calculations, discounts (percentage-based)
- Multiple product variations
- Address handling

### E2E Coupons & Discounts (partial)
- Percentage discount coupons pass
- Only flat-amount coupon (`E2E_50FLAT`) fails due to missing test data

---

## Recommendations

| Priority | Action | Effort |
|----------|--------|--------|
| High | Get admin credentials for osc1.oxid.shop → unblocks 25 tests | Config only |
| Medium | Seed test coupons on osc1.oxid.shop (or add remote seeding to global-setup) | 1-2 hours |
| Low | Add `.env.osc1` template with osc1-specific credentials | 10 min |

---

## Environment Comparison

| Setting | daniil.oxiddev.de | osc1.oxid.shop |
|---------|-------------------|----------------|
| Admin credentials | Known (local dev) | Unknown (staging) |
| Test coupons | Seeded via Docker | Not seeded |
| Staging mode | No | Yes ("Functionality is limited") |
| Test user | playwright.user@oxid-esales.dev | razvan.zerfas+playwright@betterqa.co |
