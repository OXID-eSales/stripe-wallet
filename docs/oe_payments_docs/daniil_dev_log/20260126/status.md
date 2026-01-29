# Development Status - 2026-01-26

**Last Updated:** 2026-01-26 14:30

---

## Current Test Results

```
PHPStan:        OK (0 errors)
PHPCS:          OK
PHPMD:          OK
PHPUnit:        OK (636 tests, 1549 assertions)
Status:         COMMITABLE
```

**All checks pass!**

---

## Context

**Previous State (2026-01-23):** All tests passing after Sprint 8-13 completion
**Today's Changes:**
1. Cleanup of unused/deprecated classes
2. Implemented 7 sprints to fix post-cleanup errors

### Classes Removed Today
- `FraudScoringService` + `FraudScoringServiceInterface` (unused - Stripe Radar used instead)
- `StockManagementService` + `StockManagementServiceInterface` (unused - OxidStockService used instead)
- `StripeCustomerService` (empty shell with direct SDK violation)
- `getStripeClient()` deprecated method from `StripeAdapterFactoryInterface`

---

## Completed Sprints

| Sprint | Title | Status | Changes |
|--------|-------|--------|---------|
| **14** | Fix OrderRefund Adapter Usage | DONE | Added `retrieveCharge()` to adapter |
| **15** | RequestLogService File-Based Logging | DONE | Created `RequestFileLoggerFactory`, refactored to `FileLoggerInterface` |
| **16** | WebhookController Centralized Logging | DONE | Created `WebhookLogService`, removed ~170 lines from controller |
| **17** | DELETE StripeCustomerService | DONE | Deleted empty shell with direct SDK violation |
| **18** | Refactor ConfigurationValidator | DONE | Added `testConnection()` to adapter, removed direct SDK |
| **19** | Fix Factory Type Issues | DONE | Added `is_string()` validation to all factories |
| **20** | Remove Registry Calls from Handlers | DONE | Created `SessionAdapterInterface`, inject adapters in handlers |

---

## Sprint 20 Summary

**Problem:** 96 PHPUnit errors + 3 failures caused by handlers calling `Registry::getLogger()`, `Registry::getConfig()`, `Registry::getSession()` which triggers OXID container build that fails in unit tests.

**Solution:**
1. Created `SessionAdapterInterface` and `OxidSessionAdapter` to abstract session access
2. Inject `ShopAdapterInterface` for shopId/shopUrl access
3. Inject `SessionAdapterInterface` for session variable access
4. Updated 6 handlers to use injected dependencies instead of Registry

**Files Created:**
- `src/Stripe/Adapter/SessionAdapterInterface.php`
- `src/Stripe/Adapter/OxidSessionAdapter.php`

**Handlers Modified:**
- `StripeCancelAuthorizationRequestHandler` - Added ShopAdapterInterface
- `StripeCaptureRequestHandler` - Added ShopAdapterInterface
- `StripeRefundRequestHandler` - Added ShopAdapterInterface
- `StripeCheckoutSessionHandler` - Added ShopAdapterInterface
- `StripeCheckoutReturnHandler` - Added SessionAdapterInterface
- `StripeOrderCreationHandler` - Added SessionAdapterInterface + LoggerInterface

**Tests Updated:**
- `StripeCancelAuthorizationRequestHandlerTest.php`
- `StripeCaptureRequestHandlerTest.php`
- `StripeRefundRequestHandlerTest.php`
- `StripeCheckoutSessionHandlerTest.php`
- `StripeCheckoutReturnHandlerTest.php`
- `AddressHashRestorationTest.php`
- `RequestLogServiceTest.php`

---

## Issues Resolved

| Issue | Resolution |
|-------|------------|
| Direct SDK pattern in `OrderRefund.php` | Uses `retrieveCharge()` via adapter |
| Missing `RequestLog` class | Replaced with `FileLoggerInterface` pattern |
| Inline logging in `WebhookController.php` | Extracted to `WebhookLogService` |
| Unused `$stripe` property in `StripeCustomerService.php` | Deleted entire class |
| Mixed type in rtrim (factories) | Added `is_string()` validation |
| Direct SDK in `ConfigurationValidator.testConnection()` | Uses adapter's `testConnection()` |
| Registry calls in handlers | Replaced with injected adapter interfaces |

---

## Files Modified/Created

### Created
- `src/Stripe/Adapter/SessionAdapterInterface.php`
- `src/Stripe/Adapter/OxidSessionAdapter.php`
- `src/Stripe/Service/Factory/RequestFileLoggerFactory.php`
- `src/Stripe/Service/Factory/WebhookFileLoggerFactory.php`
- `src/Stripe/Service/WebhookLogServiceInterface.php`
- `src/Stripe/Service/WebhookLogService.php`

### Modified
- `src/Stripe/Adapter/StripeAdapterInterface.php` - Added `retrieveCharge()`, `testConnection()`
- `src/Stripe/Adapter/StripeAdapter.php` - Implemented new methods
- `src/Stripe/Controller/Admin/OrderRefund.php` - Uses adapter methods
- `src/Stripe/Controller/Webhook/WebhookController.php` - Reduced ~170 lines, uses `WebhookLogService`
- `src/Stripe/Service/ConfigurationValidator.php` - Uses adapter factory
- `src/Stripe/Service/RequestLogService.php` - Uses `FileLoggerInterface`
- `src/Stripe/Service/Factory/EventFileLoggerFactory.php` - Added type validation
- `src/Stripe/Service/Factory/ReconciliationFileLoggerFactory.php` - Added type validation
- `src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php` - Inject ShopAdapterInterface
- `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php` - Inject ShopAdapterInterface
- `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` - Inject ShopAdapterInterface
- `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php` - Inject ShopAdapterInterface
- `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` - Inject SessionAdapterInterface
- `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php` - Inject SessionAdapterInterface + LoggerInterface
- `services.yaml` - Added new service registrations

### Deleted
- `src/Stripe/Service/StripeCustomerService.php`

---

## Architecture Improvements

1. **No Direct SDK Calls**: All Stripe SDK interactions go through `StripeAdapter`
   - Only `StripeClientFactory` creates `StripeClient` (legitimate factory pattern)

2. **No Registry Calls in Handlers**: All handlers use injected dependencies
   - `ShopAdapterInterface` for shopId, shopUrl
   - `SessionAdapterInterface` for session variables
   - `LoggerInterface` for logging

3. **Centralized Logging**: All file loggers use `FileLoggerInterface` from payment-component
   - `stripe_requests.log` - API request/response logging
   - `stripe_webhooks.log` - Webhook request/result logging
   - `stripe_events.log` - Event handler debugging
   - `stripe_reconciliation.log` - OXPAID reconciliation

4. **SRP Compliance**:
   - `WebhookController` reduced from ~295 to ~123 lines
   - Controller only handles HTTP concerns
   - Logging delegated to `WebhookLogService`

---

## Files Structure

```
docs/payment-component/daniil_dev_log/20260126/
├── status.md                                     (this file)
├── done/
│   ├── SPRINT-14-order-refund-adapter-usage.md
│   ├── SPRINT-15-request-log-service-file-logging.md
│   ├── SPRINT-16-webhook-controller-centralized-logging.md
│   ├── SPRINT-17-delete-stripe-customer-service.md
│   ├── SPRINT-18-refactor-configuration-validator.md
│   ├── SPRINT-19-fix-factory-type-issues.md
│   └── SPRINT-20-remove-registry-calls-from-handlers.md
└── reports/
    ├── 01-post-cleanup-errors-tdd-solutions.md
    └── 02-stripe-client-direct-usage-analysis.md
```

---

## Reference

- Last green CI: https://github.com/OXID-eSales/stripe-wallet/actions/runs/21295032247
- Previous dev log: `20260123/status.md`
