# Module ID and Namespace Update Report

**Date:** 2026-01-16
**Task:** Update module ID from `osc_stripe_wallet` to `oe_payments_stripe_wallet` and fix webhook handler namespaces

## Summary

Successfully updated the Stripe module ID and fixed namespace references for relocated webhook handlers. All tests pass.

## Changes Made

### 1. Module ID Update (`osc_stripe_wallet` → `oe_payments_stripe_wallet`)

#### Source Files
| File | Change |
|------|--------|
| `src/Stripe/Module.php` | Updated `MODULE_ID` constant |
| `src/Stripe/Core/StripeDefinitions.php` | Updated `STRIPE_WALLET_PAYMENT_ID` constant |
| `src/Stripe/Model/Payment.php` | Changed prefix detection from `osc_stripe_` to `oe_payments_stripe_` |
| `metadata.php` | Updated template namespace references |

#### Documentation
| File | Change |
|------|--------|
| `CLAUDE.md` | Updated module ID references |
| `INSTALLATION.md` | Updated all `osc_stripe_wallet` to `oe_payments_stripe_wallet` |
| `QUICK_START.md` | Updated module activation commands |

#### Templates
| File | Change |
|------|--------|
| `views/twig/frontend/base_js.html.twig` | Updated `getModuleUrl()` and `@include` references |
| `views/twig/frontend/buy_now_button.html.twig` | Updated `getModuleUrl()` reference |

#### Scripts
| File | Change |
|------|--------|
| `recipe/setup-twig-dev.sh` | Updated composer package name and module activation command |

#### Tests
| File | Change |
|------|--------|
| `tests/Unit/Stripe/Model/PaymentTest.php` | Updated test data from `osc_stripe_` to `oe_payments_stripe_` prefix |
| `tests/Unit/Stripe/Model/OrderAddressValidationTest.php` | Updated prefix pattern check |

### 2. Webhook Handler Namespace Update

Handlers were moved from old namespaces to `OxidEsales\Payments\Stripe\WebhookHandler\`:

| Old Namespace | New Namespace |
|--------------|---------------|
| `...\Webhook\Handler\PaymentIntentSucceededHandler` | `...\WebhookHandler\PaymentIntentSucceededHandler` |
| `...\Webhook\Handler\ChargeRefundedHandler` | `...\WebhookHandler\ChargeRefundedHandler` |
| `...\Handler\WebhookContractFulfillmentHandler` | `...\WebhookHandler\WebhookContractFulfillmentHandler` |
| `...\Handler\WebhookContractFulfillmentHandlerInterface` | `...\WebhookHandler\WebhookContractFulfillmentHandlerInterface` |

#### Files Updated
- `services.yaml` - Service definitions
- `src/Stripe/Service/WebhookProcessingService.php` - Import statement
- Multiple test files in `tests/Unit/` and `tests/Integration/`

### 3. Migration Test Fix

| File | Change |
|------|--------|
| `tests/Integration/Database/MigrationStructureTest.php` | Replaced `migrations-db.php` loading with OXID's `ConnectionProviderInterface` |

### 4. Payment-component CI and Migrations Update

| File | Change |
|------|--------|
| `payment-component/composer.json` | Removed hardcoded `migrations-db.php` reference from migrations script |
| `payment-component/.github/workflows/development.yml` | Creates temporary db-config on-the-fly using OXID's config settings |

### 5. Stripe CI Migrations Update

| File | Change |
|------|--------|
| `stripe/.github/workflows/development.yml` | Added migration step to `isolated_unit_tests` job |
| `stripe/.github/workflows/development.yml` | Updated migration step in `integration_tests` job to use dynamic db-config |

Both CI workflows now dynamically generate a `migrations-db.php` that reads database credentials from OXID's `config.inc.php`, eliminating the need for a standalone config file in the repo.

## Test Results

### Stripe Module Tests
```
Unit Tests:      561 passed (11 deprecations, 1 incomplete)
Integration:     217 passed (6 skipped, 1 incomplete)
```

## Files Modified (Complete List)

### Source Files
1. `src/Stripe/Module.php`
2. `src/Stripe/Core/StripeDefinitions.php`
3. `src/Stripe/Model/Payment.php`
4. `src/Stripe/Service/WebhookProcessingService.php`
5. `metadata.php`
6. `services.yaml`

### Documentation
7. `CLAUDE.md`
8. `INSTALLATION.md`
9. `QUICK_START.md`

### Templates
10. `views/twig/frontend/base_js.html.twig`
11. `views/twig/frontend/buy_now_button.html.twig`

### Scripts
12. `recipe/setup-twig-dev.sh`

### Tests
13. `tests/Unit/Stripe/Model/PaymentTest.php`
14. `tests/Unit/Stripe/Model/OrderAddressValidationTest.php`
15. `tests/Unit/Stripe/Webhook/Handler/ChargeRefundedHandlerTest.php`
16. `tests/Unit/Stripe/Webhook/Handler/PaymentIntentSucceededHandlerTest.php`
17. `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php`
18. `tests/Unit/Stripe/Service/WebhookProcessingServiceRepositoryTest.php`
19. `tests/Integration/Database/MigrationStructureTest.php`
20. `tests/Integration/Stripe/Webhook/WebhookContractTransitionTest.php`
21. `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php`
22. `tests/Integration/Stripe/Webhook/ContractAwareOxpaidWebhookTest.php`
23. `tests/Integration/Stripe/Webhook/DelayedCaptureIntegrationTest.php`
24. `tests/Integration/Stripe/Webhook/DisputeWebhookTest.php`
25. `tests/Integration/Stripe/Webhook/PaymentIntentWebhookTest.php`
26. `tests/Integration/Stripe/Webhook/ChargeWebhookTest.php`

### Payment-component CI
27. `../payment-component/.github/workflows/development.yml`

## Notes

1. **Controller IDs Unchanged**: The controller IDs in `metadata.php` (`osc_stripe_payment`, `osc_stripe_webhook`) were intentionally left unchanged as they define URL routes. Changing them would break existing URLs and bookmarks.

2. **Backward Compatibility**: The module now uses `oe_payments_stripe_` as the payment method prefix for future payment methods, aligning with the new OXID eSales naming convention.

3. **CI Migration Fix**: The payment-component CI was updated to run migrations without the standalone `migrations-db.php` file, using doctrine-migrations directly within the OXID shop context.
