# Stripe Order Page Debugging Guide

## Quick Diagnostic Checklist

### 1. Check Browser Console

Open browser DevTools (F12) and check Console tab for:

**Expected messages:**
```
Stripe Order controller connected { hasPublishableKey: true, hasClientSecret: true }
Waiting for Stripe.js to load...
Payment Element ready
Stripe Payment Element initialized successfully
```

**Common error messages:**
- `"Stripe publishable key not configured"` → Check module configuration
- `"Stripe client secret not yet available"` → Check PHP logs for PaymentIntent creation errors
- `"Failed to initialize Stripe"` → Check if Stripe.js is loaded

### 2. Check Network Tab

In browser DevTools Network tab, verify:
- ✅ `https://js.stripe.com/v3/` loads successfully (200 OK)
- ✅ `/assets/js/stripe-frontend.js` loads successfully
- ✅ No CORS errors

### 3. Check PHP Logs

Check OXID logs at: `/source/extensions/stripe/log/oxideshop.log`

**Look for:**
```
OrderController render() - Payment check
Stripe payment detected, checking user
PaymentIntent created for order page
```

**Common errors:**
- API key errors → Check `.env` or module configuration
- User not found → Session/login issue
- Amount validation errors → Basket state issue

### 4. Verify Stripe Configuration

**In OXID Admin:**
- Extensions → Modules → Stripe
- Check "Publishable Key" is set
- Check "Secret Key" is set
- Check "Stripe Checkout" is enabled

### 5. Verify Payment Method

**In Database:**
```sql
SELECT OXID, OXACTIVE, OXDESC FROM oxpayments WHERE OXID = 'osc_stripe_card';
```
Should return active payment method.

### 6. Check Element in DOM

**In browser DevTools Elements tab:**

The payment container should have:
```html
<div data-controller="stripe-order"
     data-stripe-order-publishable-key-value="pk_test_..."
     data-stripe-order-client-secret-value="pi_...secret...">
```

If `client-secret-value` is empty, the PHP controller didn't create a PaymentIntent.

## Common Issues and Solutions

### Issue: Loading spinner never hides
**Cause:** JavaScript not running or Stripe.js not loaded
**Solution:**
- Check browser console for errors
- Verify Stripe.js CDN is accessible
- Check if JavaScript is compiled: `npm run build:dev`

### Issue: "Stripe client secret not yet available"
**Cause:** PHP OrderController didn't create PaymentIntent
**Solution:**
- Check PHP error logs
- Verify Stripe API keys in configuration
- Verify user is logged in
- Check payment method is 'osc_stripe_card'

### Issue: "Stripe configuration error"
**Cause:** Publishable key not configured
**Solution:**
- Go to OXID Admin → Extensions → Modules → Stripe
- Set "Publishable Key" (starts with `pk_test_` or `pk_live_`)

### Issue: Form shows but can't submit
**Cause:** Missing submit handler (different issue)
**Solution:** Check order form submit handler is wired up

## Testing Commands

### Clear OXID cache
```bash
cd /home/gaad/PhpStormProjects/OXID/Stripe/stripe-wallet/source
php vendor/bin/oe-console oe:cache:clear
```

### Rebuild JavaScript
```bash
cd /home/gaad/PhpStormProjects/OXID/Stripe/stripe-wallet/source/extensions/stripe
npm run build:dev
```

### Check compiled JavaScript
```bash
grep -n "debugger" assets/js/stripe-frontend.js
# Should return nothing (no debugger statements)
```

## Debug Mode

To enable more detailed logging, add to template temporarily:

```twig
{# Debug Information #}
<div class="alert alert-info">
    <h5>Debug Info:</h5>
    <ul>
        <li>Payment ID: {{ payment.getId() }}</li>
        <li>Has Client Secret: {{ oViewData.stripeClientSecret ? 'YES' : 'NO' }}</li>
        <li>Client Secret Length: {{ oViewData.stripeClientSecret|length }}</li>
        <li>Has Error: {{ oViewData.stripeError ? 'YES' : 'NO' }}</li>
    </ul>
</div>
```

Add this after line 58 in `order.html.twig`.
