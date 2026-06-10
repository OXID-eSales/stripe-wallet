# Playwright E2E — full-suite run & failure analysis

**Date:** 2026-06-10
**Suite:** `tests/e2e/playwright/playwright` (submodule, branch `projects/Stripe`)
**Target shop:** `https://pay1.oxid.dev/` (from `.env`) — **remote**, behind Cloudflare
**Runner:** Playwright 1.58.0, Chromium, `workers: 1`, serial, 120 s/test, retries 0
**Wall time:** ~1.1 h
**Command:** `npx playwright test --reporter=list,json`

## 1. Headline result

| Status | Count |
|---|---|
| ✅ passed | **54** |
| ❌ failed | **47** |
| ⏱ timedOut | **3** |
| ⏭ skipped | 8 |
| ⊘ did not run | 11 |
| **total** | **123** |

50 tests failed (47 failed + 3 timed out). **No `net::ERR_*` connection errors
were logged** — the remote shop was reachable throughout (admin login + 54 tests
passed); the failures are slowness/data/config issues, analysed below.

> Environment note: from this host `pay1.oxid.dev` resolves to IPv6-only
> Cloudflare records and this box has no working IPv6 route, so `curl` defaults
> to `000`. Chromium's Happy-Eyeballs falls back to IPv4 (`curl -4` → 200), which
> is why the browser tests connect while a naive `curl` does not.

## 2. Root causes (ranked by blast radius)

### A. Coupon fixtures seeded into the LOCAL DB, but tests hit the REMOTE shop — ~17 failures

**The single most actionable cause.** `global-setup.ts` seeds the voucher
fixtures with:

```
docker compose exec -T php mysql -h mysql -u root -proot example   # LOCAL docker DB
```

…but `SHOP_URL=https://pay1.oxid.dev/` points the browser at a **remote** shop
whose database never receives those `E2E_*` vouchers. Every coupon is therefore
"rejected by the shop". The seeding step even logs *"Coupons seeded successfully
via docker compose"* — a false-positive: it succeeded against the wrong database.

Affected:
- `CouponsDiscounts.spec.ts` — 11× `Coupon "E2E_…" was rejected by the shop`
  + 2× discount-calculation assertions that depend on a coupon applying
  (`toBeGreaterThan` / `toBeGreaterThanOrEqual`).
- `GuestOrders.spec.ts` — "Apply 10%/50%/flat coupon and complete purchase"
  (3×) fail at the coupon step ("Did not reach cart page" after rejection).
- `coupon-survives-back-navigation.spec.ts` — 1×.

**Fix options:** (1) run the suite against the **local** shop (`localhost.local`,
which is up and is the DB the seeder targets); or (2) make `global-setup.ts` seed
the **remote** shop's database (not `docker compose exec`); or (3) gate the
coupon tests behind a precondition that the fixtures actually exist on the target.

### B. Remote-shop latency → navigation timeouts (cascading) — ~20 failures

Over a 1.1 h serial run the remote shop responded but was intermittently very
slow. Evidence: **9× `page.goto: Timeout 60000ms`**, 7× `[Nav] Did not reach
cart page`, 2× `page.waitForURL 15000ms`, 3× `Test timeout 120000ms`, 2× "Basket
modal did not open" — and **zero** `net::ERR`. The pages load, just not within
the timeouts. With `workers: 1` a slow remote serialises and amplifies the flake.

Affected (storefront, navigation-heavy):
- `CartBasket.spec.ts` — 5 failed (qty change, empty-cart nav, mini-basket,
  checkout nav, basket modal).
- `GuestOrders.spec.ts` — Mastercard (120 s timeout), Expired/CVC/payment-fails
  (`page.goto 60 s`), 3DS ("Basket modal did not open").
- `PaymentEdgeCases.spec.ts` — "Card Declined" (`waitForURL 15 s`).
- `Checkout.spec.ts` — "Guest User – Form Fill" (`locator.click 30 s`).
- `stripe-checkout.spec.ts` — full English checkout (`page.goto 60 s`).
- `agb-checkbox-locked-during-submit.spec.ts` — `locator.click 30 s`.
- `order-button-enabled-after-back.spec.ts` — Case A & C (120 s test timeouts).

A minor smell feeding this: `SHOP_URL` has a trailing slash and the page objects
prepend `/en/…`, producing a double slash (`pay1.oxid.dev//en/cart/`). It still
serves content, so it isn't the primary cause, but it should be normalised.

### C. OXID admin frameset — order-list frame not ready under latency — 4 failures

`List frame not found` / `list frame not present after selecting order`. The
admin uses nested framesets; the order-list frame didn't appear within the wait.
Same latency family as (B), admin side.

Affected: `payment-tab-spinner-and-blur.spec.ts` (1), `prod-spinner-probe.spec.ts`
(1), `stripe-admin-refund.spec.ts` #6 (1), `return-triggers-refund-or-cancel.spec.ts`
(1, `waitFor 10 s`).

### D. API-key masking / module-config page — likely module-version skew on remote — 5 failures

`stripe-api-key-mask.spec.ts` failed **4 of 5** sub-tests and
`module-config-screenshot.spec.ts` failed, all with short `locator.waitFor
5000ms` on the masked field / eye-toggle. These target the **Sprint 113**
`type="password"` + eye-toggle markup on the module-config page. The whole
cluster failing together (short 5 s waits, not 60 s nav) points to the **remote
shop running an older module build that lacks the Sprint 113 masking markup**,
rather than latency. ⚠️ **Verify the deployed module version on `pay1.oxid.dev`**
— if it predates Sprint 113, these are environment skew, not regressions; if it
is current, this is a real UI regression to investigate.

### E. Data/state-dependent admin order tests — 2 failures

- `stripe-partial-refund.spec.ts` #1 — *"Refund button should be visible before
  any refund"*: the chosen order on the remote shop was likely already
  refunded/captured from prior runs → no fresh refund button.
- `stripe-admin-refund.spec.ts` #6 — *"order cannot be refunded again"* (also
  "List frame not found"): order-state pollution across runs.

These need a known-good order fixture or per-run order creation, not a
pre-existing order on a shared remote shop.

### F. Catalog-dependent storefront — 2 failures

`Axle parts page loaded but product list is not visible`
(`stripe-failed-payment.spec.ts`, `coupon-survives-back-navigation.spec.ts`) — the
test depends on the "Axle parts" category showing products on the remote shop.
Either catalog data differs or the list didn't render in time (latency).

## 3. What passed (works on remote)

Fully green specs: `payment-date-validation`, `payment-refund-order-706`,
`stripe-admin-capture` (6), `stripe-admin-order` (5), `stripe-connect-button`,
`BasicTests`, and partial passes across `PriceVerification` (6/9),
`PaymentEdgeCases` (5/6), `stripe-admin-refund` (5/6), `Checkout` (3/4). Core
admin capture/refund/order viewing and the Connect button are healthy.

## 4. Failures attributable to the test harness/environment vs. real product issues

| Bucket | Count | Verdict |
|---|---|---|
| A. Coupon DB-target mismatch | ~17 | **Harness misconfig** — not a product bug |
| B. Remote latency timeouts | ~20 | **Environment** — not a product bug |
| C. Admin frame timing | 4 | **Environment** (latency) — recheck on local |
| D. API-key mask cluster | 5 | **Needs verification** — version skew likely, possible real regression |
| E. Order-state pollution | 2 | **Harness** — needs isolated order fixtures |
| F. Catalog dependency | 2 | **Environment/data** |

**Net:** ~45 of 50 failures are explained by (A) seeding the wrong database and
(B/C/F) running against a slow, shared, pre-seeded *remote* shop. Only the
**API-key-mask cluster (D, 5 tests)** carries a realistic chance of being an
actual product regression and must be re-checked against a shop running the
current module build.

## 5. Recommended next step

Re-run the suite against the **local Docker shop** (which is up at
`localhost.local` and is the exact DB `global-setup.ts` seeds) to eliminate
causes A, B, C and F in one move:

```bash
cd tests/e2e/playwright/playwright
SHOP_URL=https://localhost.local/ ADMIN_URL=https://localhost.local/admin/ \
  npx playwright test
```

Whatever still fails on local is a candidate **real** issue — most importantly
the API-key-mask cluster (D) and the order-state-dependent admin tests (E). I can
run that local pass next if you want a clean signal.

## 6. Artifacts

- JSON: `tests/e2e/playwright/playwright/reports/results.json`
- Full log: `tests/e2e/playwright/playwright/reports/run.log`
- Screenshots/videos/traces: `reports/test-results/`
- HTML report: `npm run report` (from the `playwright/` dir)
