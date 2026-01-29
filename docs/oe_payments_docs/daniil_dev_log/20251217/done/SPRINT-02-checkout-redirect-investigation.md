# SPRINT-02: Checkout Return Redirect Issue Investigation

**Date:** 2025-12-17
**Type:** Investigation / Bug
**Status:** In Progress
**Priority:** High

## Executive Summary

After completing payment on Stripe Checkout, users are being redirected to the start page (`cl=start&redirected=1`) instead of the thank you page. This breaks the checkout flow.

## Error Details

**E2E Test Failure:**
```
Final URL: https://daniil.oxiddev.de/index.php?lang=0&force_sid=xxx&&cl=start&redirected=1
Expected: URL contains "thankyou"
```

**Observation:**
- Payment completes successfully on Stripe
- User returns with `force_sid` parameter (session ID preserved)
- But lands on start page instead of `cl=order&fnc=checkoutSuccess`

## Root Cause Analysis (In Progress)

### Hypothesis 1: Session Not Restored
When returning from Stripe, OXID should restore the session via `force_sid` parameter. If the session is invalid/expired, OXID creates a new session with an empty basket, then redirects to start page because the order page requires a basket.

### Hypothesis 2: URL Manipulation
The double `&&` in the final URL (`force_sid=xxx&&cl=start`) suggests the original URL parameters (`cl=order&fnc=checkoutSuccess&session_id=...&contract_id=...&contract_token=...`) were stripped out during a redirect.

### Hypothesis 3: Early Redirect by OXID Core
OXID's ShopControl or session handling might be intercepting the request before it reaches `StripeOrderController::checkoutSuccess()`.

## What We've Done

### 1. Added Event System Logging
Created detailed logging to capture the flow through event handlers:

**New Files:**
- `src/Stripe/Service/Factory/EventFileLoggerFactory.php` - Creates logger for `log/osc/stripe_events.log`

**Modified Files:**
- `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` - Added step-by-step logging
- `src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php` - Added state transition logging
- `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php` - Added basket/order creation logging

**Log Output Location:** `/var/www/source/log/osc/stripe_events.log`

### 2. Updated services.yaml
Added `stripe.events.file_logger` service and injected into handlers:
```yaml
stripe.events.file_logger:
  class: OxidSolutionCatalysts\Payments\Component\Service\FileLoggerInterface
  factory: ['@...EventFileLoggerFactory', 'create']
```

## Expected Log Output

When working correctly, the log should show:
```
EVENT StripeCheckoutReturnHandler::handle() START
EVENT Step 1: Extract parameters {"sessionId":"cs_xxx"}
EVENT Step 2: Validating return with service...
EVENT Step 2b: Validation result {"successful":true,"paymentStatus":"paid"}
EVENT Step 3: Loading contract...
EVENT Step 3b: Contract loaded {"state":"draft","userId":"xxx"}
EVENT Step 4: Security validation passed
EVENT Step 5: Restoring session state...
EVENT Step 6: Handle payment status {"isRequiresCapture":false}
EVENT Step 6b: Automatic capture - calling dispatchPaymentEvent
EVENT dispatchPaymentEvent: Dispatching PaymentAuthorizedEvent...
EVENT PaymentAuthorizedEventHandler::handle() START
EVENT PaymentAuthorizedEventHandler: Transitioning DRAFT -> PENDING
EVENT PaymentAuthorizedEventHandler: Fulfilling payment_authorized condition
EVENT PaymentAuthorizedEventHandler: Dispatching ContractReadyToCommitEvent
EVENT StripeOrderCreationHandler::handle() START
EVENT StripeOrderCreationHandler: Checking basket {"basketExists":true,"basketProductsCount":1}
EVENT StripeOrderCreationHandler: Order created successfully {"orderId":"xxx","orderNumber":123}
EVENT StripeCheckoutReturnHandler::handle() END {"redirectTarget":"thankyou","orderId":"xxx"}
```

## Next Steps

1. **Deploy changes to server** - The logging code needs to be deployed to capture actual flow
2. **Run E2E test** - Trigger the checkout flow and collect logs
3. **Analyze logs** - Determine at which step the flow fails
4. **Possible fixes:**
   - If session not restored → Check OXID session handling/cookies
   - If basket empty → Restore basket from contract snapshot
   - If handler never reached → Debug OXID routing/ShopControl

## Code Changes Summary

### Event Logging Pattern
```php
private function logEvent(string $message, array $context = []): void
{
    if ($this->eventLogger !== null) {
        $this->eventLogger->log($message, $context);
    }
}
```

### Success URL Building (verified correct)
```php
$url = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess'
    . '&session_id={CHECKOUT_SESSION_ID}'
    . '&contract_id=' . urlencode($contractId)
    . '&contract_token=' . urlencode($contractToken);

if ($sessionId !== '') {
    $url .= '&force_sid=' . urlencode($sessionId);
}
```

## Testing

- [x] Style checks pass: PHPStan, PHPCS, PHPMD
- [x] Relevant unit tests pass: CheckoutSession*, CheckoutReturn*
- [ ] E2E test with logging enabled
- [ ] Log analysis completed
- [ ] Root cause identified
- [ ] Fix implemented and verified

## Dependencies

- Code must be deployed to https://daniil.oxiddev.de for logging to work
- E2E test runs against this remote server

---

**Author:** Development Team
**Last Updated:** 2025-12-17
