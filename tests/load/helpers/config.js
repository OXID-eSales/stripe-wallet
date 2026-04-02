/**
 * Environment config and test data constants.
 *
 * All values from Playwright fixtures/stripe-test-cards.ts.
 * Injected via CI environment variables with sensible defaults.
 */

// ─── Environment ──────────────────────────────────────────────────
export const BASE_URL   = __ENV.K6_BASE_URL   || 'https://daniil.oxiddev.de';
export const TARGET_VUS = parseInt(__ENV.LOAD_TARGET_VUS || '100');
export const DURATION   = __ENV.LOAD_DURATION   || '10';
export const RAMP_UP    = __ENV.LOAD_RAMP_UP    || '2';
export const SCENARIO   = __ENV.LOAD_SCENARIO   || 'all';
export const DRY_RUN    = __ENV.LOAD_DRY_RUN    === 'true';

// ─── Stripe Test Cards (from stripe-test-cards.ts) ────────────────
export const CARDS = {
  VISA_SUCCESS:  '4111111111111111',
  VISA_4242:     '4242424242424242',
  MASTERCARD:    '5555555555554444',
  DECLINED:      '4000000000000002',
  INSUFFICIENT:  '4000000000009995',
  REQUIRES_3DS:  '4000000000003220',
  EXPIRED:       '4000000000000069',
  INCORRECT_CVC: '4000000000000127',
};

export const CARD_DETAILS = {
  EXPIRY: '03/33',
  CVC:    '640',
  NAME:   'Test Stripe',
};

// ─── Coupons (from stripe-test-cards.ts) ──────────────────────────
export const COUPONS = {
  TEN_PERCENT:   'E2E_10PCT',
  FIFTY_PERCENT: 'E2E_50PCT',
  FIVE_FLAT:     'E2E_5FLAT',
};

// ─── Users ────────────────────────────────────────────────────────
export const TEST_PASSWORD = __ENV.K6_TEST_USER_PASSWORD || 'useruser';
export const USER_COUNT    = 200;

// ─── Traffic Distribution ─────────────────────────────────────────
export const TRAFFIC = {
  happy_path:      0.40,
  cancellation:    0.20,
  guest_coupon:    0.15,
  payment_failure: 0.15,
  threeds:         0.10,
};
