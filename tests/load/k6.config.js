/**
 * k6 Load Test — Stripe Payment (Browser Mode)
 *
 * Entry point: imports scenarios and helpers, defines options + thresholds.
 * All scenario functions must be re-exported here for k6's executor to find them.
 *
 * Usage:
 *   k6 run k6.config.js                               # defaults: 100 VU/min, 10 min, all scenarios
 *   k6 run --env K6_DRY_RUN=true k6.config.js         # 1 VU, 1 iteration per scenario
 *   k6 run --env K6_TARGET_VUS=10 k6.config.js        # quick 10 VU smoke test
 *   k6 run --env K6_SCENARIO=happy_path k6.config.js   # single scenario
 *
 * Sprint 81 — High-Load Testing
 * See: docs/oe_payments_docs/daniil_dev_log/20260402/sprints/
 */

import { TARGET_VUS, DURATION, RAMP_UP, SCENARIO, DRY_RUN, TRAFFIC } from './helpers/config.js';

// ─── Re-export scenarios (k6 requires exports from entry point) ───
export { happy_path }      from './scenarios/happy-path.js';
export { cancellation }    from './scenarios/cancellation.js';
export { guest_coupon }    from './scenarios/guest-coupon.js';
export { payment_failure } from './scenarios/payment-failure.js';
export { threeds }         from './scenarios/threeds.js';

// ─── Scenario builder ─────────────────────────────────────────────

function buildScenario(name, fraction) {
  const target = Math.max(1, Math.round(TARGET_VUS * fraction));

  if (DRY_RUN) {
    return {
      executor: 'per-vu-iterations',
      vus: 1,
      iterations: 1,
      exec: name,
      options: { browser: { type: 'chromium' } },
    };
  }

  return {
    executor: 'ramping-arrival-rate',
    startRate: 0,
    timeUnit: '1m',
    preAllocatedVUs: Math.ceil(target * 0.5),
    maxVUs: target * 2,
    stages: [
      { duration: `${RAMP_UP}m`,  target: target },
      { duration: `${DURATION}m`, target: target },
      { duration: '1m',           target: 0 },
    ],
    exec: name,
    options: { browser: { type: 'chromium' } },
  };
}

function buildScenarios() {
  if (SCENARIO !== 'all') {
    return { [SCENARIO]: buildScenario(SCENARIO, 1.0) };
  }

  const scenarios = {};
  for (const [name, fraction] of Object.entries(TRAFFIC)) {
    scenarios[name] = buildScenario(name, fraction);
  }
  return scenarios;
}

// ─── Options ──────────────────────────────────────────────────────

export const options = {
  scenarios: buildScenarios(),

  // Accept self-signed certs (localhost.local)
  insecureSkipTLSVerify: true,

  thresholds: {
    // Browser HTTP errors
    browser_http_req_failed: ['rate<0.05'],

    // Custom metrics (from helpers/metrics.js)
    checkout_success_rate:   ['rate>0.90'],
    contract_state_valid:    ['rate==1.0'],
    stripe_api_errors:       ['count<10'],
    checkout_duration:       ['p(95)<60000'],   // 60s for full browser flow
    orders_created:          ['count>0'],
  },
};
