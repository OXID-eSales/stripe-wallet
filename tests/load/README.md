# Load Tests — Stripe Payment (k6 Browser)

Load testing framework for the Stripe payment module using [k6](https://k6.io/) with the browser module (Chromium). Tests replicate Playwright e2e flows under concurrent load.

## Quick Start

```bash
# Dry-run — 1 VU, 1 iteration (validate the flow works)
K6_BROWSER_ENABLED=true \
K6_BASE_URL=http://localhost.local \
K6_TEST_USER_EMAIL=playwright.user@oxid-esales.dev \
K6_BROWSER_ARGS='ignore-certificate-errors' \
LOAD_DRY_RUN=true \
LOAD_SCENARIO=happy_path \
./bin/k6 run k6.config.js
```

## Running Tests

All commands run from `tests/load/`. The k6 binary is at `./bin/k6`.

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `K6_BROWSER_ENABLED` | **Required.** Enables k6 browser module | — |
| `K6_BASE_URL` | Shop URL (HTTP, no trailing slash) | `https://daniil.oxiddev.de` |
| `K6_TEST_USER_EMAIL` | Login email for authenticated scenarios | random `loadtest_user_NNN` |
| `K6_TEST_USER_PASSWORD` | Login password | `useruser` |
| `K6_BROWSER_ARGS` | Chromium flags (comma-separated) | — |
| `LOAD_DRY_RUN` | `true` = 1 VU, 1 iteration | `false` |
| `LOAD_SCENARIO` | Run single scenario by name | `all` |
| `LOAD_TARGET_VUS` | Target virtual users per minute | `100` |
| `LOAD_DURATION` | Steady-state duration in minutes | `10` |
| `LOAD_RAMP_UP` | Ramp-up duration in minutes | `2` |

> **Note:** Use `LOAD_` prefix for custom vars. k6 reserves `K6_VUS`, `K6_DURATION` etc. as built-in overrides that conflict with our scenario config.

### Examples

```bash
# --- Base command (always needed) ---
export K6_BROWSER_ENABLED=true
export K6_BASE_URL=http://localhost.local
export K6_TEST_USER_EMAIL=playwright.user@oxid-esales.dev
export K6_BROWSER_ARGS='ignore-certificate-errors'

# 1. Dry-run single scenario (debug / validate selectors)
LOAD_DRY_RUN=true LOAD_SCENARIO=happy_path ./bin/k6 run k6.config.js

# 2. Smoke test — 5 VUs/min, 2 min, happy_path only
LOAD_TARGET_VUS=5 LOAD_DURATION=2 LOAD_SCENARIO=happy_path ./bin/k6 run k6.config.js

# 3. Baseline — 10 VUs/min, 5 min, all scenarios
LOAD_TARGET_VUS=10 LOAD_DURATION=5 ./bin/k6 run k6.config.js

# 4. Medium load — 50 VUs/min, 10 min
LOAD_TARGET_VUS=50 LOAD_DURATION=10 ./bin/k6 run k6.config.js

# 5. Full load — 100 VUs/min, 10 min (default)
./bin/k6 run k6.config.js

# 6. Endurance — 100 VUs/min, 30 min
LOAD_DURATION=30 ./bin/k6 run k6.config.js

# 7. Stress test — 200 VUs/min, 10 min
LOAD_TARGET_VUS=200 ./bin/k6 run k6.config.js

# 8. Single scenario under load
LOAD_TARGET_VUS=20 LOAD_DURATION=5 LOAD_SCENARIO=cancellation ./bin/k6 run k6.config.js

# 9. Custom ramp-up (slow start)
LOAD_TARGET_VUS=50 LOAD_RAMP_UP=5 LOAD_DURATION=10 ./bin/k6 run k6.config.js
```

### Load Profile

When `LOAD_DRY_RUN` is not set, k6 uses a **ramping-arrival-rate** executor:

```
VUs/min
  ^
  |          ┌──────────────────────────┐
  |         /│      steady state        │\
  |        / │    (LOAD_DURATION min)   │ \
  |       /  │                          │  \
  |      /   │                          │   \
  |     /    │                          │    \
  |    /     │                          │     \
  |───/──────┴──────────────────────────┴──────\───> time
  | ramp-up                              cool-down
  | (LOAD_RAMP_UP min)                   (1 min)
```

Traffic is split across scenarios according to the distribution in `helpers/config.js`:

| Scenario | Share | At 100 VUs/min |
|----------|-------|----------------|
| `happy_path` | 40% | 40 VUs/min |
| `cancellation` | 20% | 20 VUs/min |
| `guest_coupon` | 15% | 15 VUs/min |
| `payment_failure` | 15% | 15 VUs/min |
| `threeds` | 10% | 10 VUs/min |

## Scenarios

| Scenario | Description |
|----------|-------------|
| `happy_path` | Login → add to cart → checkout → Stripe Checkout → pay with 4242 card → thank you page |
| `cancellation` | Login → add to cart → checkout → Stripe Checkout → navigate back without paying |
| `guest_coupon` | Guest → add to cart → apply coupon → navigate to checkout user step |
| `payment_failure` | Login → add to cart → checkout → pay with declined card → see error → retry with valid card |
| `threeds` | Login → add to cart → checkout → pay with 3DS card → complete 3DS challenge → thank you page |

## Project Structure

```
tests/load/
├── k6.config.js             # Entry point: scenario builder + thresholds
├── .env                     # Local config (not committed)
├── bin/
│   └── k6                   # k6 binary
├── helpers/
│   ├── config.js            # Env vars, test cards, coupons, traffic split
│   ├── metrics.js           # Custom k6 Rate/Counter/Trend
│   ├── auth.js              # Login, cookies, language switch
│   ├── shop.js              # Products, cart, checkout navigation, coupons
│   └── stripe.js            # Card fill, pay, 3DS, thank you, error handling
├── scenarios/
│   ├── happy-path.js
│   ├── cancellation.js
│   ├── guest-coupon.js
│   ├── payment-failure.js
│   └── threeds.js
└── results/                 # Run output logs
```

## Thresholds

| Metric | Threshold | Description |
|--------|-----------|-------------|
| `browser_http_req_failed` | < 5% | HTTP error rate |
| `checkout_success_rate` | > 90% | Successful checkouts |
| `contract_state_valid` | = 100% | Valid contract transitions |
| `stripe_api_errors` | < 10 | Total Stripe errors |
| `checkout_duration` p95 | < 60s | Full browser flow time |
| `orders_created` | > 0 | At least one order created |

## k6 Browser API Notes

k6 browser is **not** Playwright. Key differences that affect helper code:

- No `.first()` on locators — k6 locator already targets first match
- No `page.waitForURL()` — poll `page.url()` in a loop
- No `locator.isVisible()` — use `waitFor` with try/catch
- No `{ hasText }` option — use `page.evaluate()` for text-based DOM queries
- `page.evaluate()` clicks don't trigger React event handlers — use `locator.click()` for React UIs (e.g. Stripe Checkout)
- `page.evaluate()` that triggers navigation causes "context lost" — extract hrefs first, then `page.goto()`

## Test Data

From `tests/e2e/playwright/playwright/fixtures/stripe-test-cards.ts`:

- **Cards:** 4242424242424242 (success), 4000000000000002 (declined), 4000000000003220 (3DS)
- **Card details:** 03/33, CVC 640, "Test Stripe"
- **Coupons:** E2E_10PCT, E2E_50PCT, E2E_5FLAT
- **Users:** `loadtest_user_001..200@oxid-esales.dev` or single `playwright.user@oxid-esales.dev`
