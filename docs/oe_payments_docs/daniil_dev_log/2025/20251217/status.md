# Status - 2025-12-17

**Feature:** Bug Fixes and Event Logging
**Branch:** b-7.4.x-code-review-STRP-75

---

## Current Sprint Status

| Sprint | Description | Status | Notes |
|--------|-------------|--------|-------|
| Sprint 1 | Fix missing buy-now.css | **DONE** | Created assets/css/ folder |
| Sprint 2 | Add event system logging | **DONE** | EventFileLoggerFactory + handler logging |
| Sprint 3 | Fix manual capture redirect issue | **DONE** | Order now created in manual capture mode |
| Sprint 4 | Add logging to all event handlers | **DONE** | Extended to 10 Stripe handlers |

---

## Progress Summary

### Completed Today
- [x] **Sprint 1:** Fixed missing buy-now.css file
  - Created `assets/css/` directory
  - Copied `buy-now.css` from `resources/build/scss/`
- [x] **Sprint 2:** Added event system logging infrastructure
  - Created `EventFileLoggerFactory`
  - Initial logging in 3 handlers
- [x] **Sprint 3:** Fixed manual capture redirect issue
  - Modified `handleRequiresCaptureStatus()` to dispatch PaymentAuthorizedEvent
  - Modified `StripeOrderCreationHandler` to skip OXPAID for manual capture
  - Updated unit test expectations
- [x] **Sprint 4:** Extended event logging to all handlers
  - Added logging to `StripeCheckoutSessionHandler`
  - Added logging to `StripeContractCreationHandler`
  - Added logging to `StripeCaptureRequestHandler`
  - Added logging to `StripeRefundRequestHandler`
  - Added logging to `OrderPaymentCompletedHandler`
  - Added logging to `StripePaymentReturnHandler`
  - Added logging to `StripePaymentStatusHandler`
  - Updated services.yaml with logger injection
- [x] Created sprint documentation in done/ folder

### In Progress
- (none)

### Blocked
- (none)

### Verified
- [x] E2E test passed (redirect to thankyou page working)
- [x] All style checks passing (PHPStan, PHPCS, PHPMD)
- [x] Full unit test suite passing (1783 tests)

---

## Code Quality Baseline

Current session (2025-12-17):

| Metric | Value | Status |
|--------|-------|--------|
| Unit Tests | 1783 | PASSING |
| PHPStan Level max | OK | PASSING |
| PHPCS (PSR-12) | OK | PASSING |
| PHPMD | OK | PASSING |

---

## Handlers with Event Logging

All Stripe event handlers now have comprehensive logging:

| Handler | Events Handled |
|---------|----------------|
| `StripeContractCreationHandler` | `StripeCheckoutSessionRequestEvent` |
| `StripeCheckoutSessionHandler` | `StripeCheckoutSessionRequestEvent` |
| `StripeCheckoutReturnHandler` | `StripeCheckoutReturnEvent` |
| `PaymentAuthorizedEventHandler` | `PaymentAuthorizedEvent` |
| `StripeOrderCreationHandler` | `ContractReadyToCommitEvent` |
| `StripeCaptureRequestHandler` | `StripeCaptureRequestEvent` |
| `StripeRefundRequestHandler` | `StripeRefundRequestEvent` |
| `OrderPaymentCompletedHandler` | `ContractFulfilledEvent` |
| `StripePaymentReturnHandler` | `StripePaymentReturnEvent` |
| `StripePaymentStatusHandler` | `StripePaymentExecuteEvent` |

---

## Files Modified

| File | Changes |
|------|---------|
| `services.yaml` | Added event logger injection to all handlers |
| `StripeCheckoutReturnHandler.php` | Added logging; dispatch event in manual capture |
| `PaymentAuthorizedEventHandler.php` | Added event logging |
| `StripeOrderCreationHandler.php` | Added logging; conditional OXPAID update |
| `StripeCheckoutSessionHandler.php` | Added event logging |
| `StripeContractCreationHandler.php` | Added event logging |
| `StripeCaptureRequestHandler.php` | Added event logging |
| `StripeRefundRequestHandler.php` | Added event logging |
| `OrderPaymentCompletedHandler.php` | Added event logging |
| `StripePaymentReturnHandler.php` | Added event logging |
| `StripePaymentStatusHandler.php` | Added event logging |
| `StripeCheckoutReturnHandlerTest.php` | Updated test for PaymentAuthorizedEvent dispatch |

## Files Created

| File | Purpose |
|------|---------|
| `EventFileLoggerFactory.php` | Factory for event system logger |
| `assets/css/buy-now.css` | Buy Now button styles |
| `done/sprint-1-buy-now-css.md` | Sprint 1 documentation |
| `done/sprint-1-buy-now-css-report.md` | Sprint 1 report |
| `done/sprint-2-event-logging.md` | Sprint 2 documentation |
| `done/sprint-2-event-logging-report.md` | Sprint 2 report |
| `done/sprint-3-manual-capture-redirect.md` | Sprint 3 documentation |
| `done/sprint-3-manual-capture-redirect-report.md` | Sprint 3 report |

---

## Commands Reference

```bash
# Reinstall module
docker compose exec -T php bin/oe-console oe:module:deactivate osc_stripe_wallet
docker compose exec -T php bin/oe-console oe:module:install extensions/stripe
docker compose exec -T php bin/oe-console oe:module:activate osc_stripe_wallet

# Clear cache
docker compose exec -T php rm -rf var/cache/*

# Style checks only
./bin/pre-commit-check.sh --no-phpunit

# Full checks (with tests)
./bin/pre-commit-check.sh --full

# E2E test
cd tests/e2e/playwright && SHOP_URL=https://daniil.oxiddev.de npx playwright test tests/checkout/stripe-checkout.spec.ts

# View event log
cat source/log/osc/stripe_events.log
```

---

**Last Updated:** 2025-12-17
