/**
 * Custom k6 metrics for load test thresholds.
 */

import { Counter, Rate, Trend } from 'k6/metrics';

export const checkoutSuccess  = new Rate('checkout_success_rate');
export const contractValid    = new Rate('contract_state_valid');
export const stripeErrors     = new Counter('stripe_api_errors');
export const checkoutDuration = new Trend('checkout_duration', true);
export const orderNumbers     = new Counter('orders_created');
