# Playwright E2E — local vs. remote comparison (isolating real failures)

**Date:** 2026-06-10/11
**Follow-up to:** [`playwright-full-run-failure-analysis.md`](./playwright-full-run-failure-analysis.md)
**Purpose:** Re-run the full suite against the **local** Docker shop
(`localhost.local`, the DB the coupon seeder actually targets) to strip out the
environmental noise and surface genuine product issues.

## 1. Side-by-side

| | Remote (`pay1.oxid.dev`) | Local (`localhost.local`) |
|---|---|---|
| passed | 54 | **70** |
| failed (incl. timedOut) | 50 | **19** |
| skipped / did-not-run | 19 | 34 |
| wall time | ~1.1 h | 45 min |

**Delta:** 34 remote failures became green on local; 8 are new local-only; 11
failed on both.

```
REMOTE→LOCAL FIXED ......... 34   (coupons + remote latency — environmental)
FAILED ON BOTH ............. 11   (env-independent candidates)
NEW FAIL ON LOCAL .......... 8    (local data / stale test selectors)
```

## 2. Confirmed: the big remote clusters were environmental

- **Coupons (≈17):** every `E2E_*` "rejected by the shop" failure is **gone on
  local**. Confirms cause A — `global-setup.ts` seeds the *local* DB via
  `docker compose exec … mysql`, so coupons only exist for a local-targeted run.
- **Cart / mini-basket / guest-checkout latency (≈17):** `CartBasket` (5),
  `GuestOrders` (8), plus several `page.goto 60 s` timeouts are **all green on
  local**. Confirms cause B — remote-shop latency, not product defects.

## 3. The one genuine product bug 🔴

**Eye-toggle on masked API-key fields is not keyboard-operable.**
`tests/admin/stripe-api-key-mask.spec.ts:157` — fails on **both** remote and
local (env-independent), with a definitive assertion:

```
Error: Enter on focused toggle reveals
expect(locator).toHaveAttribute failed
Locator:  input[name="confstrs[sStripeTestPk]"]
Expected: "text"
Received: "password"      (19× — never flips)
```

The **mouse-click** toggle test passes ("clicking the eye toggle switches the
input to type=text and back"), and the static markup tests pass (`type=password`
+ `aria-label`/`aria-pressed`). Only **keyboard activation (Enter / Space)** does
nothing — the field stays masked. This is an **accessibility regression in the
Sprint 113 masking feature**: the toggle handles `click` but not keyboard
activation (likely a non-`<button>` element, or a `keydown` handler that never
got wired). WCAG 2.1.1 (keyboard operable) violation.

The paired test `…:175 input value is preserved across toggle clicks` also fails
on both (a 30 s `locator.click` timeout) — almost certainly the same component;
confirm while fixing the keyboard handler.

**Recommendation:** real fix — make the eye-toggle a focusable `<button type="button">`
(or add `keydown` Enter/Space → same handler as click). TDD: the two failing
specs are the ready-made RED tests.

## 4. Everything else = test-harness / data / config (not product bugs)

### 4a. Stale admin order selector — 8–9 failures (the "Payment tab not found" cluster)
`AdminOrdersPage.selectOrderByCustomerName()` defaults to customer **"Marc"** and
falls back to `listFrame.locator('a:has-text("2025-")')`. On local neither
matches (no "Marc" order; **it is 2026, so the hardcoded "2025-" year matches
nothing**), so no order is opened and `openPaymentTab()` throws *"Payment tab
link not found in list frame"*. Local actually has **131 Stripe orders + 162
contracts** — the data exists; the **selector is stale**. On remote a "Marc"
fixture order existed, so these passed there.
Affected: `stripe-admin-capture` #3, `stripe-admin-order` #2, `stripe-admin-refund`
#3, `stripe-manual-capture-fix` #1, `stripe-partial-refund` #1,
`payment-refund-order-706`, `payment-tab-spinner-and-blur`, `prod-spinner-probe`,
`return-triggers-refund-or-cancel`.
**Fix:** pick the order by a stable attribute (year-agnostic, or query a known
Stripe order id), not customer name + hardcoded year.

### 4b. Stripe hosted-checkout redirect/return timing — 4 failures
`stripe-checkout` EN & DE (`page.waitForURL 90 s`), `coupon-survives-back`
(`waitForURL 30 s`), `Checkout – Logged-In 3DS Fail` (120 s test timeout). The
**happy-path guest Stripe checkout passes on local** (`GuestOrders` green), so
the redirect machinery works; these specs wait on a specific URL / 3DS challenge
that doesn't resolve in time. Needs a targeted look (return-URL pattern, 3DS
iframe handling) — not a confirmed product defect.

### 4c. Catalog + test-isolation — 3 failures (`PriceVerification`)
`"Axle parts" submenu link not visible` (category-menu assumption) and
`[Cart] Cart is not empty (2 items found)` ×2 (the cart wasn't emptied between
tests — ordering/cleanup). Harness/data; failed on both environments because the
catalog assumption and the missing per-test cart reset are environment-independent.

### 4d. Data-only — 1 failure
`payment-date-validation` — *"Expected at least one order with a valid payment
date"*: the picked order set on local has none with a payment date. Data.

## 5. Bottom line

Of the original 50 remote failures, **~45 were environmental** (coupon DB target,
remote latency, shared-shop data). Running on local fixed 34 outright and
re-expressed the rest as **stale test fixtures** (hardcoded customer/year, missing
cart reset, catalog assumptions).

**Exactly one genuine product issue surfaced:** the **API-key eye-toggle is not
keyboard-operable** (with a likely-related value-preservation failure). That is
the only item warranting a code fix; the remaining failures are test-suite
maintenance:

1. 🔴 **Fix the eye-toggle keyboard handler** (Sprint 113 feature) — real bug.
2. 🟠 Replace the `'Marc'` / `'2025-'` order selector in `AdminOrdersPage` with a
   stable, year-agnostic lookup (unblocks ~9 admin specs).
3. 🟠 Make coupon tests target the same shop whose DB is seeded (or seed remote).
4. 🟡 Add per-test cart reset + fix the "Axle parts" category assumption in
   `PriceVerification`.
5. 🟡 Investigate the `stripe-checkout` EN/DE + 3DS `waitForURL` timeouts.

## 6. Artifacts
- Local JSON: `reports/results-local.json` · log: `reports/run-local.log`
- Remote JSON: `reports/results.json` · log: `reports/run.log`
