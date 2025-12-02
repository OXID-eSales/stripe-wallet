# Debug: "Contract token is missing" Error

**Date:** December 2, 2025
**Status:** INVESTIGATING
**Severity:** Critical (blocks checkout completion)

---

## Error Description

After successfully completing payment on Stripe Checkout hosted page, the user is redirected back to the shop but sees the error "Contract token is missing" and is sent back to the payment selection page.

### Error Location
```
src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php:134
```

### Error Flow
```
1. User clicks "Pay with Stripe" → createCheckoutSession()
2. Stripe Checkout Session created with success_url containing contract_id & contract_token
3. User completes payment on Stripe
4. Stripe redirects to success_url
5. checkoutSuccess() called → creates EventContext
6. StripeCheckoutReturnHandler::validateContractToken() → ERROR: token missing
7. User redirected back to payment page
```

---

## Root Cause Analysis

### Expected Flow
The success URL should contain both parameters:
```
https://shop.example.com/index.php?cl=order&fnc=checkoutSuccess
  &session_id=cs_test_xxx
  &contract_id=abc123
  &contract_token=base64encodedtoken
```

### Possible Causes

#### 1. Token Not Generated During Session Creation
**File:** `StripeCheckoutSessionHandler.php:79`
```php
$contractToken = $this->tokenService->generateToken($contractId);
```
- `TokenServiceInterface` might not be properly injected
- `ContractTokenService` constructor might fail silently

#### 2. Token Not Added to Success URL
**File:** `StripeCheckoutSessionHandler.php:83-86`
```php
$successUrl = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess'
    . '&session_id={CHECKOUT_SESSION_ID}'
    . '&contract_id=' . urlencode($contractId)
    . '&contract_token=' . urlencode($contractToken);
```
- URL might be truncated
- `urlencode()` might cause issues with special characters

#### 3. Stripe Strips Custom Parameters
- Stripe might not preserve all URL parameters
- Only `{CHECKOUT_SESSION_ID}` is officially supported placeholder

#### 4. Controller Not Reading URL Parameters
**File:** `StripeOrderController.php:182-184` (FIXED)
```php
// Was missing - now added:
$contractId = Registry::getRequest()->getRequestParameter('contract_id');
$contractToken = Registry::getRequest()->getRequestParameter('contract_token');
```

#### 5. OXID SEO URL Rewriting
- SEO engine might strip unknown parameters
- `.htaccess` rules might interfere

#### 6. Session/Cookie Issues
- PHP session might be lost after redirect
- Cross-domain cookie issues with Stripe redirect

---

## Debug Logging Added

### 1. StripeCheckoutSessionHandler (Session Creation)
```php
Registry::getLogger()->error('StripeCheckoutSessionHandler DEBUG', [
    'contract_id' => $contractId,
    'contract_token' => $contractToken ? 'generated (' . strlen($contractToken) . ' chars)' : 'FAILED',
    'success_url' => $successUrl,
]);
```

### 2. StripeOrderController::checkoutSuccess (Return Entry Point)
```php
Registry::getLogger()->error('checkoutSuccess DEBUG', [
    'sessionId' => $sessionId,
    'contract_id_from_url' => $contractId,
    'contract_token_from_url' => $contractToken ? 'present' : 'MISSING',
    'contract_id_from_session' => $this->getContractIdFromSession(),
    'REQUEST' => array_keys($_REQUEST),
    'GET' => array_keys($_GET),
]);
```

### 3. StripeCheckoutReturnHandler::validateContractToken
```php
$this->logger->error('StripeCheckoutReturnHandler::validateContractToken DEBUG', [
    'contract_token' => $contractToken ? 'present' : 'MISSING/NULL',
    'contract_token_type' => gettype($contractToken),
    'contract_id' => $contractIdFromUrl,
    'all_context_keys' => array_keys($context->toArray()),
]);
```

---

## Diagnostic Steps

### Step 1: Check Log After Checkout Initiation
```bash
tail -50 /var/www/source/log/oxideshop.log | grep "StripeCheckoutSessionHandler"
```
**Expected:** Should show `contract_token: generated (XX chars)` and full `success_url`

### Step 2: Check Stripe Dashboard
1. Go to Stripe Dashboard → Developers → Events
2. Find the `checkout.session.completed` event
3. Check the `success_url` in the session object

### Step 3: Check Browser Network Tab
1. Open DevTools → Network
2. Complete payment on Stripe
3. Watch the redirect URL - does it contain `contract_token`?

### Step 4: Check Log After Return
```bash
tail -50 /var/www/source/log/oxideshop.log | grep "checkoutSuccess DEBUG"
```
**Expected:** Should show what parameters were received

---

## Possible Solutions

### Solution 1: Store Token in Stripe Metadata (Recommended)
Instead of passing token in URL, store it in Stripe session metadata:

```php
// In StripeCheckoutSessionHandler
$checkoutSession = $stripeClient->checkout->sessions->create([
    // ...
    'metadata' => [
        'contract_id' => $contractId,
        'contract_token' => $contractToken,  // Add this
    ],
]);
```

Then retrieve it in the return handler from the Stripe session.

### Solution 2: Store Token in PHP Session
```php
// In StripeCheckoutSessionHandler
Registry::getSession()->setVariable('stripe_contract_token', $contractToken);

// In StripeOrderController::checkoutSuccess
$contractToken = Registry::getSession()->getVariable('stripe_contract_token');
```

### Solution 3: Store Token in Contract
```php
// In StripeCheckoutSessionHandler
$contract->setMetadata('security_token', $contractToken);
$this->contractRepository->save($contract);

// In return handler - regenerate and compare
$expectedToken = $this->tokenService->generateToken($contractId);
```

### Solution 4: Use Stripe's client_reference_id
```php
$checkoutSession = $stripeClient->checkout->sessions->create([
    'client_reference_id' => $contractId . ':' . $contractToken,
    // ...
]);
```

---

## Files Modified for Debug

| File | Changes |
|------|---------|
| `src/Stripe/Controller/StripeOrderController.php` | Added URL parameter reading + debug logging |
| `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php` | Added debug logging |
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Added debug logging |

---

## Next Steps

1. **Run checkout flow** and capture debug logs
2. **Identify where token is lost** based on log output
3. **Implement appropriate solution** based on findings
4. **Remove debug logging** after fix verified

---

## Related Files

- `src/Stripe/Service/ContractTokenService.php` - Token generation
- `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php` - Creates checkout session
- `src/Stripe/Controller/StripeOrderController.php` - Entry point for return
- `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` - Processes return

---

**Last Updated:** 2025-12-02
