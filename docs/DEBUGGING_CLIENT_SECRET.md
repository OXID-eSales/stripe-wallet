# Debugging "Stripe client secret not yet available"

## Understanding the Issue

The message "Stripe client secret not yet available" appears when the JavaScript controller loads but the backend hasn't provided a Stripe PaymentIntent client secret.

### Important: Security is Fine ✅

- **Client Secret** (safe for frontend) ≠ **Secret Key** (backend only)
- Client secrets are designed to be exposed in JavaScript
- They're single-use tokens that can only complete one specific payment
- Your implementation is **secure** - this is a data flow issue, not a security problem

## How It Works

```
Backend (PHP)                    →    Template (Twig)                →    Frontend (JS)
─────────────────────────────────────────────────────────────────────────────────────
OrderController::render()             order.html.twig                    stripe_order_controller.js
  ↓                                     ↓                                   ↓
Creates PaymentIntent          data-stripe-order-client-secret-value   this.clientSecretValue
  ↓                                     ↓                                   ↓
$paymentIntent['client_secret']  {{ stripeClientSecret }}              Used to mount Payment Element
```

## Debugging Steps

### 1. Check Browser Console (Enhanced Debugging Added)

The JavaScript now provides detailed debugging information:

```javascript
// You should see:
Stripe Order controller connected {
  hasPublishableKey: true,
  hasClientSecret: false,  // ⚠️ This is your problem
  publishableKey: "pk_test_...",
  clientSecretLength: 0
}

Debug info: "PaymentID: osc_stripe_card, HasSecret: no"

⚠️ Stripe client secret not available {
  message: "The backend did not generate a PaymentIntent client secret.",
  possibleReasons: [
    "1. Payment method not detected as Stripe (check payment ID = osc_stripe_card)",
    "2. User not logged in or session issue",
    "3. Backend error creating PaymentIntent (check PHP logs)",
    "4. StripePaymentService not properly configured"
  ]
}
```

### 2. Check Browser DevTools → Elements Tab

Inspect the `#stripe-payment-container` div:

```html
<div id="stripe-payment-container"
     data-controller="stripe-order"
     data-stripe-order-publishable-key-value="pk_test_..."
     data-stripe-order-client-secret-value=""
     data-debug-info="PaymentID: osc_stripe_card, HasSecret: no">
```

**What to check:**
- Is `data-stripe-order-client-secret-value` empty? → Backend issue
- Is `data-debug-info` showing "HasSecret: no"? → PaymentIntent not created
- Is the `data-controller` attribute present? → Controller should connect

### 3. Check PHP Logs (Enhanced Logging Added)

The OrderController now logs detailed debugging information.

**Enable OXID logging:**

```php
// In config.inc.php or similar
$this->iDebug = 6; // Enable debug logging
```

**Look for these log entries:**

```
[DEBUG] OrderController render() - Payment check
  payment_id: "osc_stripe_card"  // ✅ Should be this
  is_stripe: true                // ✅ Should be true
  basket_exists: true            // ✅ Should be true

[DEBUG] Stripe payment detected, checking user
  user_exists: true              // ✅ Should be true
  user_id: "abc123..."           // ✅ Should have an ID

[INFO] PaymentIntent prepared for order page
  payment_intent_id: "pi_..."
  amount: 1000

[ERROR] Failed to create/retrieve Stripe PaymentIntent on order page
  error: "..."                   // ⚠️ This tells you what went wrong
  trace: "..."
```

### 4. Common Issues and Solutions

#### Issue #1: Payment Method Not Detected

**Symptom:**
```
[DEBUG] Non-Stripe payment method selected
  payment_id: "oxidcashondel"  // Wrong payment ID
```

**Solution:**
- Ensure you selected "Credit/Debit Card (Stripe)" during checkout
- Verify payment method ID in database: `SELECT OXID FROM oxpayments WHERE OXID = 'osc_stripe_card'`
- Check if payment method is active: `OXACTIVE = 1`

#### Issue #2: User Not Logged In

**Symptom:**
```
[WARNING] Stripe payment selected but user not available
  has_user: false
  has_user_id: false
```

**Solution:**
- Guest checkout may not be configured for Stripe payments
- Enable user login before checkout
- Check basket user: `$basket->getBasketUser()`

#### Issue #3: Stripe API Error

**Symptom:**
```
[ERROR] Failed to create/retrieve Stripe PaymentIntent on order page
  error: "No API key provided"
```

**Solution:**
- Check Stripe API keys are configured in module settings
- Verify keys are correct (test vs. live mode)
- Check `StripePaymentService` configuration

#### Issue #4: Missing StripePaymentService

**Symptom:**
```
[ERROR] Call to a member function createPaymentIntent() on null
```

**Solution:**
- Check dependency injection is working
- Verify `services.yaml` is correctly configured
- Clear OXID cache: `php vendor/bin/oe-console oe:cache:clear`

### 5. Testing the Fix

After addressing the issue:

1. **Clear OXID cache:**
   ```bash
   php vendor/bin/oe-console oe:cache:clear
   ```

2. **Clear browser cache:**
   - Press `Ctrl+Shift+R` (hard refresh)
   - Or clear cache in DevTools → Network → "Disable cache"

3. **Reload checkout page**

4. **Check console for successful connection:**
   ```javascript
   Stripe Order controller connected {
     hasClientSecret: true,  // ✅ Fixed!
     clientSecretLength: 89   // ✅ Should be ~80-90 chars
   }

   Payment Element ready
   ```

5. **Verify Payment Element appears:**
   - Card input fields should be visible
   - Stripe branding should appear

## Files Modified for Debugging

### Backend (PHP)
- `src/Stripe/Controller/OrderController.php`
  - Added logging before/after payment method check
  - Added user validation logging
  - Added error output to template

### Frontend (Twig)
- `views/twig/extensions/themes/default/page/checkout/order.html.twig`
  - Added error display div
  - Added debug-info attribute
  - Shows Stripe errors visually

### Frontend (JavaScript)
- `resources/build/js/controllers/stripe_order_controller.js`
  - Enhanced console logging
  - Added possible reasons for failure
  - Better error messages for users

## Next Steps

1. **Test in development mode:**
   ```bash
   npm run build:dev  # Already done
   ```

2. **Load checkout page and check console:**
   - Open DevTools → Console
   - Look for enhanced debug messages
   - Check if client secret is now available

3. **Check PHP logs:**
   - Look for the new debug/warning/error messages
   - Identify which check is failing

4. **Fix the underlying issue:**
   - Use the "Common Issues" section above
   - Address the specific error from logs

5. **Once working, rebuild for production:**
   ```bash
   npm run build:prod
   ```

## Still Having Issues?

If you're still seeing "Stripe client secret not yet available":

1. **Share the console output** - The enhanced debugging will show exactly what's failing
2. **Share the PHP logs** - The new logging will pinpoint the issue
3. **Check the error div** - Any backend errors will now display on the page

The debugging enhancements will make it clear exactly where the flow is breaking!
