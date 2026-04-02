# Status — 2026-04-02

## Sprint 81: STRP-XXX High-Load Testing — Stripe Payment Under Stress (100 Users/Min)

### Objective

Validate that the Stripe payment integration (Smart-Contract Architecture) operates correctly and resiliently under heavy load on the staging server `pay1.oxid.dev`. Target: **100 concurrent users per minute** performing realistic e-commerce flows — basket creation, checkout with payment, mid-flow cancellations, coupon usage, and guest orders.

### Core Principles Applied

| Principle | How It Applies to This Sprint |
|-----------|-------------------------------|
| **TDD** | Write load test scenarios as executable specs first; define pass/fail thresholds before implementation |
| **DevOps First** | CI/CD pipeline integration from step 1; k6 runs in Docker, results exported to Grafana/InfluxDB |
| **SOLID — SRP** | Each load scenario is a single-responsibility script (one user journey per scenario file) |
| **SOLID — OCP** | Scenario runner is open for extension (add new scenarios) without modifying the runner |
| **SOLID — LSP** | All scenario types implement the same `VirtualUser` interface — substitutable in the runner |
| **SOLID — ISP** | Separate interfaces: `CheckoutScenario`, `CancellationScenario`, `CouponScenario` |
| **SOLID — DIP** | Scenarios depend on abstractions (`HttpClient`, `StripeTestHelper`), not concrete implementations |
| **DRY** | Shared helpers extracted from existing Playwright Page Objects; reusable request builders |
| **DI** | Test configuration injected via environment variables and config files, not hardcoded |

---

### Target Environment

| Parameter | Value |
|-----------|-------|
| **Server** | `pay1.oxid.dev` |
| **Stripe Mode** | Test (using Stripe test keys) |
| **PHP** | 8.3 |
| **MySQL** | 8.0 |
| **Expected Baseline** | ~100 users/min sustained for 10 minutes |
| **Ramp-up** | 0 → 100 users over 2 minutes |
| **Steady state** | 100 users/min for 10 minutes |
| **Ramp-down** | 100 → 0 over 1 minute |
| **Total duration** | ~13 minutes per run |

---

### Load Test Scenarios (User Journeys)

Based on our existing Playwright e2e test suite, translated to k6 HTTP-level load tests:

#### Scenario 1: Happy Path Checkout (40% of traffic)
_Derived from: `tests/e2e/Checkout.spec.ts`, `tests/checkout/stripe-checkout.spec.ts`_

1. Login as registered user (or use session cookie)
2. Browse product catalog (2-3 page views)
3. Add 1-3 products to basket
4. Navigate to checkout
5. Select Stripe Wallet payment
6. Complete Stripe Checkout (simulated via Stripe test API — `4242424242424242`)
7. Verify Thank You page (HTTP 200)
8. **Assert:** Order created in DB, contract state = `FULFILLED`, `OXPAID` has valid date

#### Scenario 2: Mid-Flow Cancellation (20% of traffic)
_Derived from: `tests/checkout/coupon-survives-back-navigation.spec.ts`_

1. Login or guest session
2. Add products to basket
3. Navigate to Stripe Checkout
4. **Abandon payment** (simulate Stripe session expiry / back navigation)
5. **Assert:** Contract state = `CANCELLED` or `EXPIRED`, no orphan orders, basket preserved
6. **Assert:** Coupon (if applied) is NOT marked as used

#### Scenario 3: Guest Checkout with Coupon (15% of traffic)
_Derived from: `tests/e2e/GuestOrders.spec.ts`, `tests/e2e/CouponsDiscounts.spec.ts`_

1. Browse as guest (no login)
2. Add products to basket
3. Apply coupon code (`E2E_10PCT` or `E2E_50PCT`)
4. Fill guest checkout form
5. Complete Stripe payment
6. **Assert:** Discount applied correctly, order total matches expected, coupon marked as used

#### Scenario 4: Payment Failure & Retry (15% of traffic)
_Derived from: `tests/e2e/PaymentEdgeCases.spec.ts`_

1. Login as registered user
2. Add products to basket
3. Attempt payment with declined card (`4000000000000002`)
4. **Assert:** Error displayed, contract state = `FAILED`
5. Retry with valid card (`4242424242424242`)
6. **Assert:** New contract created, order fulfilled

#### Scenario 5: 3D Secure Authentication (10% of traffic)
_Derived from: `tests/e2e/Checkout.spec.ts` — 3DS tests_

1. Login, add products, proceed to checkout
2. Pay with 3DS-required card (`4000000000003220`)
3. Complete 3DS authentication (Stripe test mode auto-completes)
4. **Assert:** Order fulfilled, transaction recorded with 3DS flag

---

### Success Criteria (Pass/Fail Thresholds)

| Metric | Threshold | Tool |
|--------|-----------|------|
| **HTTP Error Rate** | < 1% of all requests | k6 |
| **p95 Response Time** (product pages) | < 2s | k6 |
| **p95 Response Time** (checkout flow) | < 5s | k6 |
| **p99 Response Time** (any page) | < 10s | k6 |
| **Stripe API Error Rate** | < 0.5% | Stripe Dashboard + webhook logs |
| **Contract State Consistency** | 100% valid transitions | Post-run DB validation |
| **Orphan Orders** | 0 orders without valid contract | Post-run DB query |
| **Coupon Integrity** | 0 double-used single-use coupons | Post-run DB query |
| **Memory Leaks** | PHP memory stable (no upward trend) | Grafana / `memory_get_peak_usage()` |
| **DB Connection Pool** | No connection exhaustion | MySQL `SHOW PROCESSLIST` monitoring |
| **Concurrent Transactions** | No deadlocks on `oe_payments_contract` | MySQL error log |

---

### Sprint Subtasks

#### Phase 1: Foundation (CI + Infrastructure)

| # | Subtask | Files | Status | Notes |
|---|---------|-------|--------|-------|
| 1.1 | **GitHub Actions workflow** — `workflow_dispatch` with 5 inputs | `.github/workflows/load-test.yml` | done | 292 lines, 4 jobs |
| 1.2 | **k6 orchestrator** — scenario builder, thresholds, re-exports | `tests/load/k6.config.js` | done | 85 lines, imports from helpers/ + scenarios/ |
| 1.3 | **Helpers: config + metrics** — env vars, test data, custom k6 metrics | `tests/load/helpers/config.js`, `metrics.js` | done | Cards, coupons, users from Playwright fixtures |
| 1.4 | **Helpers: auth** — login, cookie accept, language switch | `tests/load/helpers/auth.js` | done | Mirrors Helper.setupLoggedInSession() |
| 1.5 | **Helpers: shop** — products, cart, checkout, coupon | `tests/load/helpers/shop.js` | done | Mirrors 5 Playwright Helper methods |
| 1.6 | **Helpers: stripe** — card fill, pay, 3DS, thank you | `tests/load/helpers/stripe.js` | done | Mirrors StripeCheckoutPage + Helper 3DS/verify |
| 1.7 | **Pre-flight job** — shop health + Stripe test mode check | `.github/workflows/load-test.yml` | done | Fails CI if Stripe mode != "test" |
| 1.8 | **Post-validation job** — DB consistency queries | `.github/workflows/load-test.yml` | done | Orphan orders, stuck contracts, double-used coupons |

#### Phase 2: Scenarios (k6 browser + Chromium)

| # | Subtask | Traffic | File | Status | Notes |
|---|---------|---------|------|--------|-------|
| 2.1 | **Happy Path** — login → browse → cart → Stripe → card → 3DS → thank you | 40% | `scenarios/happy-path.js` | done | Full browser flow, mirrors Playwright Checkout.spec.ts |
| 2.2 | **Cancellation** — same flow → `page.goBack()` from Stripe | 20% | `scenarios/cancellation.js` | done | Tests contract cleanup, no orphan orders |
| 2.3 | **Guest + Coupon** — no login → cart → apply voucher → user step | 15% | `scenarios/guest-coupon.js` | done | Random coupon per iteration: E2E_10PCT/50PCT/5FLAT |
| 2.4 | **Payment Failure + Retry** — declined card → error → retry with valid card | 15% | `scenarios/payment-failure.js` | done | Tests idempotency table + Stripe error display |
| 2.5 | **3DS Flow** — 3DS card → iframe challenge → Complete → thank you | 10% | `scenarios/threeds.js` | done | Tests 3DS iframe interaction under load |

#### Phase 3: Data Seeding

| # | Subtask | Status | Notes |
|---|---------|--------|-------|
| 3.1 | **Seed 200 test users** via SSH in CI job | done | `loadtest_user_001..200@oxid-esales.dev`, password: `useruser` |
| 3.2 | **Set product stock to 99999** | done | Prevents stock-out during test |
| 3.3 | **Coupon pool** | done | Reusing Playwright coupons: `E2E_10PCT`, `E2E_50PCT`, `E2E_5FLAT` (same as e2e tests) |

#### Phase 4: Refinement & Analysis

| # | Subtask | Status | Notes |
|---|---------|--------|-------|
| 4.1 | **Dry-run validation** — 1 VU, 1 iteration, verify each scenario works | todo | First CI run |
| 4.2 | **Baseline run** — 10 VU/min for 2 min, capture p95 metrics | todo | Establish reference |
| 4.3 | **Full load run** — 100 VU/min for 10 min | todo | Primary test |
| 4.4 | **Endurance run** — 100 VU/min for 30 min | todo | Memory leak / connection pool detection |
| 4.5 | **Results analysis + report** — document bottlenecks, recommendations | todo | |

**Overall status:** Phase 1-3 implemented (11 files, 851 lines), Phase 4 ready to execute

**Completed subsprint docs (in `done/`):**
- [Sprint 81a: Phase 1 — CI Infrastructure](done/sprint-81a-phase1-ci-infrastructure.md) — **done** (25 subtasks)
- [Sprint 81b: Phase 2 — Browser Scenarios](done/sprint-81b-phase2-browser-scenarios.md) — **done** (5 scenarios, 20 subtasks)
- [Sprint 81c: Phase 3 — Data Seeding](done/sprint-81c-phase3-data-seeding.md) — **done** (10 subtasks)
- [Completion Report: Phases 1-3](done/report-sprint-81-phases-1-3.md) — decisions, issues found, dry-run results

**Remaining (in `sprints/`):**
- [Sprint 81d: Phase 4 — Execution & Analysis](sprints/sprint-81d-phase4-execution-analysis.md) — **blocked** (4 runs, 30 subtasks)

**Blocker: OXID Session Not Preserved Across k6 Browser Navigations**

Login works (Step 1). Products added via `page.evaluate(fetch(...))` (Step 2). But navigating to `cl=payment` (Step 3) gets a new `force_sid` — OXID creates a new empty session, basket is lost, redirects to `cl=start`.

Root cause: OXID uses URL-based sessions (`force_sid`) when session cookies aren't available. k6 Chromium browser should carry cookies, but something (Cloudflare CF proxy, OXID `blSessionUseCookies` config, or HTTPS/SameSite cookie flags) prevents the session cookie from being sent with `page.goto()`.

**Dry-run progress so far:**
- Login: works (`#loginUser`, `#loginPwd`, `#loginButton` — same as Playwright)
- Add to cart: works (via `page.evaluate(fetch(...))`)
- Navigate to checkout: FAILS (session lost, `cl=payment` → `cl=start`)
- Stripe, 3DS, thank you: untested

**Next steps to unblock:**
1. Investigate k6 browser cookie handling: `page.context().cookies()` to see what cookies exist
2. Check if OXID sets `sid` or `oxidesales_session` cookie via `Set-Cookie` header
3. If no cookie: try passing `force_sid` in all URLs (extract from login, thread through all helpers)
4. Alternative: run against localhost (no Cloudflare) to isolate the issue

---

### CI Workflow: `load-test.yml`

**Trigger:** Manual only (`workflow_dispatch`) — never runs on push/PR.

**Inputs:**

| Input | Options | Default | Purpose |
|-------|---------|---------|---------|
| `target_vus` | 10, 25, 50, 100, 200 | 100 | Virtual users per minute |
| `duration` | 2, 5, 10, 20, 30 | 10 | Steady-state duration (minutes) |
| `ramp_up` | 1, 2, 3, 5 | 2 | Ramp-up period (minutes) |
| `scenario` | all, happy_path, cancellation, guest_coupon, payment_failure, threeds | all | Which scenario(s) to run |
| `dry_run` | true/false | false | 1 VU, 1 iteration (smoke test) |

**Jobs:**

```
pre-flight ──→ seed-data ──→ load-test ──→ post-validation
   │                            │               │
   │ Check shop health          │ Run k6         │ DB consistency
   │ Verify Stripe test mode    │ Upload results │ Contract states
   │                            │                │ Coupon integrity
```

**Artifacts:** `k6-results-{run_number}` (JSON + console output, 30-day retention)

**File:** `.github/workflows/load-test.yml`

---

### Technical Architecture

#### Tool Choice: k6 Browser Module (Grafana Labs)

**Why k6 with browser module:**
- k6 browser module uses Chromium — same engine as Playwright
- Reuses Playwright test data: same cards, coupons, selectors, user flows
- Full browser interaction: navigates shop UI, fills Stripe Checkout forms, handles 3DS
- Built-in thresholds for CI/CD pass/fail
- `ramping-arrival-rate` executor manages browser lifecycle efficiently
- JSON metric export for post-analysis

**Relationship to Playwright e2e:**
- Playwright e2e = functional correctness (83 specs, 97.5% pass rate)
- k6 browser = same flows under concurrent load (100 VU/min)
- k6 helpers mirror Playwright Helper.ts methods exactly (same selectors, same flow)
- Test data shared: `stripe-test-cards.ts` values hardcoded in k6 config

#### Project Structure (proposed)

```
tests/
├── e2e/playwright/          # Existing — UI correctness (unchanged)
└── load/                    # NEW — Performance & stress testing
    ├── k6.config.js         # k6 options, thresholds, scenarios
    ├── docker-compose.yml   # k6 + InfluxDB + Grafana stack
    ├── Makefile             # make load-test, make load-report
    │
    ├── scenarios/           # One file per user journey (SRP)
    │   ├── happy-path-checkout.js
    │   ├── mid-flow-cancellation.js
    │   ├── guest-coupon-checkout.js
    │   ├── payment-failure-retry.js
    │   └── 3ds-authentication.js
    │
    ├── helpers/             # Shared abstractions (DIP, DRY)
    │   ├── http-client.js       # Base HTTP client with cookie jar
    │   ├── auth.js              # Login/session management
    │   ├── basket.js            # Add to cart, apply coupon
    │   ├── checkout.js          # Checkout flow steps
    │   ├── stripe-api.js        # Stripe test API interactions
    │   └── assertions.js        # Custom k6 checks
    │
    ├── data/                # Test data (DI via config)
    │   ├── users.json           # Pre-seeded test users
    │   ├── products.json        # Product IDs and prices
    │   ├── coupons.json         # Coupon codes and expected discounts
    │   └── cards.json           # Stripe test card numbers
    │
    ├── validation/          # Post-run DB consistency checks
    │   ├── check-orphan-orders.sql
    │   ├── check-contract-states.sql
    │   ├── check-coupon-integrity.sql
    │   └── run-validation.sh
    │
    └── dashboards/          # Grafana dashboard JSON exports
        └── stripe-load-test.json
```

#### k6 Configuration (TDD: Thresholds First)

```javascript
// k6.config.js — Define pass/fail BEFORE writing scenarios
export const options = {
  scenarios: {
    happy_path: {
      executor: 'ramping-arrival-rate',
      startRate: 0,
      timeUnit: '1m',
      preAllocatedVUs: 50,
      maxVUs: 200,
      stages: [
        { duration: '2m', target: 40 },   // 40% of 100 = 40/min
        { duration: '10m', target: 40 },
        { duration: '1m', target: 0 },
      ],
      exec: 'happyPathCheckout',
    },
    cancellation: {
      executor: 'ramping-arrival-rate',
      startRate: 0,
      timeUnit: '1m',
      preAllocatedVUs: 25,
      maxVUs: 100,
      stages: [
        { duration: '2m', target: 20 },   // 20% of 100 = 20/min
        { duration: '10m', target: 20 },
        { duration: '1m', target: 0 },
      ],
      exec: 'midFlowCancellation',
    },
    guest_coupon: {
      executor: 'ramping-arrival-rate',
      startRate: 0,
      timeUnit: '1m',
      preAllocatedVUs: 20,
      maxVUs: 80,
      stages: [
        { duration: '2m', target: 15 },
        { duration: '10m', target: 15 },
        { duration: '1m', target: 0 },
      ],
      exec: 'guestCouponCheckout',
    },
    payment_failure: {
      executor: 'ramping-arrival-rate',
      startRate: 0,
      timeUnit: '1m',
      preAllocatedVUs: 20,
      maxVUs: 80,
      stages: [
        { duration: '2m', target: 15 },
        { duration: '10m', target: 15 },
        { duration: '1m', target: 0 },
      ],
      exec: 'paymentFailureRetry',
    },
    threeds: {
      executor: 'ramping-arrival-rate',
      startRate: 0,
      timeUnit: '1m',
      preAllocatedVUs: 15,
      maxVUs: 50,
      stages: [
        { duration: '2m', target: 10 },
        { duration: '10m', target: 10 },
        { duration: '1m', target: 0 },
      ],
      exec: 'threeDSAuthentication',
    },
  },

  thresholds: {
    // Global
    http_req_failed: ['rate<0.01'],                      // <1% errors
    http_req_duration: ['p(95)<5000', 'p(99)<10000'],    // p95 < 5s, p99 < 10s

    // Per-scenario
    'http_req_duration{scenario:happy_path}': ['p(95)<5000'],
    'http_req_duration{scenario:cancellation}': ['p(95)<3000'],
    'http_req_duration{scenario:guest_coupon}': ['p(95)<5000'],

    // Custom metrics
    'checkout_success_rate': ['rate>0.95'],              // >95% checkouts succeed
    'contract_state_valid': ['rate==1.0'],               // 100% valid transitions
    'stripe_api_errors': ['count<5'],                    // Near-zero Stripe errors
  },
};
```

#### Mapping Playwright Helpers → k6 Browser Functions

k6 browser module uses same Chromium selectors as Playwright. Functions mirror Helper.ts exactly:

| Playwright Helper | k6 Function | Same Selectors |
|-------------------|-------------|----------------|
| `setupLoggedInSession()` | `loginAndSetup(page, user)` | `input[name="lgn_usr"]`, cookie accept |
| `goToAxlePartsFromMenu()` + `addFirstTwoProducts...()` | `addProductsToCart(page)` | `#navigation .nav-item`, `#productList button.btn-highlight` |
| `startCheckoutViaMiniBasket()` | `navigateToCheckout(page)` | `button[data-bs-target="#basketModal"]` |
| `selectDigitalWalletPayment()` + `clickNext()` + `clickOrderNow()` | `selectStripeAndOrder(page)` | `#payment_oe_payments_stripe_wallet`, `#stripe-checkout-btn` |
| `fillStripeCardDetailsWithCard()` | `fillStripeCard(page, card)` | `#cardNumber`, Card radio, `#cardExpiry`, `#cardCvc` |
| `clickPayOnStripeCheckout()` | `clickStripePay(page)` | `[data-testid="hosted-payment-submit-button"]` |
| `click3DSComplete()` | `handle3DS(page, 'Complete')` | `iframe#challengeFrame`, `#test-source-authorize-3ds` |
| `assertThankYouPageAndGetOrderNumber()` | `verifyThankYou(page)` | `#thankyouPage`, regex `/order with number\s+(\d+)/` |
| `applyCoupon(code)` | `applyCoupon(page, code)` | `input[name="voucherNr"]` |

#### Post-Run Validation Queries

```sql
-- check-orphan-orders.sql
-- Orders without a valid contract (should be 0)
SELECT o.OXID, o.OXORDERNR, o.OXTOTALORDERSUM
FROM oxorder o
LEFT JOIN oe_payments_contract c ON c.OXORDERID = o.OXID
WHERE o.OXORDERDATE > DATE_SUB(NOW(), INTERVAL 1 HOUR)
  AND c.OXID IS NULL;

-- check-contract-states.sql
-- Contracts in invalid terminal states (should be 0)
SELECT OXID, OXSTATUS, OXORDERID
FROM oe_payments_contract
WHERE OXCREATEDAT > DATE_SUB(NOW(), INTERVAL 1 HOUR)
  AND OXSTATUS NOT IN ('fulfilled', 'cancelled', 'expired', 'failed')
  AND OXSTATUS IN ('draft', 'pending')  -- stuck contracts
  AND TIMESTAMPDIFF(MINUTE, OXCREATEDAT, NOW()) > 5;

-- check-coupon-integrity.sql
-- Single-use coupons used more than once (should be 0)
SELECT OXVOUCHERNR, COUNT(*) as usage_count
FROM oxvouchers
WHERE OXDATEUSED > DATE_SUB(NOW(), INTERVAL 1 HOUR)
  AND OXVOUCHERSERIEID IN (
    SELECT OXID FROM oxvoucherseries WHERE OXALLOWUSEDAGAIN = 0
  )
GROUP BY OXVOUCHERNR
HAVING COUNT(*) > 1;

-- check-deadlocks.sql
-- Recent deadlock events
SHOW ENGINE INNODB STATUS;  -- Parse LATEST DETECTED DEADLOCK section
```

---

### Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Stripe test mode rate limits (25 req/s default) | Throttled API calls, false failures | Request elevated test mode limits from Stripe; implement backoff in k6 |
| MySQL connection pool exhaustion | 500 errors under load | Monitor `SHOW PROCESSLIST`; tune `max_connections` (default 151 → 300) |
| PHP-FPM worker starvation | Queued requests, timeouts | Monitor `pm.status`; increase `pm.max_children` |
| Coupon race conditions | Double-use of single-use coupons | This IS what we're testing — log and report as a bug |
| Contract deadlocks on concurrent transitions | Failed state transitions | This IS what we're testing — log and report as a bug |
| Test data pollution | Invalid baseline for subsequent runs | Use unique user/coupon pools per run; cleanup script |

---

### Dependencies

- [ ] `pay1.oxid.dev` accessible and configured with Stripe test keys
- [ ] Docker installed on the load generation machine (or CI runner)
- [ ] k6 Docker image (`grafana/k6:latest`)
- [ ] InfluxDB + Grafana for metrics visualization (optional but recommended)
- [ ] 200+ pre-seeded test user accounts
- [ ] Sufficient coupon codes seeded in DB
- [ ] Product stock set high enough (or infinite) to avoid stock-out during tests
- [ ] Stripe test mode API keys configured on `pay1.oxid.dev`
- [ ] MySQL monitoring access (for post-run validation)

---

### References

- Existing Playwright E2E suite: `tests/e2e/playwright/`
- Stripe test cards: `tests/e2e/playwright/playwright/fixtures/stripe-test-cards.ts`
- Helper patterns: `tests/e2e/playwright/playwright/Helpers/Helper.ts`
- k6 documentation: https://k6.io/docs/
- Stripe rate limits: https://stripe.com/docs/rate-limits
- Smart-Contract Architecture: `docs/payment-component/00-overview.md`
