# Stripe Payment Element - Quick Start Guide

**For OXID eShop 7.0+ with Twig Templates**

---

## ✅ Implementation Complete

The Stripe Payment Element is implemented using **JavaScript injection** (not template blocks, as those only work with Smarty).

---

## 🚀 Quick Setup (3 Steps)

### 1. Add to Your Theme

Edit your theme's payment or layout template:

```bash
# Example locations:
# application/views/apex/page/checkout/payment.html.twig
# application/views/wave/page/checkout/payment.html.twig
# application/views/[your-theme]/layout/page.html.twig
```

**Add this code:**

```twig
{# CSS in head block #}
{% block head_css %}
    {{ parent() }}
    {% if stripeCssUrl is defined %}
        <link rel="stylesheet" href="{{ stripeCssUrl }}">
    {% endif %}
{% endblock %}

{# JavaScript before </body> #}
{% block javascript %}
    {{ parent() }}
    {% if stripeConfigScript is defined %}
        {{ stripeConfigScript|raw }}
    {% endif %}
{% endblock %}
```

### 2. Activate & Clear Cache

```bash
vendor/bin/oe-console oe:module:activate osc_stripe
rm -rf source/tmp/*
vendor/bin/oe-console oe:cache:clear
```

### 3. Configure API Keys

Admin Panel → Extensions → Modules → Stripe:
- Test Publishable Key: `pk_test_...`
- Test Secret Key: `sk_test_...`

---

## 🧪 Test It

1. Add product to cart
2. Go to checkout payment page
3. Select "Stripe Card Payment"
4. **Expected:** Payment Element appears below radio button
5. Enter test card: `4242 4242 4242 4242`, any future expiry, any CVC
6. Complete order
7. **Expected:** Order confirmation page

---

## ❓ Troubleshooting

### Payment Element doesn't appear?

**Check browser console:**
```javascript
window.stripeConfig  // Should show configuration object
```

**If undefined:**
- Verify template includes `{{ stripeConfigScript|raw }}`
- Clear cache: `rm -rf source/tmp/*`
- Check Stripe API keys are configured

### JavaScript errors?

**Common issues:**
- Missing `|raw` filter: Use `{{ stripeConfigScript|raw }}` not `{{ stripeConfigScript }}`
- Wrong block name: Use `{% block javascript %}` or check your theme's block name
- Cache not cleared: Run `rm -rf source/tmp/*`

### Styling issues?

**Check CSS is loaded:**
```twig
{% if stripeCssUrl is defined %}
    <link rel="stylesheet" href="{{ stripeCssUrl }}">
{% endif %}
```

---

## 📚 Full Documentation

- **TWIG_INTEGRATION.md** - Complete integration guide with all options
- **SOLUTION_SUMMARY.md** - Technical explanation of the approach
- **SETUP_STATUS.md** - Implementation status and testing checklist

---

## 🔍 How It Works (Technical Overview)

1. **PaymentController** creates PaymentIntent when page loads
2. **Controller injects** JavaScript configuration into template
3. **JavaScript loads** and finds Stripe payment radio button
4. **Payment Element** container injected into DOM
5. **Stripe.js loads** dynamically from CDN
6. **Form submission** intercepted for Stripe payments
7. **Redirects to** OrderController::stripeReturn() after payment

**Key files:**
- `/src/Controller/PaymentController.php` - Injects configuration
- `/out/src/js/stripe_payment_element.js` - Payment Element logic
- `/out/src/css/stripe_payment_element.css` - Styling
- **No template blocks** - JavaScript injection approach

---

## ✅ Why This Approach?

**Template blocks only work with Smarty, not Twig:**
- Smarty: Uses `[{block name="..."}]` syntax, `.tpl` files
- Twig: Different template engine, incompatible with blocks in metadata.php

**JavaScript injection works with Twig:**
- Controller adds configuration to template parameters
- JavaScript manipulates DOM at runtime
- Works with any theme, no template dependencies
- Modern, maintainable approach

---

## 📋 Checklist

- [ ] Theme template includes `{{ stripeConfigScript|raw }}`
- [ ] Theme template includes CSS: `<link rel="stylesheet" href="{{ stripeCssUrl }}">`
- [ ] Module activated: `oe-console oe:module:activate osc_stripe`
- [ ] Cache cleared: `rm -rf source/tmp/*`
- [ ] API keys configured in admin
- [ ] Payment method activated for countries/currencies
- [ ] Test payment successful with test card

---

## 🎉 Done!

Your Stripe Payment Element is now integrated and ready to accept payments.

**Support:** See TWIG_INTEGRATION.md for detailed troubleshooting.

---

**Last Updated:** 2025-01-14
