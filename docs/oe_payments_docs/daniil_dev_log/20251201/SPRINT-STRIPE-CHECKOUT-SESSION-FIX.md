# Sprint: Fix Stripe Checkout Session "Could Not Be Found" Error

## Date: 2025-12-01

## Problem Statement

When clicking the checkout button, the Stripe Checkout Session is created successfully (returns `cs_test_...` ID), but when redirecting to Stripe's hosted checkout page, Stripe displays:

```
Something went wrong
The specified Checkout Session could not be found. This error is usually caused by using the wrong API key or visiting an expired Checkout Session.
```

Console errors:
```
POST https://api.stripe.com/v1/payment_pages/cs_test_a1EFDtZvjfNCNNXp7UoewICJIyta3PgCXsb8BisSevO2LENbAxXEOKuleh/init 404 (Not Found)
```

## Root Cause Analysis

### Hypothesis 1: API Key Mismatch (Most Likely)

The Stripe Checkout Session is created on the backend using the **secret key** (`sk_test_xxx`), but the frontend JavaScript initializes Stripe.js with the **publishable key** (`pk_test_yyy`).

If these keys are from **different Stripe accounts**, the session created with one account's secret key cannot be accessed with another account's publishable key.

### Key Flow Analysis

1. **Backend (PHP)** - Creates Checkout Session:
   ```
   StripeOrderController::createCheckoutSession()
   → StripeCheckoutSessionHandler::handle()
   → StripeAdapterFactory::getStripeClient()
   → StripeClientFactory::create()
   → ModuleConfigurationService::getToken() // Returns sk_test_XXX
   → Stripe API creates session with sk_test_XXX
   ```

2. **Frontend (JS)** - Redirects to Stripe:
   ```
   order_submit_controller.js::handleStripeCheckout()
   → Stripe(this.publishableKeyValue) // Uses pk_test_YYY from data attribute
   → stripe.redirectToCheckout({ sessionId: data.id })
   ```

3. **Template** - Provides publishable key:
   ```twig
   data-order-submit-publishable-key-value="{{ oViewConf.getStripePublishableKey() | raw }}"
   ```

4. **ViewConfig** - Gets publishable key:
   ```php
   ViewConfig::getStripePublishableKey()
   → ModuleConfigurationService::getPublishableKey() // Returns pk_test_YYY
   ```

### Configuration Source

Both keys come from `ModuleConfigurationService`:
- `getToken()` → `sStripeTestToken` (secret key for backend)
- `getPublishableKey()` → `sStripeTestPk` (publishable key for frontend)

**Problem**: If these settings contain keys from different Stripe accounts (different account IDs), the session cannot be found.

### Hypothesis 2: Stripe API Version Incompatibility

The `StripeClientFactory` uses API version `2024-11-20.acacia` which is very recent. Some Checkout Session behaviors may have changed.

### Hypothesis 3: Session ID Format Issue

The session ID `cs_test_a1EFDtZvjfNCNNXp7UoewICJIyta3PgCXsb8BisSevO2LENbAxXEOKuleh` appears valid but could be:
- Truncated during JSON serialization
- Modified during URL encoding
- Cached/stale from a previous request

## Investigation Steps

### Step 1: Verify API Key Consistency

Check if publishable key and secret key are from the same Stripe account.

**Test**: Add debug output to verify key prefixes match:

```php
// In StripeOrderController::createCheckoutSession()
$config = $this->getServiceFromContainer(ModuleConfigurationService::class);
$publishableKey = $config->getPublishableKey();
$secretKey = $config->getToken();

// Log or return for debugging
echo json_encode([
    'id' => $context->get('checkoutSessionId'),
    '_debug' => [
        'pk_prefix' => substr($publishableKey, 0, 20),
        'sk_prefix' => substr($secretKey, 0, 12) . '...',
        'testMode' => $config->isTestMode(),
    ],
]);
```

**Expected**: Both keys should have matching account IDs:
- `pk_test_51ABC...` and `sk_test_51ABC...` (same `51ABC` account ID)

### Step 2: Verify Session Creation Response

Check the actual response from Stripe API when creating the session.

**Test**: Log the full Stripe response:

```php
// In StripeCheckoutSessionHandler::handle()
$checkoutSession = $stripeClient->checkout->sessions->create([...]);

Registry::getLogger()->info('Stripe session created', [
    'id' => $checkoutSession->id,
    'url' => $checkoutSession->url,
    'status' => $checkoutSession->status,
    'livemode' => $checkoutSession->livemode,
]);
```

### Step 3: Verify Frontend Receives Correct Session ID

Check Network tab in browser DevTools for the `createCheckoutSession` response.

**Expected**:
```json
{
  "id": "cs_test_a1...",
  "contract_id": "...",
  "_debug": {
    "pk_prefix": "pk_test_51ABC...",
    "sk_prefix": "sk_test_51AB...",
    "testMode": true
  }
}
```

## Sprint Tasks (TDD-First)

### Task 1: Create API Key Validation Test

**File**: `tests/Unit/Stripe/Service/ModuleConfigurationServiceTest.php`

```php
/**
 * @test
 */
public function api_keys_should_be_from_same_stripe_account(): void
{
    // Given: A configured ModuleConfigurationService
    $config = $this->createConfiguredService([
        'sStripeMode' => 'test',
        'sStripeTestPk' => 'pk_test_51ABC123',
        'sStripeTestToken' => 'sk_test_51ABC123',
    ]);

    // When: Getting both keys
    $publishableKey = $config->getPublishableKey();
    $secretKey = $config->getToken();

    // Then: Account IDs should match
    $pkAccountId = $this->extractAccountId($publishableKey);
    $skAccountId = $this->extractAccountId($secretKey);

    $this->assertEquals($pkAccountId, $skAccountId);
}

/**
 * @test
 */
public function mismatched_keys_should_be_detectable(): void
{
    // Given: Keys from different accounts
    $config = $this->createConfiguredService([
        'sStripeMode' => 'test',
        'sStripeTestPk' => 'pk_test_51ABC123',
        'sStripeTestToken' => 'sk_test_51XYZ789', // Different account!
    ]);

    // When: Validating configuration
    $isValid = $config->validateKeyPair();

    // Then: Should return false
    $this->assertFalse($isValid);
}
```

### Task 2: Implement Key Pair Validation Method

**File**: `src/Stripe/Service/ModuleConfigurationService.php`

```php
/**
 * Validate that publishable key and secret key are from the same Stripe account.
 *
 * Stripe keys follow the format: {type}_{mode}_{accountId}{randomChars}
 * e.g., pk_test_51ABC123XYZ or sk_live_51ABC123XYZ
 *
 * @return bool True if keys are from the same account
 */
public function validateKeyPair(): bool
{
    $publishableKey = $this->getPublishableKey();
    $secretKey = $this->getToken();

    if (empty($publishableKey) || empty($secretKey)) {
        return false;
    }

    $pkAccountId = $this->extractAccountId($publishableKey);
    $skAccountId = $this->extractAccountId($secretKey);

    return $pkAccountId !== null
        && $skAccountId !== null
        && $pkAccountId === $skAccountId;
}

/**
 * Extract account ID from Stripe key.
 *
 * @param string $key Stripe API key
 * @return string|null Account ID or null if invalid format
 */
private function extractAccountId(string $key): ?string
{
    // Stripe keys: pk_test_51ABC... or sk_live_51ABC...
    // Account ID starts after the mode prefix
    if (preg_match('/^[ps]k_(test|live)_([a-zA-Z0-9]+)/', $key, $matches)) {
        // Return first 10 chars of account portion for comparison
        return substr($matches[2], 0, 10);
    }
    return null;
}
```

### Task 3: Create Integration Test for Checkout Session Flow

**File**: `tests/Integration/Stripe/Controller/StripeOrderControllerCheckoutTest.php`

```php
/**
 * @test
 */
public function checkout_session_should_be_accessible_with_configured_publishable_key(): void
{
    // Given: Valid matching API keys
    $this->configureStripeKeys(
        publishableKey: 'pk_test_51...',
        secretKey: 'sk_test_51...'
    );

    // And: A basket with items
    $this->createBasketWithProducts();

    // When: Creating checkout session
    $response = $this->callCreateCheckoutSession();

    // Then: Session ID should be returned
    $this->assertArrayHasKey('id', $response);
    $this->assertStringStartsWith('cs_test_', $response['id']);

    // And: Session should be retrievable with same account's key
    $stripe = new \Stripe\StripeClient($this->getSecretKey());
    $session = $stripe->checkout->sessions->retrieve($response['id']);
    $this->assertEquals($response['id'], $session->id);
}
```

### Task 4: Add Configuration Health Check Endpoint

**File**: `src/Stripe/Controller/Admin/ConfigHealthController.php`

Create admin endpoint to verify configuration:

```php
public function checkHealth(): void
{
    header('Content-Type: application/json');

    $config = $this->getServiceFromContainer(ModuleConfigurationService::class);

    $health = [
        'testMode' => $config->isTestMode(),
        'hasPublishableKey' => !empty($config->getPublishableKey()),
        'hasSecretKey' => !empty($config->getToken()),
        'keysMatch' => $config->validateKeyPair(),
        'publishableKeyPrefix' => substr($config->getPublishableKey(), 0, 15) . '...',
        'secretKeyPrefix' => substr($config->getToken(), 0, 12) . '...',
    ];

    echo json_encode($health);
    exit;
}
```

### Task 5: Add Warning in Admin Panel for Mismatched Keys

Display warning in module settings if keys appear to be from different accounts.

## Implementation Order

1. **Write failing tests** for key validation
2. **Implement** `validateKeyPair()` method
3. **Run tests** - should pass
4. **Add debug logging** to checkout session creation
5. **Test with browser** - check Network tab for debug info
6. **Verify** publishable key and secret key account IDs match
7. **Fix configuration** if keys are mismatched
8. **Remove debug code** after fix is confirmed

## Quick Fix (If Keys Are Mismatched)

If investigation reveals keys are from different Stripe accounts:

1. Go to OXID Admin → Extensions → Modules → Stripe
2. Check "Test Publishable Key" (`sStripeTestPk`)
3. Check "Test Secret Key" (`sStripeTestToken`)
4. Ensure both are from the **same Stripe dashboard** (same account)

Keys should look like:
- `pk_test_51ABC...` (Publishable)
- `sk_test_51ABC...` (Secret)

The `51ABC` part should be **identical** for both keys.

## Files to Modify

| File | Change |
|------|--------|
| `src/Stripe/Service/ModuleConfigurationService.php` | Add `validateKeyPair()` method |
| `src/Stripe/Controller/StripeOrderController.php` | Add debug logging (temporary) |
| `tests/Unit/Stripe/Service/ModuleConfigurationServiceTest.php` | Add key validation tests |
| `tests/Integration/Stripe/Controller/StripeOrderControllerCheckoutTest.php` | Add checkout flow test |

## Success Criteria

1. [ ] Tests for key validation pass
2. [ ] Debug output shows matching key prefixes
3. [ ] Checkout session redirects successfully to Stripe
4. [ ] Payment can be completed on Stripe hosted page
5. [ ] Return URL works correctly

## Rollback Plan

If issues persist after fix:
1. Revert to original code
2. Use alternative checkout flow (Payment Elements instead of Checkout Sessions)
3. Create PaymentIntent directly instead of Checkout Session
