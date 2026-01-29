# Stripe Test Tokens Fix

## Issue Summary

Integration tests were failing with Stripe CardException because they were sending raw credit card numbers directly to the Stripe API:

> Sending credit card numbers directly to the Stripe API is generally unsafe. We suggest you use test tokens that map to the test card you are using, see https://stripe.com/docs/testing. To enable testing raw card data APIs, see https://support.stripe.com/questions/enabling-access-to-raw-card-data-apis.

**Failing Tests**:
- `testDeletesMultiplePaymentMethods`
- `testCompletePaymentMethodLifecycle`
- Various payment method creation tests

**Date Reported**: 2025-11-07

## Root Cause

The test helper `StripeIntegrationTestCase::createTestPaymentMethod()` was creating PaymentMethods by sending raw card data (card numbers, expiry, CVC) directly to Stripe's API:

```php
$paymentMethod = $this->stripeClient->paymentMethods->create([
    'type' => 'card',
    'card' => [
        'number' => '4242424242424242',  // ❌ Raw card data
        'exp_month' => 12,
        'exp_year' => (int) date('Y') + 2,
        'cvc' => '123',
    ],
]);
```

Stripe now requires using test tokens instead for security reasons.

## Solution

### 1. Updated Test Helper (`tests/Integration/Stripe/StripeIntegrationTestCase.php`)

Changed `createTestPaymentMethod()` to use Stripe test tokens:

```php
protected function createTestPaymentMethod(string $cardNumber = '4242424242424242'): \Stripe\PaymentMethod
{
    // Map card numbers to Stripe test tokens
    $testToken = match ($cardNumber) {
        '4242424242424242' => 'tok_visa',
        '5555555555554444' => 'tok_mastercard',
        '378282246310005' => 'tok_amex',
        '6011111111111117' => 'tok_discover',
        '3056930009020004' => 'tok_diners',
        '3566002020360505' => 'tok_jcb',
        default => 'tok_visa',
    };

    // Create PaymentMethod using test token (safe method)
    $paymentMethod = $this->stripeClient->paymentMethods->create([
        'type' => 'card',
        'card' => [
            'token' => $testToken,  // ✅ Use token instead
        ],
    ]);

    return $paymentMethod;
}
```

### 2. Updated Integration Tests

**File**: `tests/Integration/Stripe/Adapter/StripePaymentMethodIntegrationTest.php`

Changed all tests that directly created PaymentMethods to use tokens:

**Before**:
```php
$request = new CreatePaymentMethodRequest(
    paymentMethod: 'card',
    paymentMethodData: [
        'card' => [
            'number' => '4242424242424242',  // ❌ Raw card data
            'exp_month' => 12,
            'exp_year' => (int) date('Y') + 1,
            'cvc' => '123',
        ],
    ],
);
```

**After**:
```php
$request = new CreatePaymentMethodRequest(
    paymentMethod: 'card',
    paymentMethodData: [
        'card' => [
            'token' => 'tok_visa',  // ✅ Use test token
        ],
    ],
);
```

### 3. Added return_url to PaymentIntent Confirmations

Tests that created PaymentIntents with `confirm=true` now include required `return_url`:

```php
$paymentIntent = $this->stripeClient->paymentIntents->create([
    'amount' => 2500,
    'currency' => 'eur',
    'customer' => $customer->id,
    'payment_method' => $paymentMethodId,
    'confirm' => true,
    'capture_method' => 'automatic',
    'return_url' => 'https://example.com/return',  // ✅ Required when confirming
]);
```

### 4. Made Payment Method Customer Attachment Optional

Updated request/response classes to support payment methods that aren't attached to customers:

**`CreatePaymentMethodRequest.php`**:
```php
public function __construct(
    public string $paymentMethod,
    public ?string $customerId = null,  // Now nullable
    public array $paymentMethodData = [],
    // ...
)
```

**`PaymentMethodResponse.php`**:
```php
public function __construct(
    public string $paymentMethodId,
    public ?string $customerId,  // Now nullable
    // ...
)
```

### 5. Updated Test Assertions

Since test tokens have fixed values:
- **tok_visa**: last4 = `4242`, brand = `visa`
- **tok_mastercard**: last4 = `4444`, brand = `mastercard`
- **tok_amex**: last4 = `8431`, brand = `amex`

Updated assertions to match these values instead of original card numbers.

## Test Results

### All PaymentMethod Integration Tests Passing ✅

```
Tests: 11, Assertions: 71
OK (but there were issues - just PHPUnit deprecations)
```

**Passing Tests**:
1. ✅ `testCreatesCardPaymentMethod`
2. ✅ `testCreatesPaymentMethodAndAttachesToCustomer`
3. ✅ `testCreatesPaymentMethodWithBillingAddress`
4. ✅ `testCreatesPaymentMethodWithMetadata`
5. ✅ `testListsCustomerPaymentMethods`
6. ✅ `testListsEmptyPaymentMethodsForNewCustomer`
7. ✅ `testDeletesSinglePaymentMethod`
8. ✅ `testDeletesMultiplePaymentMethods` (was failing)
9. ✅ `testListsMultiplePaymentMethods`
10. ✅ `testCompletePaymentMethodLifecycle` (was failing)
11. ✅ `testPaymentMethodPersistsAcrossMultiplePayments`

## Stripe Test Tokens Reference

| Token | Card Type | Last 4 Digits | Use Case |
|-------|-----------|---------------|----------|
| `tok_visa` | Visa | 4242 | Standard successful payment |
| `tok_mastercard` | Mastercard | 4444 | Mastercard testing |
| `tok_amex` | American Express | 8431 | Amex testing |
| `tok_discover` | Discover | - | Discover testing |
| `tok_diners` | Diners Club | - | Diners testing |
| `tok_jcb` | JCB | - | JCB testing |

See [Stripe Testing Documentation](https://stripe.com/docs/testing#cards) for more test tokens.

## Files Modified

1. **`tests/Integration/Stripe/StripeIntegrationTestCase.php`** - Updated helper to use tokens
2. **`tests/Integration/Stripe/Adapter/StripePaymentMethodIntegrationTest.php`** - Updated all tests to use tokens
3. **`src/Component/Adapter/Request/CreatePaymentMethodRequest.php`** - Made customerId nullable
4. **`src/Component/Adapter/Response/PaymentMethodResponse.php`** - Made customerId nullable

## Benefits

1. **Security**: No longer sending raw card data to API
2. **Compliance**: Following Stripe best practices
3. **Reliability**: Test tokens are always available and don't expire
4. **Consistency**: All tests use standardized test tokens
5. **Future-proof**: Aligns with Stripe's security requirements

## Migration Notes

Any new tests that need to create payment methods should:

1. Use the `createTestPaymentMethod()` helper from `StripeIntegrationTestCase`
2. If creating PaymentMethods directly via adapter, use tokens in `paymentMethodData`:
   ```php
   'card' => ['token' => 'tok_visa']
   ```
3. Always include `return_url` when confirming PaymentIntents

---

**Date**: 2025-11-07
**Status**: ✅ Complete
**Reviewed**: Ready for deployment
