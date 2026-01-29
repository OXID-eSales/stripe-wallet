# Development Status - 2026-01-29

**Last Updated:** 2026-01-29
**Previous State:** 606 tests (stripe), ALL CHECKS PASS, COMMITABLE
**Current State:** 619 tests (stripe), ALL CHECKS PASS, COMMITABLE
**Completed Today:** Sprint 26 (LazyStripeAdapter), Sprint 27 (Extract to payment-component), Sprint 28 (Controller IDs), Sprint 29 (StatusMappingConfig), Template Prefix Fix

---

## Core Requirements

All code must follow these principles:

| Requirement | Description |
|-------------|-------------|
| **TDD-First** | Write failing tests first, then implementation |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **DRY** | Don't Repeat Yourself - extract common patterns |
| **Clean Code** | Meaningful names, small functions (15-25 lines), no else expressions (use early returns) |
| **PSR-12** | PHP coding style standard |

**Testing Requirements:**
- All changes must pass pre-commit checks: `./bin/pre-commit-check.sh`
- Unit tests: `docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit`

---

## Context

Continuing from the major cleanup work done Jan 21-28:
- Sprint 5-7: Webhook infrastructure, controller removal, repository cleanup
- Sprint 8-13: Service extraction (RequestLog, Capture, Refund, CancelAuth)
- Sprint 14-20: Registry removal, centralized logging, session adapters
- Sprint 22-25: Refund cleanup, stock management, DTO consolidation

---

## Sprints

| Sprint | Title | Status |
|--------|-------|--------|
| **26** | Fix Module Activation Without API Keys | **COMPLETED** |
| **27** | Extract Services to payment-component | **COMPLETED** |
| **28** | Rename Controller IDs | **COMPLETED** |
| **29** | Extract Status Mapping to Config Class | **COMPLETED** |
| **30** | Fix Template Prefix for Payment ID | **COMPLETED** |

---

## Sprint 30 Summary (Bug Fix)

**Problem:** The order template used `replace({'osc_': ''})` to derive the payment method template name, but the payment ID was `oe_payments_stripe_wallet`. This caused the template lookup to fail and show "Error: payment method unsupported".

**Root Cause:** Legacy prefix `osc_` was never updated to match the new payment ID prefix `oe_payments_`.

**Solution:** Changed the replace filter to use the correct prefix.

### File Modified

| File | Change |
|------|--------|
| `views/twig/extensions/themes/default/page/checkout/order.html.twig` | Line 44: `replace({'osc_': ''})` → `replace({'oe_payments_': ''})` |

### Template Lookup Now Works

- Payment ID: `oe_payments_stripe_wallet`
- After replace: `stripe_wallet`
- Template found: `@oe_payments_stripe_wallet/frontend/stripe_wallet.html.twig` ✓

---

## Sprint 27 Summary

**Problem:** Provider-specific services (ContractMetadata, DeliveryAddressHash, RequestLog, SessionAdapter, logger factories, OrderPaymentCompletedHandler) were in Stripe but were provider-agnostic.

**Solution:** Moved interfaces and implementations to payment-component. Stripe now depends on payment-component abstractions (Dependency Inversion).

### Files Created in payment-component

| File | Purpose |
|------|---------|
| `src/Service/Factory/AbstractFileLoggerFactory.php` | Template Method for logger factories |
| `src/Adapter/SessionAdapterInterface.php` | Session operations interface |
| `src/Service/ContractMetadataServiceInterface.php` | Contract metadata interface |
| `src/Service/ContractMetadataService.php` | Contract metadata implementation |
| `src/Service/DeliveryAddressHashServiceInterface.php` | Delivery hash interface |
| `src/Service/DeliveryAddressHashService.php` | Delivery hash implementation |
| `src/Service/RequestLogServiceInterface.php` | Request logging interface |
| `src/EventSystem/Handler/OrderPaymentCompletedHandler.php` | OXPAID update handler |

### Files Deleted from Stripe (moved to payment-component)

- `src/Stripe/Service/ContractMetadataServiceInterface.php`
- `src/Stripe/Service/ContractMetadataService.php`
- `src/Stripe/Service/DeliveryAddressHashServiceInterface.php`
- `src/Stripe/Service/DeliveryAddressHashService.php`
- `src/Stripe/Service/RequestLogServiceInterface.php`
- `src/Stripe/Adapter/SessionAdapterInterface.php`
- `src/Stripe/EventSystem/Handler/OrderPaymentCompletedHandler.php`

### Files Refactored in Stripe

| File | Change |
|------|--------|
| `EventFileLoggerFactory.php` | Extends AbstractFileLoggerFactory |
| `ReconciliationFileLoggerFactory.php` | Extends AbstractFileLoggerFactory |
| `RequestFileLoggerFactory.php` | Extends AbstractFileLoggerFactory |
| `OxidSessionAdapter.php` | Implements payment-component interface |
| `RequestLogService.php` | Implements payment-component interface |

### Architecture

```
payment-component/                          Stripe/
├── AbstractFileLoggerFactory               ├── EventFileLoggerFactory (extends)
│   └── create() [template method]          ├── ReconciliationFileLoggerFactory (extends)
├── SessionAdapterInterface                 ├── RequestFileLoggerFactory (extends)
├── ContractMetadataServiceInterface        └── OxidSessionAdapter (implements)
├── DeliveryAddressHashServiceInterface     └── RequestLogService (implements)
├── RequestLogServiceInterface
└── OrderPaymentCompletedHandler
```

---

## Sprint 27 Preview (COMPLETED)

Services to extract to payment-component:

| Category | Items |
|----------|-------|
| **Interfaces** | CaptureServiceInterface, CancelAuthorizationServiceInterface, RefundServiceInterface, HostedCheckoutReturnServiceInterface, RequestLogServiceInterface |
| **Services** | ContractMetadataService, DeliveryAddressHashService |
| **Handler** | OrderPaymentCompletedHandler |
| **Adapter** | SessionAdapterInterface, SessionAdapter |
| **Factory** | AbstractFileLoggerFactory (Template Method Pattern) |

Stripe logger factories will extend AbstractFileLoggerFactory with Stripe-specific log paths:
- `EventFileLoggerFactory` → `log/osc/stripe_events.log`
- `ReconciliationFileLoggerFactory` → `log/osc/stripe_reconciliation.log`
- `RequestFileLoggerFactory` → `log/osc/stripe_requests.log`

---

## Sprint 28 Summary

**Problem:** Controller IDs in metadata.php used inconsistent naming (e.g., `osc_stripe_webhook`, `stripe_order_refund`).

**Solution:** Renamed all controller IDs to follow consistent naming convention (class names as IDs).

### ID Mapping

| Old ID | New ID |
|--------|--------|
| `osc_stripe_payment` | `StripePaymentController` |
| `osc_stripe_webhook` | `StripeWebhookController` |
| `stripe_order_refund` | `OrderRefund` |
| `orderController` | `StripeOrderController` |

### Files Modified

| File | Change |
|------|--------|
| `tests/Integration/Module/MetadataTest.php` | Updated controller ID assertions |
| `menu.xml` | Updated `cl` attribute |
| `views/twig/admin/stripe_order_refund.html.twig` | Updated all `cl` values |
| `src/Stripe/Service/ModuleConfigurationService.php` | Updated webhook URL |
| `recipe/.../oe_payments_stripe_wallet.yaml` | Updated controller IDs |

### Breaking Change

**Webhook URL Change:**
- OLD: `index.php?cl=osc_stripe_webhook`
- NEW: `index.php?cl=StripeWebhookController`

After deployment, update webhook URL in Stripe Dashboard.

---

## Sprint 26 Summary

**Problem:** Module activation failed when Stripe API keys weren't configured because the DI container tried to create the adapter during compilation.

**Solution:** Created `LazyStripeAdapter` that defers adapter creation until first actual use.

### Files Created

| File | Purpose |
|------|---------|
| `src/Stripe/Adapter/LazyStripeAdapter.php` | Lazy proxy adapter |

### Files Modified

| File | Change |
|------|--------|
| `src/Stripe/Service/CaptureService.php` | Uses factory instead of adapter |
| `src/Stripe/Service/CancelAuthorizationService.php` | Uses factory instead of adapter |
| `src/Stripe/Service/StripeCaptureService.php` | Updated docstring |
| `src/Stripe/Service/StripeRefundService.php` | Updated docstring |
| `services.yaml` | Removed factory service, added LazyStripeAdapter |
| Tests | Updated to mock factory instead of adapter |

---

## Test Results

```
PHPUnit tests passed (619 tests, 1493 assertions)
PHP Code Sniffer passed
PHPStan passed
PHPMD passed
Status: COMMITABLE
```

---

## Verification Commands

```bash
# Module activation test
docker compose exec php bin/oe-console oe:module:deactivate oe_payments_stripe_wallet
docker compose exec php rm -rf var/cache/*
docker compose exec php bin/oe-console oe:module:activate oe_payments_stripe_wallet
# SUCCESS!

# Pre-commit checks
./bin/pre-commit-check.sh
# ALL CHECKS PASSED

# Unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

---

## Files Structure

```
docs/payment-component/daniil_dev_log/20260129/
├── status.md                                         (this file)
├── todo/                                             (empty - all sprints completed)
├── done/
│   ├── SPRINT-26-fix-activation-without-keys.md      (completed sprint)
│   ├── SPRINT-27-extract-services-to-payment-component.md (completed sprint)
│   └── SPRINT-28-rename-controller-ids.md            (completed sprint)
└── reports/
    └── (as needed)
```

---

## Reference

- Previous dev log: `20260128/status.md`
- Module config: `var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml`
- Services config: `services.yaml`
