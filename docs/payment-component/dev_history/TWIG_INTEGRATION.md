# Stripe Payment Element - Twig Integration Guide

**Date:** 2025-01-14
**OXID Version:** 7.0+ (Twig templates only)

---

## Overview

Since OXID 7.0+ uses Twig templates and the `blocks` section in `metadata.php` only works with Smarty, we use **JavaScript injection** to add the Stripe Payment Element to the payment page.

**How it works:**
1. PaymentController creates a PaymentIntent and injects JavaScript configuration
2. JavaScript file loads dynamically and injects Payment Element into DOM
3. When Stripe payment is selected, Payment Element appears
4. Form submission is intercepted and redirects to Stripe

---

## Implementation Architecture

### ✅ No Template Blocks Required

Unlike Smarty modules, Twig modules **cannot use the `blocks` section** in metadata.php. Instead:

- **PaymentController** injects JavaScript configuration via template parameters
- **JavaScript file** (`stripe_payment_element.js`) handles DOM manipulation
- **CSS file** (`stripe_payment_element.css`) provides styling

### File Structure

```
source/extensions/stripe/
├── src/Controller/
│   └── PaymentController.php          ← Injects JS config
├── out/src/
│   ├── js/
│   │   └── stripe_payment_element.js  ← Payment Element logic
│   └── css/
│       └── stripe_payment_element.css ← Styling
└── metadata.php                       ← NO blocks section
```

---

## Theme Integration

### Option 1: Automatic Injection (Recommended)

The PaymentController automatically adds these template parameters:

```php
$this->addTplParam('stripeConfigScript', '<script>...</script>');
$this->addTplParam('stripeCssUrl', 'https://..../stripe_payment_element.css');
```

**To use in your theme's payment template:**

#### 1. Add CSS to `<head>`

Edit your theme's `page/checkout/payment.html.twig` or layout template:

```twig
{% block head_css %}
    {{ parent() }}
    {% if stripeCssUrl is defined %}
        <link rel="stylesheet" href="{{ stripeCssUrl }}">
    {% endif %}
{% endblock %}
```

#### 2. Add JavaScript before `</body>`

```twig
{% block javascript %}
    {{ parent() }}
    {% if stripeConfigScript is defined %}
        {{ stripeConfigScript|raw }}
    {% endif %}
{% endblock %}
```

**That's it!** The JavaScript will automatically:
- Find the Stripe payment method radio button
- Inject the Payment Element container
- Load Stripe.js
- Handle payment flow

---

### Option 2: Manual Theme Override

If you prefer more control, override the payment template in your theme:

#### 1. Copy base payment template

```bash
cp vendor/oxid-esales/wave-theme/tpl/page/checkout/payment.html.twig \
   application/views/[your-theme]/page/checkout/payment.html.twig
```

#### 2. Add Stripe CSS and JS

```twig
{# In <head> or css block #}
{% if stripeCssUrl is defined %}
    <link rel="stylesheet" href="{{ stripeCssUrl }}">
{% endif %}

{# Before </body> or in javascript block #}
{% if stripeConfigScript is defined %}
    {{ stripeConfigScript|raw }}
{% endif %}
```

---

## How It Works (Technical Details)

### 1. PaymentController Injection

When the payment page loads, `PaymentController::render()`:

```php
// Creates PaymentIntent
$paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);

// Builds configuration
$stripeConfig = [
    'publishableKey' => 'pk_test_...',
    'clientSecret' => 'pi_...secret...',
    'returnUrl' => 'https://shop.com/index.php?cl=order&fnc=stripeReturn',
    'locale' => 'en',
    'labels' => [...]
];

// Injects into template
$this->addTplParam('stripeConfigScript', '<script>window.stripeConfig = {...}</script>...');
$this->addTplParam('stripeCssUrl', 'https://.../stripe_payment_element.css');
```

### 2. JavaScript Execution

When `stripe_payment_element.js` loads:

```javascript
document.addEventListener('DOMContentLoaded', function() {
    // 1. Find Stripe payment radio button
    const stripeRadio = document.querySelector('input[value="osc_stripe_card"]');

    // 2. Create container and inject after payment method
    const stripeContainer = createStripeContainer(stripeRadio);

    // 3. Load Stripe.js dynamically
    loadStripeJs(); // Loads https://js.stripe.com/v3/

    // 4. Listen for payment method changes
    paymentRadios.forEach(radio => {
        radio.addEventListener('change', handlePaymentMethodChange);
    });

    // 5. Intercept form submission
    form.addEventListener('submit', async (e) => {
        if (selectedPayment === 'osc_stripe_card') {
            e.preventDefault();
            await stripe.confirmPayment({ elements, ... });
        }
    });
});
```

### 3. Payment Flow

```
1. Customer selects "Stripe Card Payment" radio button
                    ↓
2. JavaScript shows stripe-payment-container (display: block)
                    ↓
3. Stripe Payment Element initializes with clientSecret
                    ↓
4. Customer enters card details
                    ↓
5. Customer clicks "Next Step"
                    ↓
6. JavaScript intercepts submit event
                    ↓
7. stripe.confirmPayment() called
                    ↓
8. Stripe handles 3D Secure if needed
                    ↓
9. Redirects to: cl=order&fnc=stripeReturn
                    ↓
10. OrderController::stripeReturn() creates order
```

---

## Configuration in metadata.php

**Important:** The `metadata.php` does **NOT** have a `blocks` section:

```php
$aModule = [
    'id' => 'osc_stripe_wallet',
    'extend' => [
        \OxidEsales\Eshop\Core\ViewConfig::class =>
            \OxidSolutionCatalysts\Stripe\Core\ViewConfig::class,
        \OxidEsales\Eshop\Application\Controller\PaymentController::class =>
            \OxidSolutionCatalysts\Stripe\Controller\PaymentController::class,
        \OxidEsales\Eshop\Application\Controller\OrderController::class =>
            \OxidSolutionCatalysts\Stripe\Controller\OrderController::class,
    ],
    'templates' => [],  // Empty - not using template registration
    // NO blocks section - it's Smarty-only
];
```

---

## Template Parameters Available

The PaymentController provides these parameters to **all payment page templates**:

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `stripeConfigScript` | string (HTML) | Complete `<script>` tags with config and JS file | `<script>window.stripeConfig={...}</script>` |
| `stripeCssUrl` | string (URL) | CSS file URL | `https://shop.com/modules/osc/stripe/out/src/css/...` |

**Usage in Twig:**

```twig
{# CSS in head #}
<link rel="stylesheet" href="{{ stripeCssUrl }}">

{# JavaScript before </body> #}
{{ stripeConfigScript|raw }}
```

**Note:** The `|raw` filter is required to output HTML without escaping.

---

## JavaScript Configuration Object

The `window.stripeConfig` object contains:

```javascript
{
    publishableKey: 'pk_test_...',          // Stripe public API key
    clientSecret: 'pi_...client_secret...',  // PaymentIntent client secret
    returnUrl: 'https://shop.com/...',       // Return URL after payment
    locale: 'en',                             // Language code
    testMode: true,                           // Test/Live mode flag
    primaryColor: '#0570de',                  // Brand color
    labels: {                                 // Translated labels
        cardPayment: 'Credit Card Payment',
        paymentDesc: 'Pay securely...',
        processing: 'Processing',
        // ... all translations
    }
}
```

---

## DOM Structure Created by JavaScript

When Stripe payment is selected, the JavaScript creates:

```html
<div id="stripe-payment-container" class="stripe-payment-wrapper">
    <div class="stripe-payment-element-wrapper">

        <!-- Header -->
        <div class="payment-description">
            <h3>Credit Card Payment</h3>
            <p>Pay securely with your credit or debit card...</p>
        </div>

        <!-- Stripe Payment Element mounts here -->
        <div id="payment-element" class="stripe-payment-element"></div>

        <!-- Error display -->
        <div id="payment-errors" class="stripe-errors alert alert-danger" style="display: none;">
            <i class="fa fa-exclamation-circle"></i>
            <span id="payment-error-message"></span>
        </div>

        <!-- Loading indicator -->
        <div id="payment-loading" class="stripe-loading" style="display: none;">
            <div class="spinner-border text-primary"></div>
            <p>Processing your payment. Please wait...</p>
        </div>

        <!-- Security badge -->
        <div class="stripe-security-info">
            <p><i class="fa fa-lock"></i> Secure payment powered by Stripe</p>
        </div>

    </div>
</div>
```

---

## Styling Customization

### Override CSS Variables

Add to your theme's CSS:

```css
.stripe-payment-wrapper {
    /* Your custom styles */
    border-color: #your-brand-color;
    border-radius: 8px;
}

.payment-description h3 {
    color: #your-brand-color;
    font-family: 'Your Font', sans-serif;
}
```

### Customize Stripe Element Appearance

Edit `PaymentController::buildStripeConfig()` to modify Stripe's appearance:

```php
$stripeConfig = [
    // ...
    'primaryColor' => '#ff0000',  // Your brand color
];
```

This affects Stripe's hosted Payment Element styling.

---

## Troubleshooting

### JavaScript Not Loading

**Problem:** Payment Element doesn't appear

**Solutions:**

1. **Check template includes script:**
   ```twig
   {{ stripeConfigScript|raw }}
   ```

2. **Check browser console** for errors:
   ```javascript
   // Expected in console:
   window.stripeConfig // Should be an object
   ```

3. **Verify JavaScript file path:**
   ```bash
   ls -la source/extensions/stripe/out/src/js/stripe_payment_element.js
   ```

### CSS Not Applied

**Problem:** Payment Element has no styling

**Solutions:**

1. **Check CSS is included:**
   ```twig
   <link rel="stylesheet" href="{{ stripeCssUrl }}">
   ```

2. **Verify CSS file exists:**
   ```bash
   ls -la source/extensions/stripe/out/src/css/stripe_payment_element.css
   ```

3. **Check browser Network tab** - CSS should load successfully

### Payment Element Not Initializing

**Problem:** `clientSecret` is empty or missing

**Check:**

1. **User is logged in** - PaymentIntent requires authenticated user
2. **Basket has products** - Cannot create PaymentIntent for empty basket
3. **Stripe API keys configured** - Check module settings
4. **Check PHP error log:**
   ```bash
   tail -f source/log/oxideshop.log
   ```

### Form Not Submitting

**Problem:** Clicking "Next Step" does nothing

**Solutions:**

1. **Check JavaScript console** for errors
2. **Verify Stripe.js loaded:**
   ```javascript
   typeof Stripe // Should be "function"
   ```
3. **Check form selector** - JavaScript may not find the form
4. **Verify payment method ID:**
   ```javascript
   // Should find:
   document.querySelector('input[value="osc_stripe_card"]')
   ```

---

## Testing

### 1. Verify Script Injection

**View page source** on payment page and search for:

```html
<script>window.stripeConfig = {"publishableKey":"pk_test_...
```

### 2. Check Browser Console

Open DevTools Console, should see:

```javascript
window.stripeConfig
// {publishableKey: "pk_test_...", clientSecret: "pi_...", ...}
```

### 3. Test Payment Flow

1. Add product to basket
2. Proceed to checkout
3. Select "Stripe Card Payment"
4. **Expected:** Payment Element appears below radio button
5. Enter test card: `4242 4242 4242 4242`
6. Click "Next Step"
7. **Expected:** Redirects to order confirmation or thank you page

### 4. Test 3D Secure

1. Use 3DS test card: `4000 0025 0000 3155`
2. **Expected:** Stripe modal opens for authentication
3. Click "Complete authentication"
4. **Expected:** Redirects back to order page

---

## Migration from Smarty to Twig

If you're upgrading from OXID 6.x (Smarty) to 7.x (Twig):

### Before (Smarty with blocks):

```php
// metadata.php
'blocks' => [
    [
        'template' => 'page/checkout/payment.tpl',
        'block' => 'checkout_payment_main',
        'file' => 'views/blocks/checkout_payment.tpl',
    ],
],
```

### After (Twig with JavaScript):

```php
// metadata.php
// NO blocks section

// PaymentController injects JavaScript automatically
```

**Theme changes:**

```twig
{# Add to payment.html.twig #}
<link rel="stylesheet" href="{{ stripeCssUrl }}">
{{ stripeConfigScript|raw }}
```

---

## Performance Optimization

### 1. Conditional Loading

JavaScript only loads on payment page (via controller check):

```php
if ($this->isStripeAvailable()) {
    // Only inject when Stripe is configured
}
```

### 2. Lazy Stripe.js Loading

Stripe.js loads dynamically only when needed:

```javascript
function loadStripeJs() {
    const script = document.createElement('script');
    script.src = 'https://js.stripe.com/v3/';
    script.async = true;
    document.head.appendChild(script);
}
```

### 3. Single Initialization

Payment Element initializes once and reuses:

```javascript
if (!paymentElement) {
    paymentElement = elements.create('payment');
}
```

---

## Security Considerations

### 1. PCI Compliance

✅ **PCI SAQ A** - Card data never touches your server
- Stripe.js loads from Stripe's CDN
- Payment Element is hosted by Stripe
- `clientSecret` is safe to expose (single-use, amount-specific)

### 2. XSS Protection

All configuration is JSON-encoded with security flags:

```php
json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
```

### 3. CSRF Protection

Uses OXID's standard session management:

```php
Registry::getSession()->setVariable('stripe_payment_intent_id', $intentId);
```

---

## Summary

**Key Points:**

1. ✅ **No template blocks needed** - Smarty-only feature
2. ✅ **JavaScript injection** - PaymentController provides script
3. ✅ **Theme integration** - Add `{{ stripeConfigScript|raw }}` to template
4. ✅ **Automatic DOM manipulation** - JavaScript finds and enhances payment form
5. ✅ **PCI compliant** - Card data never touches server
6. ✅ **Works with any OXID theme** - No theme-specific code required

**Files to know:**

- `/src/Controller/PaymentController.php` - Injects configuration
- `/out/src/js/stripe_payment_element.js` - Payment Element logic
- `/out/src/css/stripe_payment_element.css` - Styling
- Theme's `payment.html.twig` - Include `{{ stripeConfigScript|raw }}`

---

**Last Updated:** 2025-01-14
**Tested With:** OXID eShop 7.0+, Stripe API 2024-12-18.acacia
