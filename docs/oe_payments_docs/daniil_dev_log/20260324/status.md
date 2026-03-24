# Status — 2026-03-24

## Sprint 75: STRP-104 Empty Shipping Triggers 500 on Payment Page

| Step | Description | Status |
|------|-------------|--------|
| 1 | Create failing test for null paymentId in isStripeSelected() | done |
| 2 | Fix PaymentController::isStripeSelected() — null-safe check | done |
| 3 | Disable "Weiter" button when no shipping/payment methods available | done |
| 4 | Run full pre-commit check | done |

**Overall:** completed

### Results

- **Unit tests:** 784 pass, 0 failures
- **Integration tests:** 164 run, 9 pre-existing WebhookEndpointE2ETest failures (HTTP connectivity)
- **PHPCS:** 0 errors
- **PHPStan:** 0 errors (level max)
- **PHPMD:** 0 errors

### Changes

**`src/Stripe/Controller/PaymentController.php`**
- Fixed `isStripeSelected()`: added `is_string()` null-guard before `str_starts_with()`
- Added PHPStan annotation for OXID's `getPaymentId()` which returns null despite PHPDoc saying string

**`views/twig/extensions/themes/default/page/checkout/payment.html.twig`**
- Added `{% block checkout_payment_nextstep %}` override
- When no shipping methods (`oView.getAllSets()`) or no payment methods (`oView.getPaymentList()`) available: shows warning message, disables "Weiter" button (grey, non-clickable)

**`tests/Unit/Stripe/Controller/PaymentControllerNullPaymentIdTest.php`** (new)
- 4 tests: null paymentId crash proof, null-safe fix validation, Stripe detection still works, empty string edge case

---

## Sprint 76: Security Fixes S1–S4 from Cross-Module Audit

| Step | Description | Status |
|------|-------------|--------|
| 1 | Failing tests for S1: ContractTokenService must reject missing secret | done |
| 2 | Fix S1: remove hardcoded fallback, throw on missing secret | done |
| 3 | Failing tests for S2: isConfigured() must check webhook secret | done |
| 4 | Fix S2: uncomment webhook secret check | done |
| 5 | Failing test for S3: log warning when guard chain unavailable | skipped (no testable seam without OXID bootstrap) |
| 6 | Fix S3: add warning log on guard chain failure | done |
| 7 | Fix S4: clean dead code template | done |
| 8 | Run full pre-commit check | done |

**Overall:** completed

### Results

- **Unit tests:** 788 pass (+4 new), 0 failures
- **PHPCS:** 0 errors | **PHPStan:** 0 errors (level max) | **PHPMD:** 0 errors

### Changes

**`src/Stripe/Service/ContractTokenService.php`** (S1)
- Removed hardcoded `TOKEN_SECRET` constant
- Constructor throws `\RuntimeException` when no API secret or webhook secret is configured

**`src/Stripe/Service/ModuleConfigurationService.php`** (S2)
- `isConfigured()`: uncommented webhook secret check → `!empty($this->getToken()) && !empty($this->getWebhookSecret())`

**`src/Stripe/Controller/Webhook/WebhookController.php`** (S3)
- Guard chain failure now logs `Registry::getLogger()->warning()` instead of silent catch

**`views/twig/frontend/base_stripe_payment_controller_config.html.twig`** (S4)
- Replaced 73 lines of dead code (stoken leaks, XDEBUG, `|raw`) with single-line comment

**`tests/Unit/Stripe/Service/ContractTokenServiceTest.php`** (S1 test)
- Replaced `testUsesDefaultSecretWhenNoneConfigured` → `testThrowsExceptionWhenNoSecretConfigured`

**`tests/Unit/Stripe/Service/ModuleConfigurationServiceIsConfiguredTest.php`** (S2 tests, new)
- 4 tests: webhook secret empty → false, all keys present → true, token empty → false, both empty → false
