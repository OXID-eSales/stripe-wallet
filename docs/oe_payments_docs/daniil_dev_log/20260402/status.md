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

### Sprint Steps

| Step | Description | Status |
|------|-------------|--------|
| 1 | **Infrastructure Setup** — Provision k6 + InfluxDB + Grafana on pay1.oxid.dev or CI runner | todo |
| 2 | **Stripe Test Data Seeding** — Create 200+ test users, seed coupon codes, ensure product stock | todo |
| 3 | **Scenario 1: Happy Path** — k6 script with login → browse → basket → checkout → payment → verify | todo |
| 4 | **Scenario 2: Cancellation** — k6 script simulating Stripe Checkout abandonment via API | todo |
| 5 | **Scenario 3: Guest + Coupon** — k6 script for guest flow with discount codes | todo |
| 6 | **Scenario 4: Payment Failure & Retry** — k6 script with declined cards and retry logic | todo |
| 7 | **Scenario 5: 3DS Flow** — k6 script with 3DS card and authentication simulation | todo |
| 8 | **Shared Helpers Module** — Extract reusable HTTP request builders (DRY from Playwright helpers) | todo |
| 9 | **Threshold Definitions** — Codify pass/fail criteria as k6 thresholds (TDD: define before run) | todo |
| 10 | **Baseline Run** — Single-user dry run per scenario to establish baseline metrics | todo |
| 11 | **Ramp-Up Test** — 0 → 50 → 100 users/min, 13-minute full cycle | todo |
| 12 | **Sustained Load Test** — 100 users/min for 30 minutes (extended endurance) | todo |
| 13 | **Post-Run Validation** — DB consistency checks: orphan orders, contract states, coupon integrity | todo |
| 14 | **Monitoring Dashboard** — Grafana dashboard for real-time metrics during load test | todo |
| 15 | **CI Integration** — Docker Compose service for k6, triggered via `make load-test` | todo |
| 16 | **Report & Analysis** — Document findings, bottlenecks, recommendations | todo |

**Overall status:** not started

---

### Technical Architecture

#### Tool Choice: k6 (Grafana Labs)

**Why k6 over Playwright for load testing:**
- Playwright e2e tests run at browser level (heavy, ~1 browser per VU) — unsuitable for 100+ concurrent users
- k6 operates at HTTP/protocol level — lightweight, thousands of VUs per machine
- k6 has native Stripe API support via HTTP requests
- k6 thresholds provide built-in pass/fail for CI/CD
- k6 exports metrics to InfluxDB/Grafana for real-time dashboards

**Why we still keep Playwright e2e:**
- Playwright validates UI rendering, JavaScript behavior, Stripe.js iframe interaction
- k6 validates backend performance, API correctness, data consistency under load
- **Complementary, not competing** — Playwright = correctness, k6 = performance

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

#### Mapping Playwright Helpers → k6 Helpers

| Playwright (Browser-Level) | k6 (HTTP-Level) | Notes |
|---------------------------|-----------------|-------|
| `Helper.setupLoggedInSession()` | `auth.login(user)` → cookie jar | HTTP POST to login endpoint |
| `Helper.addProductToCart(url)` | `basket.addProduct(productId)` | POST to basket controller |
| `Helper.applyCoupon(code)` | `basket.applyCoupon(code)` | POST to voucher controller |
| `Helper.proceedToStripeCheckout()` | `checkout.initiateStripe()` | POST order → get Stripe session |
| `StripeCheckoutPage.fillCardDetails()` | `stripe.confirmPayment(sessionId, card)` | Direct Stripe API call |
| `ThankYouPage.verifyOrderSuccess()` | `assertions.checkOrderCreated()` | HTTP GET thank-you + status check |

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
