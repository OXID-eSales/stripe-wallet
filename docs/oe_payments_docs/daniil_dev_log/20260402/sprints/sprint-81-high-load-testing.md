# Sprint 81: High-Load Testing — Stripe Payment Under Stress

**Date:** 2026-04-02
**Server:** pay1.oxid.dev
**Target:** 100 users/min sustained load

## Summary

This sprint establishes a comprehensive load testing framework for the Stripe payment module using k6 at the HTTP protocol level. The existing Playwright e2e tests (83 specs, 97.5% pass rate) validate UI correctness; this sprint validates **backend resilience, data consistency, and performance** under realistic concurrent traffic.

## Why k6, Not Playwright for Load?

Playwright runs a full Chromium browser per virtual user — at 100 concurrent users, that's 100 browsers consuming ~300MB RAM each = **30GB RAM minimum**. Impossible.

k6 runs at the HTTP level — 100 virtual users consume ~50MB total. It can simulate thousands of users from a single machine.

**The two tools are complementary:**
- **Playwright** answers: "Does the UI work correctly?" (functional correctness)
- **k6** answers: "Does the backend hold up under load?" (performance + data integrity)

## Scenario Design (SOLID)

### Single Responsibility Principle
Each scenario file handles exactly one user journey. No scenario knows about other scenarios.

```
scenarios/
├── happy-path-checkout.js      # Login → Browse → Buy → Verify
├── mid-flow-cancellation.js    # Login → Browse → Buy → Abandon → Verify cleanup
├── guest-coupon-checkout.js    # Guest → Coupon → Buy → Verify discount
├── payment-failure-retry.js    # Login → Declined card → Retry → Success
└── 3ds-authentication.js       # Login → 3DS card → Authenticate → Verify
```

### Open/Closed Principle
The k6 config uses named scenarios. Adding a new user journey = adding a new file + entry in config. No existing code changes.

### Liskov Substitution Principle
All scenarios follow the same lifecycle interface:
```javascript
// Every scenario exports a default function with the same signature
export default function () {
  const session = setup();      // Arrange
  const result = execute(session); // Act
  validate(result);             // Assert
}
```

### Interface Segregation
Helpers are split by concern, not bundled into one mega-helper:
- `auth.js` — login, session management
- `basket.js` — add to cart, apply coupon, remove item
- `checkout.js` — proceed to payment, handle redirects
- `stripe-api.js` — confirm payment, simulate 3DS
- `assertions.js` — k6 check() wrappers with custom metrics

### Dependency Inversion
Scenarios depend on helper abstractions, not on HTTP details:
```javascript
// Good (DIP): scenario depends on abstraction
import { login } from '../helpers/auth.js';
import { addToCart } from '../helpers/basket.js';

// Bad: scenario hardcodes HTTP details
http.post('https://pay1.oxid.dev/index.php?cl=basket&fnc=tobasket', ...);
```

## TDD Approach

**Step 1: Define thresholds (the "test"):**
```javascript
thresholds: {
  http_req_failed: ['rate<0.01'],           // FAIL if >1% errors
  http_req_duration: ['p(95)<5000'],        // FAIL if p95 > 5s
  checkout_success_rate: ['rate>0.95'],     // FAIL if <95% succeed
  contract_state_valid: ['rate==1.0'],      // FAIL if ANY invalid state
}
```

**Step 2: Run against empty scenarios (they fail — RED).**

**Step 3: Implement scenarios (they pass — GREEN).**

**Step 4: Optimize bottlenecks found (REFACTOR).**

## Traffic Distribution

| Scenario | % of Traffic | VUs/min | Rationale |
|----------|-------------|---------|-----------|
| Happy Path | 40% | 40 | Most common real-world flow |
| Cancellation | 20% | 20 | High drop-off rate in real e-commerce |
| Guest + Coupon | 15% | 15 | Marketing campaign simulation |
| Payment Failure | 15% | 15 | Edge case but critical for data integrity |
| 3DS Auth | 10% | 10 | EU PSD2 compliance flow |
| **Total** | **100%** | **100** | |

## DevOps First

### Docker Compose for Load Test Stack

```yaml
# tests/load/docker-compose.yml
services:
  k6:
    image: grafana/k6:latest
    volumes:
      - ./:/scripts
    environment:
      - K6_OUT=influxdb=http://influxdb:8086/k6
      - BASE_URL=https://pay1.oxid.dev
      - STRIPE_TEST_KEY=${STRIPE_TEST_KEY}
    command: run /scripts/k6.config.js
    depends_on:
      - influxdb

  influxdb:
    image: influxdb:1.8
    ports:
      - "8086:8086"
    environment:
      - INFLUXDB_DB=k6

  grafana:
    image: grafana/grafana:latest
    ports:
      - "3000:3000"
    volumes:
      - ./dashboards:/var/lib/grafana/dashboards
    depends_on:
      - influxdb
```

### Makefile Integration

```makefile
# From module root
load-test:
	cd tests/load && docker compose up --abort-on-container-exit k6

load-test-report:
	cd tests/load && docker compose up -d grafana influxdb
	@echo "Open http://localhost:3000 for Grafana dashboard"

load-test-validate:
	cd tests/load && ./validation/run-validation.sh

load-test-clean:
	cd tests/load && docker compose down -v
```

### CI Pipeline (GitHub Actions)

```yaml
load-test:
  runs-on: ubuntu-latest
  needs: [unit-tests, e2e-tests]  # Only after functional tests pass
  steps:
    - uses: actions/checkout@v4
    - name: Run k6 load test
      run: make load-test
    - name: Validate DB consistency
      run: make load-test-validate
    - name: Upload k6 results
      uses: actions/upload-artifact@v4
      with:
        name: k6-results
        path: tests/load/results/
```

## Data Seeding Strategy

### Test Users (200+ accounts)
```sql
-- Seed script: tests/load/data/seed-users.sql
-- Pattern: loadtest_user_001@oxid-esales.dev through loadtest_user_200@oxid-esales.dev
-- All with password: 'useruser' (hashed)
-- Each with pre-configured billing/shipping address
-- Distributed across 4 country groups for VAT variation
```

### Coupon Pool
```sql
-- 500 single-use coupons: LOAD_10PCT_001 through LOAD_10PCT_500
-- 100 multi-use coupons: LOAD_MULTI_10PCT, LOAD_MULTI_50PCT, etc.
-- Matching patterns from Playwright fixtures but scaled for concurrency
```

### Product Stock
```sql
-- Set stock to 99999 for all test products to avoid stock-out
UPDATE oxarticles SET OXSTOCK = 99999 WHERE OXID IN (...test product IDs...);
```

## Key Metrics to Capture

### Real-Time (during test)
- Requests/second (by endpoint)
- Response time percentiles (p50, p95, p99)
- Error rate (by HTTP status code)
- Active VUs (ramping visualization)
- Stripe API latency (webhook delivery time)

### Post-Run (validation)
- Total orders created vs expected
- Contract state distribution (fulfilled/cancelled/expired/failed)
- Orphan orders (orders without contracts)
- Coupon double-usage violations
- Database deadlock count
- PHP error log entries during test window
- MySQL slow query log entries

## Stripe-Specific Considerations

### Rate Limits
Stripe test mode default: **25 requests/second**. With 100 users/min and ~3-5 Stripe API calls per checkout:
- Peak: ~100 × 4 / 60 = ~7 req/s — within limits
- But burst during ramp-up could hit limits
- **Mitigation:** k6 `rps` limiter + exponential backoff in helpers

### Webhook Delivery Under Load
- Stripe delivers webhooks asynchronously
- Under load, webhook processing delay increases
- **Test:** Measure time from payment_intent.succeeded to contract FULFILLED
- **Threshold:** Webhook-to-fulfillment < 30 seconds at p95

### Idempotency
- `oe_payments_idempotency` table prevents duplicate charges
- Load test MUST verify: zero duplicate charges even under concurrent retries
- k6 scenario: same user retries payment rapidly (simulates double-click)

## Expected Bottlenecks (Hypotheses)

1. **MySQL `oe_payments_contract` table** — Row-level locking during concurrent state transitions
2. **PHP-FPM worker pool** — Worker starvation if Stripe API calls block
3. **Session handling** — Concurrent requests from same user may cause session race conditions
4. **Coupon reservation** — Single-use coupon `SELECT ... FOR UPDATE` may deadlock under concurrency
5. **Basket serialization** — `OXBASKETDATA` JSON serialization overhead with large baskets

Each hypothesis will be confirmed or rejected by the load test results.

## Deliverables

1. `tests/load/` directory with complete k6 test suite
2. Docker Compose stack for local and CI execution
3. Grafana dashboard JSON for import
4. Post-run validation SQL scripts
5. Data seeding scripts (users, coupons, stock)
6. Load test report with findings and recommendations
7. Makefile targets: `load-test`, `load-test-report`, `load-test-validate`
