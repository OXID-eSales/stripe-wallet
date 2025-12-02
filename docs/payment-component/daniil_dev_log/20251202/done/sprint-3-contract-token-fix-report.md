# Sprint 3: Contract Token Fix Report

**Date:** December 2, 2025
**Status:** COMPLETED
**Duration:** ~2 hours

---

## Issue Summary

After successfully completing payment on Stripe Checkout hosted page, users were redirected back to the shop but saw the error **"Contract token is missing"** and were sent back to the payment selection page instead of the thank you page.

---

## Root Cause Analysis

### The Problem

The `StripeOrderController::checkoutSuccess()` method was **not reading URL parameters** (`contract_id` and `contract_token`) from the return URL. It only checked session variables, but after redirecting to Stripe and back, these URL parameters were the primary source of data.

### Flow Before Fix
```
1. User clicks "Pay with Stripe"
2. StripeCheckoutSessionHandler creates session with success_url containing:
   - contract_id=xxx
   - contract_token=yyy
3. User completes payment on Stripe
4. Stripe redirects to success_url with parameters
5. checkoutSuccess() called BUT:
   - ❌ Did NOT read contract_id from URL
   - ❌ Did NOT read contract_token from URL
   - Only checked session variables (which were empty)
6. Handler received null values → "Contract token is missing" error
```

### Flow After Fix
```
1-4. Same as above
5. checkoutSuccess() now:
   - ✅ Reads contract_id from Registry::getRequest()->getRequestParameter()
   - ✅ Reads contract_token from Registry::getRequest()->getRequestParameter()
   - Passes both to EventContext
6. Handler receives valid token → Payment authorized → Order created
```

---

## Files Modified

### 1. StripeOrderController.php
**Location:** `src/Stripe/Controller/StripeOrderController.php`

**Change:** Added URL parameter reading in `checkoutSuccess()` method

```php
// BEFORE (missing):
$context = new EventContext([
    'checkoutSessionId' => $sessionId,
    'contractId' => $this->getContractIdFromSession(), // Only session!
]);

// AFTER (fixed):
$contractId = Registry::getRequest()->getRequestParameter('contract_id');
$contractToken = Registry::getRequest()->getRequestParameter('contract_token');

$context = new EventContext([
    'checkoutSessionId' => $sessionId,
    'contract_id' => $contractId,        // From URL
    'contract_token' => $contractToken,  // From URL
    'contractId' => $this->getContractIdFromSession(), // Fallback
]);
```

### 2. StripeCheckoutReturnHandler.php
**Location:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

**Change:** Fixed debug logging that used non-existent `toArray()` method (removed after debugging)

### 3. StripeCheckoutSessionHandler.php
**Location:** `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php`

**Change:** Debug logging added then removed (no permanent changes)

---

## Additional Issue Fixed: Twig Rendering

### Symptom
Shop displayed raw template path `page/shop/start.html.twig` instead of rendered HTML.

### Cause
Corrupted autoloader/container state after PHP container restart.

### Solution
```bash
docker compose exec -T php bash -c "cd /var/www && composer install"
```

This rebuilt the autoloader and Twig component, restoring proper template rendering.

---

## Verification

### Test Performed
1. Added product to cart
2. Proceeded to checkout
3. Selected "Digitale Geldbörse (Stripe)" payment
4. Completed payment on Stripe Checkout page
5. Redirected back to shop

### Results
- ✅ Thank you page displayed
- ✅ Order #38 created in admin
- ✅ Order details correct:
  - Customer: Marc Muster
  - Products: Ocean Eyes (106,50 EUR) + Moonlight (85,99 EUR)
  - Total: 202,49 EUR
  - Payment: Digitale Geldbörse (Stripe)
  - Internal Status: OK

### Log Evidence
```
[2025-12-02 13:11:12] StripeCheckoutSessionHandler: contract_token "generated (130 chars)"
[2025-12-02 13:11:41] checkoutSuccess: contract_token_from_url "present (130 chars)"
[2025-12-02 13:11:41] StripeCheckoutReturnHandler: contract_token "present (130 chars)"
```

---

## Debug Process

### Step 1: Added Debug Logging
Added logging to trace token flow through:
- `StripeCheckoutSessionHandler` - Token generation
- `StripeOrderController::checkoutSuccess()` - URL parameter receipt
- `StripeCheckoutReturnHandler::validateContractToken()` - Handler processing

### Step 2: Identified Missing URL Reading
Logs showed:
- Token WAS in URL (Stripe preserved it correctly)
- Controller was NOT reading it from URL
- Handler received null values

### Step 3: Fixed URL Parameter Reading
Added `Registry::getRequest()->getRequestParameter()` calls.

### Step 4: Fixed Debug Code Error
Debug code used `EventContext::toArray()` which doesn't exist. Removed the call.

### Step 5: Cleared Cache & Tested
```bash
docker compose exec -T php bash -c "cd /var/www && composer install"
docker compose exec -T php bash -c "cd /var/www && php bin/oe-console oe:cache:clear"
```

### Step 6: Removed Debug Logging
Cleaned up all temporary debug statements from production code.

---

## Lessons Learned

1. **Always check URL parameters** - After external redirects (Stripe, PayPal, etc.), URL parameters are often the only reliable data source since PHP sessions may not persist.

2. **Test the full flow** - Unit tests passed but integration testing revealed the URL parameter issue.

3. **Debug logging is valuable** - Temporarily adding detailed logging helped pinpoint exactly where the data was lost.

4. **Container state matters** - After PHP restarts, always run `composer install` to ensure autoloader and DI container are properly rebuilt.

---

## Related Documentation

- `debug_contract_token_missing.md` - Initial investigation document
- `sprint3_report.md` - Overall Sprint 3 summary

---

## Next Steps

1. Consider adding integration test for full checkout return flow
2. Monitor production logs for any remaining token issues
3. Review other payment return handlers for similar URL parameter handling

---

**Report Created:** 2025-12-02
**Author:** Claude Code Assistant
