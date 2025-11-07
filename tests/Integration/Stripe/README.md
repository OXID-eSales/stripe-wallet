# Stripe Integration Tests

Comprehensive integration tests for the Stripe payment adapter that interact with **real Stripe API**.

## Overview

These tests validate the complete integration between:
- `src/Stripe/Adapter/StripeAdapter.php` → Stripe SDK v18 → Real Stripe API

All tests use real Stripe test API keys and make actual HTTP requests to Stripe's test environment.

## Test Structure

```
tests/Integration/Stripe/
├── README.md (this file)
├── StripeIntegrationTestCase.php         # Base test case with helpers
└── Adapter/
    ├── StripeAdapterIntegrationTest.php           # Payment creation, capture, refund, void
    ├── StripeAuthorizationFlowIntegrationTest.php # Two-step authorization flow
    ├── StripePaymentMethodIntegrationTest.php     # Vaulting/tokenization
    └── Stripe3DSecureIntegrationTest.php          # 3D Secure/SCA flow
```

## Test Coverage

### StripeAdapterIntegrationTest (13 tests)
- ✅ Payment creation (manual/automatic capture)
- ✅ Payment with saved payment methods
- ✅ Payment with metadata
- ✅ Full and partial capture
- ✅ Full and partial refunds
- ✅ Refund reasons (customer_requested, fraudulent, duplicate)
- ✅ Void/cancel payments
- ✅ Get payment details

### StripeAuthorizationFlowIntegrationTest (10 tests)
- ✅ Authorization creation
- ✅ Authorization with saved cards
- ✅ Capture full/partial authorization
- ✅ Void authorization (confirmed/unconfirmed)
- ✅ Reauthorization (not supported, throws exception)
- ✅ Complete authorization lifecycle
- ✅ Authorization expiration date (7 days)

### StripePaymentMethodIntegrationTest (12 tests)
- ✅ Create card payment method
- ✅ Attach payment method to customer
- ✅ Payment method with billing address
- ✅ Payment method with metadata
- ✅ List customer payment methods
- ✅ Delete payment methods
- ✅ Complete payment method lifecycle
- ✅ Payment method persistence across payments

### Stripe3DSecureIntegrationTest (10 tests)
- ✅ Initiate 3DS for payments requiring action
- ✅ 3DS status mapping (authenticated, pending, not_required)
- ✅ Verify 3DS result
- ✅ 3DS with direct capture
- ✅ 3DS data in payment response

**Total: 45 integration tests**

## Configuration

### 1. Stripe Test Credentials

Copy and configure credentials in `tests/.env`:

```bash
cd tests/
cp .env.dist .env
```

Edit `tests/.env` with your Stripe test credentials:

```env
# Get from https://dashboard.stripe.com/test/apikeys
STRIPE_TEST_SECRET_KEY=sk_test_your_secret_key_here
STRIPE_TEST_PUBLISHABLE_KEY=pk_test_your_publishable_key_here

# Get from https://dashboard.stripe.com/test/webhooks (optional)
STRIPE_WEBHOOK_SECRET=whsec_your_webhook_secret_here

# API version (SDK v18 uses latest by default)
STRIPE_API_VERSION=2023-10-16
```

### 2. Enable Raw Card Data API (IMPORTANT!)

**The tests require creating payment methods from raw card numbers, which Stripe disables by default for security.**

To enable this for testing:

1. Go to: https://dashboard.stripe.com/test/settings/integration
2. Find "**Enable APIs that use raw card data**"
3. Click "**Request access**"
4. Fill out the form explaining this is for **automated integration testing**
5. Wait for approval (usually instant for test mode)

Alternatively, you can use Stripe's SetupIntent flow, but it requires more complex test setup.

### 3. Verify Test Mode

Ensure you're using **test** credentials (start with `sk_test_` and `pk_test_`).

**NEVER use live credentials in tests!**

## Running Tests

### Run All Stripe Integration Tests

```bash
vendor/bin/phpunit tests/Integration/Stripe/ --testdox
```

### Run Specific Test Suite

```bash
# Payment operations
vendor/bin/phpunit tests/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php --testdox

# Authorization flow
vendor/bin/phpunit tests/Integration/Stripe/Adapter/StripeAuthorizationFlowIntegrationTest.php --testdox

# Payment methods
vendor/bin/phpunit tests/Integration/Stripe/Adapter/StripePaymentMethodIntegrationTest.php --testdox

# 3D Secure
vendor/bin/phpunit tests/Integration/Stripe/Adapter/Stripe3DSecureIntegrationTest.php --testdox
```

### Run in Docker

```bash
docker compose exec php bash
cd /var/www/extensions/stripe
vendor/bin/phpunit tests/Integration/Stripe/ --testdox
```

### Run with Groups

```bash
# Run only integration tests
vendor/bin/phpunit --group integration

# Run only Stripe tests
vendor/bin/phpunit --group stripe

# Run only authorization tests
vendor/bin/phpunit --group authorization
```

## Test Data & Cleanup

### Test Cards

Tests use Stripe's test card numbers:
- `4242424242424242` - Visa success
- `5555555555554444` - Mastercard success
- `378282246310005` - Amex success
- `4000000000000002` - Card declined
- `4000000000009995` - Insufficient funds

See: https://stripe.com/docs/testing#cards

### Automatic Cleanup

The base test case (`StripeIntegrationTestCase`) automatically tracks and cleans up:
- Payment intents (cancelled if possible)
- Customers (deleted)
- Payment methods (detached)

Cleanup runs in `tearDown()` after each test.

## Troubleshooting

### Error: "Sending credit card numbers directly to the Stripe API is generally unsafe"

**Solution**: Enable "raw card data API" in your Stripe test dashboard (see Configuration step 2)

### Error: "No such test key"

**Solution**:
1. Verify you copied test credentials to `tests/.env`
2. Ensure credentials start with `sk_test_` (not `sk_live_`)
3. Check credentials are from https://dashboard.stripe.com/test/apikeys

### Error: "This PaymentIntent requires a return_url"

**Solution**: This is expected for certain payment methods. Tests include `return_url` where needed.

### Tests are slow

Integration tests make real API calls, so they're slower than unit tests:
- Average: 5-15 seconds per test
- Total runtime: ~2-5 minutes for all 45 tests

This is normal for integration tests.

### Test failures due to rate limiting

**Solution**: Stripe test mode has rate limits. Wait a minute and retry.

## Test Principles (TDD, SOLID, Clean Code)

### TDD Approach
1. Tests written **before** implementation
2. Each test validates **one specific behavior**
3. Tests are **independent** (can run in any order)
4. Tests use **real Stripe API** (not mocks)

### SOLID Principles
- **Single Responsibility**: Each test class focuses on one feature area
- **Open/Closed**: Base test case is extensible via inheritance
- **Liskov Substitution**: All tests extend `StripeIntegrationTestCase`
- **Interface Segregation**: Tests use specific request/response DTOs
- **Dependency Inversion**: Tests depend on `PaymentAdapterInterface`

### Clean Code
- **Descriptive test names**: `testCreatesPaymentIntentWithManualCapture()`
- **Arrange-Act-Assert** pattern in every test
- **No magic numbers**: Constants and named variables
- **Helper methods**: Reduce duplication (createTestCustomer, etc.)
- **Comments**: Explain "why", not "what"

### Strict Types
- ✅ `declare(strict_types=1)` in all files
- ✅ Type hints for all parameters and return values
- ✅ Strict assertions (assertEquals, not loose comparisons)

## CI/CD Integration

### GitHub Actions Example

```yaml
jobs:
  stripe-integration-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install dependencies
        run: composer install

      - name: Run Stripe Integration Tests
        env:
          STRIPE_TEST_SECRET_KEY: ${{ secrets.STRIPE_TEST_SECRET_KEY }}
          STRIPE_TEST_PUBLISHABLE_KEY: ${{ secrets.STRIPE_TEST_PUBLISHABLE_KEY }}
        run: vendor/bin/phpunit tests/Integration/Stripe/ --testdox
```

Store credentials in GitHub repository secrets.

## Related Documentation

- [Stripe Testing Guide](https://stripe.com/docs/testing)
- [Stripe SDK v18 Documentation](https://github.com/stripe/stripe-php)
- [Payment Component Architecture](../../docs/payment-component/04-sdk-adapter-layer.md)
- [Test Organization](../../docs/payment-component/10-test-organization.md)

## Support

For issues or questions:
1. Check Stripe Dashboard logs: https://dashboard.stripe.com/test/logs
2. Review test output with `--testdox` and `--debug` flags
3. Check Stripe API status: https://status.stripe.com/

---

**Version**: 1.0.0
**Last Updated**: 2025-01-07
**Stripe SDK**: v18
**PHP**: 8.2+
**PHPUnit**: 11.5+
