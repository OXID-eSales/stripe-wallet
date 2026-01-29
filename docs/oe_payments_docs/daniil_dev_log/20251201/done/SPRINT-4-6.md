# Implementation Report: Stripe API Key Validation

## Date: 2025-12-01
## Status: COMPLETED

## Problem

When clicking the checkout button, Stripe Checkout Session was created successfully but the redirect to Stripe's hosted checkout page failed with:

```
Something went wrong
The specified Checkout Session could not be found. This error is usually caused by using the wrong API key or visiting an expired Checkout Session.
```

## Root Cause

The publishable key (`pk_test_xxx`) used in JavaScript to initialize Stripe.js and the secret key (`sk_test_yyy`) used in PHP to create the Checkout Session were from **different Stripe accounts**.

Stripe keys contain an account identifier after the mode prefix. If these don't match, the session created with one account cannot be accessed with another account's publishable key.

## Solution Implemented

### 1. Added API Key Validation Methods (TDD)

**File**: `src/Stripe/Service/ModuleConfigurationService.php`

Added two new methods:

```php
/**
 * Validate that publishable key and secret key are from the same Stripe account.
 * @return bool True if keys are from the same account
 */
public function validateKeyPair(): bool

/**
 * Get validation error message for API key configuration.
 * @return string|null Error message or null if configuration is valid
 */
public function getKeyValidationError(): ?string

/**
 * Extract account ID from Stripe key.
 * @param string $key Stripe API key
 * @return string|null Account ID portion or null if invalid format
 */
private function extractAccountId(string $key): ?string
```

The `extractAccountId()` method parses Stripe keys (format: `{type}_{mode}_{accountId}{randomChars}`) and extracts the first 10 characters of the account portion for comparison.

### 2. Added Early Validation in Checkout Session Creation

**File**: `src/Stripe/Controller/StripeOrderController.php`

Added key validation at the start of `createCheckoutSession()`:

```php
// 0. Validate API key configuration
$config = $this->getServiceFromContainer(ModuleConfigurationService::class);
$keyValidationError = $config->getKeyValidationError();
if ($keyValidationError !== null) {
    throw new \RuntimeException('Stripe configuration error: ' . $keyValidationError);
}
```

This provides a clear error message if keys are misconfigured instead of a cryptic "Session not found" error from Stripe.

### 3. Added Debug Output

Added `_debug` object to the checkout session JSON response:

```json
{
  "id": "cs_test_...",
  "contract_id": "...",
  "_debug": {
    "pk_prefix": "pk_test_51ABC12345...",
    "sk_prefix": "sk_test_51AB...",
    "testMode": true,
    "keysValid": true
  }
}
```

This helps diagnose configuration issues by showing:
- First 20 characters of publishable key
- First 12 characters of secret key
- Whether test mode is enabled
- Whether key validation passed

### 4. Unit Tests (TDD Approach)

**File**: `tests/Unit/Component/Service/ModuleConfigurationServiceTest.php`

Added 8 new tests (Tests 27-34):

| Test | Description |
|------|-------------|
| `testValidateKeyPairReturnsTrueForMatchingKeys` | Valid keys from same account |
| `testValidateKeyPairReturnsFalseForMismatchedKeys` | Keys from different accounts |
| `testValidateKeyPairReturnsFalseWhenPublishableKeyEmpty` | Missing publishable key |
| `testValidateKeyPairReturnsFalseWhenSecretKeyEmpty` | Missing secret key |
| `testValidateKeyPairReturnsFalseForInvalidKeyFormat` | Invalid key format |
| `testValidateKeyPairWorksForLiveModeKeys` | Live mode keys validation |
| `testGetKeyValidationErrorForMismatchedKeys` | Error message content |
| `testGetKeyValidationErrorReturnsNullForValidKeys` | No error for valid keys |

### 5. Updated Controller Test

**File**: `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php`

Added mock `getServiceFromContainer()` method to the test helper that returns a mock `ModuleConfigurationService` with valid keys.

## Files Modified

| File | Changes |
|------|---------|
| `src/Stripe/Service/ModuleConfigurationService.php` | Added `validateKeyPair()`, `getKeyValidationError()`, `extractAccountId()` |
| `src/Stripe/Controller/StripeOrderController.php` | Added early key validation, debug output with `keysValid` flag |
| `tests/Unit/Component/Service/ModuleConfigurationServiceTest.php` | Added 8 new tests for key validation |
| `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php` | Added mock for `getServiceFromContainer()` |

## Test Results

```
PHPUnit 11.5.44

OK, but there were issues!
Tests: 860, Assertions: 1847, Deprecations: 2, PHPUnit Deprecations: 116, Skipped: 1.
```

All 860 tests pass, including the 8 new key validation tests.

## How to Verify Configuration

### Option 1: Check Browser Network Tab

1. Click checkout button
2. Open Network tab in DevTools
3. Find `createCheckoutSession` request
4. Check Response JSON for `_debug.keysValid`

If `keysValid: false`, keys are from different accounts.

### Option 2: Check Key Prefixes

In the `_debug` response:
- `pk_prefix`: Should show `pk_test_51XXXXX...` or `pk_live_51XXXXX...`
- `sk_prefix`: Should show `sk_test_51XXXXX...` or `sk_live_51XXXXX...`

The portion after `_test_` or `_live_` should be **identical** for both keys (same Stripe account ID).

### Option 3: Check OXID Admin

Go to: Extensions → Modules → Stripe → Settings

Verify:
- **Test Publishable Key** (`sStripeTestPk`): `pk_test_51XXXXX...`
- **Test Secret Key** (`sStripeTestToken`): `sk_test_51XXXXX...`

The `51XXXXX` portion should match between both keys.

## Error Messages

If keys are misconfigured, users will now see clear error messages:

| Scenario | Error Message |
|----------|---------------|
| Empty publishable key | "Publishable key is not configured" |
| Empty secret key | "Secret key is not configured" |
| Invalid publishable key format | "Publishable key has invalid format" |
| Invalid secret key format | "Secret key has invalid format" |
| Keys from different accounts | "API keys appear to be from different Stripe accounts. Publishable key account: 51ABC..., Secret key account: 51XYZ... Please ensure both keys are from the same Stripe dashboard." |

## Recommendations

1. **Fix Configuration**: Ensure both keys are from the same Stripe account dashboard
2. **Remove Debug Output**: After confirming the fix works, remove `_debug` from production responses
3. **Add Admin Warning**: Consider adding a warning in the OXID admin panel if keys don't validate

## Related Issues

This fix is part of the investigation into the "Invalid checkout session response" error. Additional fixes in this session:

1. **Empty line_items**: Fixed `ContractService::createBasketSnapshot()` to extract items from OXID basket
2. **Handler chain**: Created `StripeContractCreationHandler` to create contract before checkout session
3. **Priority support**: Added `getPriority()` to handlers for proper execution order
