# Sprint 81 Completion Report — Phases 1-3

**Date:** 2026-04-02
**Sprint:** 81 — High-Load Testing: Stripe Payment Under Stress
**Completed:** Phases 1 (CI Infrastructure), 2 (Browser Scenarios), 3 (Data Seeding)
**Remaining:** Phase 4 (Execution & Analysis)

---

## What Was Built

A complete k6 browser-based load testing framework for the Stripe payment module, targeting 100 concurrent users/min against `daniil.oxiddev.de` (or `pay1.oxid.dev`).

### Deliverables

| Deliverable | Files | Lines | Status |
|-------------|-------|-------|--------|
| CI Pipeline | `.github/workflows/load-test.yml` | 292 | done |
| k6 Orchestrator | `tests/load/k6.config.js` | 85 | done |
| Helper: Config | `tests/load/helpers/config.js` | 52 | done |
| Helper: Metrics | `tests/load/helpers/metrics.js` | 11 | done |
| Helper: Auth | `tests/load/helpers/auth.js` | 71 | done |
| Helper: Shop | `tests/load/helpers/shop.js` | 130 | done |
| Helper: Stripe | `tests/load/helpers/stripe.js` | 189 | done |
| Scenario: Happy Path | `tests/load/scenarios/happy-path.js` | 52 | done |
| Scenario: Cancellation | `tests/load/scenarios/cancellation.js` | 43 | done |
| Scenario: Guest+Coupon | `tests/load/scenarios/guest-coupon.js` | 79 | done |
| Scenario: Payment Failure | `tests/load/scenarios/payment-failure.js` | 65 | done |
| Scenario: 3DS | `tests/load/scenarios/threeds.js` | 51 | done |
| Runner Script | `tests/load/bin/run.sh` | 118 | done |
| Env Config | `tests/load/.env`, `.env.dist` | 4 | done |
| README | `tests/load/README.md` | 210 | done |
| **Total** | **15 files** | **~1450** | |

---

## Design Decisions

### 1. k6 Browser Module over HTTP-only

**Decision:** Use k6's Chromium browser module, not HTTP-level requests.

**Reason:** Stripe Checkout is a hosted page (`checkout.stripe.com`) requiring real browser interaction — filling card forms in iframes, handling 3DS challenge iframes, JS-rendered UI. HTTP requests can't replicate this.

**Trade-off:** Higher memory per VU (~150-300MB vs ~1MB), but realistic end-to-end flow.

### 2. Reuse Playwright Selectors, Not Page Objects

**Decision:** Copy CSS selectors from Playwright's `Helper.ts` into k6 helper functions. Don't try to share code between Playwright and k6.

**Reason:** k6 browser API is similar but NOT identical to Playwright. No `.first()`, no `{ hasText }`, no `getByRole()`, no `frameLocator()`. Sharing code would require an abstraction layer more complex than the helpers themselves.

### 3. `LOAD_` Prefix for Custom Env Vars

**Decision:** Custom variables use `LOAD_TARGET_VUS`, `LOAD_DURATION` etc., NOT `K6_TARGET_VUS`.

**Reason:** k6 interprets any `K6_`-prefixed env var as a built-in config override. `K6_DURATION=10` sets the default scenario duration to 10ms, breaking all scenarios. Discovered during dry-run debugging.

### 4. Single Entry Point (`k6.config.js`) with Module Imports

**Decision:** k6 requires all scenario functions to be exported from the entry point. Scenarios and helpers are in separate files, imported and re-exported.

**Reason:** SRP — each file has one responsibility. But k6's executor needs `export { happy_path }` at the top level to find the function by name.

### 5. Reuse Playwright Coupons, Don't Create New Pool

**Decision:** Use same coupon codes (`E2E_10PCT`, `E2E_50PCT`, `E2E_5FLAT`) as Playwright e2e tests.

**Reason:** Already seeded and validated. Load test and Playwright never run simultaneously (both manual-trigger). Avoids maintaining two coupon pools.

---

## Issues Found & Fixed

### 1. `K6_DURATION` Env Var Conflict (Critical)

**Symptom:** `scenario default has configuration errors: the duration must be at least 1s, but is 10ms`

**Root cause:** k6 interprets `K6_DURATION=10` as "set default scenario duration to 10ms" (not minutes). This created a phantom `default` scenario that couldn't find a `default` export function.

**Fix:** Renamed all custom env vars from `K6_` prefix to `LOAD_` prefix.

**Time spent:** ~45 minutes debugging. The env vars were correctly exported (verified via `env | grep K6_`), but k6's internal var handling was the issue.

### 2. k6 Browser API Differences from Playwright

**Symptom:** `Object has no member 'first'`

**Root cause:** k6 browser locator API is a subset of Playwright's:
- No `.first()` — locator already targets first match
- No `{ hasText }` option — use `:has-text()` CSS pseudo-selector
- No `getByRole()` — use CSS attribute selectors
- No `frameLocator()` — use `page.frames()` loop

**Fix:** Removed all `.first()` calls, converted `{ hasText }` to `:has-text()`, replaced `getByRole()` with CSS selectors, replaced `frameLocator()` with frame iteration.

### 3. OPC Login Page Layout

**Symptom:** `isLoggedIn: false` after login attempt

**Root cause:** `daniil.oxiddev.de` uses one-page-checkout layout. The login form at `/index.php?cl=account` renders inside the OPC container with different selectors than standard OXID.

**Status:** Not yet fixed. Needs investigation of OPC login form selectors.

### 4. `pay1.oxid.dev` HTTP 521

**Symptom:** Cloudflare 521 (Web server is down)

**Status:** Server issue. Switched to `daniil.oxiddev.de` for development. CI workflow still targets `pay1.oxid.dev` (will work when server is back).

---

## Dry-Run Results

**First successful dry-run** (happy_path, `daniil.oxiddev.de`):

```
browser_data_received.......: 3.8 MB  2.0 MB/s
browser_http_req_duration...: avg=157ms  p95=429ms  p99=682ms
browser_http_req_failed.....: 0.00%  0 out of 16
browser_web_vital_fcp.......: 600ms
browser_web_vital_ttfb......: 404ms
iteration_duration..........: 1.51s
```

**Outcome:** k6 browser launched Chromium, loaded the shop (3.8MB, 16 HTTP requests, 0% failures), OPC JavaScript initialized. Login failed due to OPC form layout (see Issue #3).

---

## Metrics & Thresholds Defined (TDD)

| Metric | Threshold | Purpose |
|--------|-----------|---------|
| `browser_http_req_failed` | < 5% | HTTP error rate |
| `checkout_success_rate` | > 90% | Successful end-to-end checkouts |
| `contract_state_valid` | = 100% | No invalid state transitions |
| `stripe_api_errors` | < 10 | Stripe SDK errors |
| `checkout_duration` p95 | < 60s | Full browser flow performance |
| `orders_created` | > 0 | At least one order per run |

---

## Architecture Summary

```
tests/load/
├── .env / .env.dist         # K6_BASE_URL, K6_BROWSER_ENABLED, password
├── k6.config.js             # Entry: imports, re-exports, thresholds, scenario builder
├── bin/run.sh               # CLI: --dry-run, --full, --baseline, --endurance, --scenario
├── helpers/
│   ├── config.js            # LOAD_ env vars, CARDS, COUPONS, TRAFFIC distribution
│   ├── metrics.js           # checkout_success_rate, contract_state_valid, etc.
│   ├── auth.js              # loginAndSetup(), getRandomUser()
│   ├── shop.js              # addProductsToCart(), navigateToCheckout(), selectStripeAndOrder()
│   └── stripe.js            # fillStripeCard(), clickStripePay(), handle3DS(), verifyThankYou()
├── scenarios/
│   ├── happy-path.js        # 40% — full checkout
│   ├── cancellation.js      # 20% — abandon at Stripe
│   ├── guest-coupon.js      # 15% — guest + coupon
│   ├── payment-failure.js   # 15% — declined → retry
│   └── threeds.js           # 10% — 3DS challenge
└── results/                 # Logs per run (gitignored)
```

CI: `.github/workflows/load-test.yml` — 4 jobs (pre-flight → seed → k6 → post-validation), manual trigger only.

---

## What's Next (Phase 4)

| Step | Action | Blocker |
|------|--------|---------|
| 4.0 | **Fix OPC login selector** in `helpers/auth.js` | Must investigate OPC form structure |
| 4.1 | **Dry-run all scenarios** — verify each works end-to-end | Login fix required |
| 4.2 | **Baseline** — 10 VU/min, 2 min | Dry-run must pass |
| 4.3 | **Full load** — 100 VU/min, 10 min | Baseline must pass |
| 4.4 | **Endurance** — 100 VU/min, 30 min | Full must pass |
| 4.5 | **Report** — bottlenecks, recommendations | All runs complete |

**Estimated time to complete Phase 4:** ~2-3 hours (including login fix + 4 test runs + analysis).

---

## Principles Applied

| Principle | Evidence |
|-----------|----------|
| **TDD** | Thresholds defined in `k6.config.js` before scenarios implemented |
| **DevOps First** | CI pipeline created first (Phase 1), before any scenario code |
| **SRP** | 1 file per scenario, 1 file per helper concern |
| **OCP** | Adding a scenario = new file + 1 re-export line + traffic entry |
| **LSP** | All scenarios: `browser.newPage()` → try/metrics/catch → `page.close()` |
| **ISP** | Helpers split: auth, shop, stripe, config, metrics |
| **DIP** | Scenarios import helpers, not raw selectors |
| **DRY** | 9 shared helpers used across 5 scenarios |
| **DI** | All config via `.env` → env vars → `__ENV` in k6 |
