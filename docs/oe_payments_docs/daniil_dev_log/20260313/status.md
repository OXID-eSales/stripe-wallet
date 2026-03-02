# Status — 2026-03-13

## Sprint 73: Fix StripeOrderControllerTest Missing RetryCleanupService Mock

| Step | Description | Status |
|------|-------------|--------|
| 1 | Add RetryCleanupService mock to getServiceFromContainer | done |
| 2 | Run full pre-commit check | done |

**Overall:** completed

## Sprint 74: StripeOrderControllerTest Coverage Gaps

| Step | Description | Status |
|------|-------------|--------|
| 1 | Move test file from Integration/ to Unit/ | done |
| 2 | Fix captureMode default test (F1) | done |
| 3 | Add processContextResults tests — 3DS, error, orderId (F2, F3) | done |
| 4 | Add checkoutSuccess security validation tests (F4) | done |
| 5 | Add createCheckoutSession error path tests (F7) | done |
| 6 | Add checkoutCancel cleanup failure test (F6) | done |
| 7 | Add edge case tests — expired session, stripeReturn data, user mock fix (F8, F9, F10) | done |
| 8 | Run full pre-commit check | done |

**Overall:** completed

### Results

- **Unit tests:** 780 pass, 0 failures
- **Integration tests:** 164 run, 9 pre-existing WebhookEndpointE2ETest failures (HTTP connectivity)
- **PHPStan:** 0 errors (level max)
- **Tests added:** 14 new tests (12→25 in StripeOrderControllerTest, +1 in RetryTest)
- **All 10 findings addressed:** F1-F10

### Changes

**`tests/Unit/Stripe/Controller/StripeOrderControllerTest.php`** (moved from Integration/)
- Namespace: `Tests\Integration` → `Tests\Unit`
- Fixed captureMode fake default (F1)
- Added 3DS, error display, orderId session tests (F2, F3)
- Added checkoutSuccess security validation: missing contractId, missing token, invalid token, mismatch (F4)
- Added createCheckoutSession error paths: expired session, invalid API key, empty basket, no user (F7)
- Added executeStripePayment expired session test (F9)
- Added stripeReturn context data verification (F10)
- Fixed user mock to use basket user (F8)
- Refactored createControllerWithMocks: configurable keyValidationError, sessionChallengeResult, independent contractId keys, exposed tplParams

**`tests/Unit/Stripe/Controller/StripeOrderControllerRetryTest.php`**
- Added testCheckoutCancelContinuesOnCleanupFailure (F6)
