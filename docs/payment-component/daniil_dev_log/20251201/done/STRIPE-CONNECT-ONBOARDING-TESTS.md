# Stripe Connect Onboarding Tests

**Date:** 2025-12-01
**Status:** Completed
**Tests Created:** 9 tests, all passing

## Overview

Created comprehensive unit tests for the `StripeConnect` admin controller that handles the Stripe Connect OAuth onboarding flow.

## Test File

`tests/Unit/Stripe/Controller/Admin/StripeConnectTest.php`

## Tests Created

### Test Mode Onboarding Tests

1. **testFinishOnBoardingSavesTestModeCredentials**
   - Verifies that both `sStripeTestToken` (access token) and `sStripeTestPk` (publishable key) are saved when onboarding in test mode

2. **testFinishOnBoardingSavesTestModeWithEmptyPublishableKey**
   - Verifies that onboarding succeeds even when publishable key is empty (saves empty string)

### Live Mode Onboarding Tests

3. **testFinishOnBoardingSavesLiveModeCredentials**
   - Verifies that both `sStripeLiveToken` and `sStripeLivePk` are saved when onboarding in live mode

### Validation Tests

4. **testFinishOnBoardingFailsWithEmptyAccessToken**
   - Verifies onboarding fails and nothing is saved when access token is empty

5. **testFinishOnBoardingFailsWithInvalidMode**
   - Verifies onboarding fails when mode is neither 'test' nor 'live'

6. **testFinishOnBoardingFailsWithMissingMode**
   - Verifies onboarding fails when mode parameter is missing

7. **testFinishOnBoardingReturnsFalseOnSessionChallengeFail**
   - Verifies CSRF protection - returns false when session challenge fails

### Key Format Consistency Tests

8. **testOnBoardingSavesMatchingKeyPair**
   - Documents expected behavior: keys from same Stripe account should be saved together
   - Extracts and compares account IDs from saved keys

9. **testOnBoardingAllowsMismatchedKeysCurrentBehavior**
   - **Documents a potential bug**: Current implementation does NOT validate that keys belong to the same Stripe account
   - This can cause "Checkout Session could not be found" errors at runtime

## Issues Discovered

### Issue 1: Mismatched API Keys Allowed

The current `StripeConnect::stripeFinishOnBoarding()` method does not validate that the access token and publishable key belong to the same Stripe account.

**Current behavior:**
```php
// Keys from different accounts are saved without validation
$this->moduleSettingService->save('sStripeTestToken', $sAccessToken, Module::MODULE_ID);
$this->moduleSettingService->save('sStripeTestPk', $sPublishableKey, Module::MODULE_ID);
```

**Observed in admin HTML:**
- `sStripeTestToken`: `sk_test_51OyDwdAeM...` (Account: 51OyDwdAeM)
- `sStripeTestPk`: `pk_test_51NXKT4ESz...` (Account: 51NXKT4ESz)

These are from **different Stripe accounts**, which causes:
- Checkout sessions created with secret key from account A
- Frontend tries to redirect using publishable key from account B
- Stripe returns "Checkout Session could not be found"

### Issue 2: Template Path Error (Fixed Separately)

The template path was incorrectly set to a string literal instead of proper OXID 7 Twig syntax. Fixed in separate commit.

## Recommended Fix

Add key pair validation to `stripeFinishOnBoarding()`:

```php
// Validate keys belong to same account
if (!$this->validateKeyPair($sAccessToken, $sPublishableKey)) {
    $blSuccess = false;
    $this->addViewData(['keyMismatchError' => true]);
} else {
    // Save keys...
}
```

Or use the existing `ModuleConfigurationService::validateKeyPair()` method after saving to warn the user.

## Test Execution

```bash
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --testsuite Unit \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter StripeConnectTest
```

**Result:** 9 tests, 35 assertions, all passing

## Files Created/Modified

- **Created:** `tests/Unit/Stripe/Controller/Admin/StripeConnectTest.php`
- **Fixed:** `src/Stripe/Controller/Admin/StripeConnect.php` (template path - separate fix)
