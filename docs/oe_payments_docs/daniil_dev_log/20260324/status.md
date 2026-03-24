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
