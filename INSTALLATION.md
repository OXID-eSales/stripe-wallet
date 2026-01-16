# Buy Now Feature - Installation & Activation

## File Structure (PayPal-Style)

The Buy Now feature has been implemented following OXID 7.x Twig conventions, matching the PayPal module structure:

```
source/extensions/stripe/
├── metadata.php                          # Module configuration (updated)
├── src/Component/Controller/
│   └── CheckoutOnePageController.php     # Controller with addProductAndCheckout() method
├── views/twig/
│   └── extensions/themes/default/
│       └── page/details/inc/
│           └── productmain.html.twig     # Product page template override
├── out/
│   └── css/
│       └── buy-now.css                   # Button styling
└── translations/
    ├── en/stripe_lang.php                # English translations
    └── de/stripe_lang.php                # German translations
```

## Changes Made to metadata.php

### 1. Controller Registration
```php
'controllers' => [
    // ... existing controllers ...
    'stripe_checkout_onepage' => \OxidEsales\StripeWallet\Component\Controller\CheckoutOnePageController::class,
],
```

### 2. Templates Section
**Note:** Twig template overrides use directory convention, NOT the 'templates' array.
The template at `views/twig/extensions/themes/default/page/details/inc/productmain.html.twig` is automatically discovered by OXID.

## Activation Steps

Run these commands from your OXID root directory:

```bash
cd /home/gaad/PhpStormProjects/OXID/Stripe/stripe-wallet/source

# 1. Clear cache
rm -rf tmp/*

# 2. Deactivate module
php bin/oe-console oe:module:deactivate oe_payments_stripe_wallet

# 3. Activate module (registers new controller and template)
php bin/oe-console oe:module:activate oe_payments_stripe_wallet

# 4. Clear cache again
php bin/oe-console oe:cache:clear

# 5. Regenerate views (IMPORTANT!)
php bin/oe-console oe:views:generate
```

## Verification

After activation, verify the installation:

### 1. Check Module Status
```bash
php bin/oe-console oe:module:list
```

You should see `oe_payments_stripe_wallet` as **active**.

### 2. Check Template
Navigate to any product detail page. You should see:
- Standard "Add to Cart" button (from parent theme)
- NEW green "Buy Now" button below it with lightning icon

### 3. Test Buy Now Flow
1. Click "Buy Now" button on product page
2. Should redirect to: `/index.php?cl=stripe_checkout_onepage`
3. Product should be in basket
4. One-page checkout should load

## How It Works

### Template Override Pattern (PayPal-Style)

```twig
{# views/twig/extensions/themes/default/page/details/inc/productmain.html.twig #}
{% extends "page/details/inc/productmain.html.twig" %}

{% block details_productmain_tobasket %}
    {{ parent() }}  {# Keeps original Add to Cart button #}

    {# Adds Buy Now button after it #}
    <div class="buy-now-wrapper">
        <button id="buyNowButton" ...>Buy Now</button>
    </div>
{% endblock %}
```

### Controller Flow

```
Product Page (Buy Now clicked)
    ↓
JavaScript collects product data
    ↓
POST to: cl=stripe_checkout_onepage&fnc=addProductAndCheckout
    ↓
CheckoutOnePageController::addProductAndCheckout()
    ↓
- Validate CSRF token
- Clear basket (optional)
- Add product to basket
- Set session flag: isBuyNowCheckout = true
    ↓
Redirect to: cl=stripe_checkout_onepage
    ↓
One-page checkout loads with product
```

## Configuration Options

### Keep Existing Cart Items

By default, Buy Now **clears the basket**. To add to existing cart instead:

Edit `src/Component/Controller/CheckoutOnePageController.php` line 296:

```php
// Comment out this line:
// $basket->deleteBasket();
```

### Customize Button Styling

Override CSS in your theme or edit `out/css/buy-now.css`:

```css
.stripe-buy-now {
    background-color: #your-color !important;
    /* Your styles here */
}
```

### Change Button Text

Edit translation files:
- `translations/en/stripe_lang.php`
- `translations/de/stripe_lang.php`

## Troubleshooting

### Button Not Appearing

**Check 1:** Module activated?
```bash
php bin/oe-console oe:module:list | grep stripe
```

**Check 2:** Views regenerated?
```bash
php bin/oe-console oe:views:generate
```

**Check 3:** Cache cleared?
```bash
rm -rf tmp/* && php bin/oe-console oe:cache:clear
```

**Check 4:** Template file exists?
```bash
ls -la source/extensions/stripe/views/twig/extensions/themes/default/page/details/inc/productmain.html.twig
```

### CSS Not Loading

**Check:** CSS file exists?
```bash
ls -la source/extensions/stripe/out/css/buy-now.css
```

**Fix:** Clear browser cache and hard refresh (Ctrl+Shift+R)

### Controller Error: "Class not found"

**Check:** Controller registered in metadata.php?
```bash
grep "stripe_checkout_onepage" source/extensions/stripe/metadata.php
```

Should show:
```php
'stripe_checkout_onepage' => \OxidEsales\StripeWallet\Component\Controller\CheckoutOnePageController::class,
```

### "Invalid request token" Error

**Cause:** CSRF token mismatch or expired session

**Fix:**
1. Clear cookies
2. Start fresh session
3. Check that template includes: `{{ oViewConf.getSessionChallengeToken() }}`

## Module ID

The module ID is: **oe_payments_stripe_wallet**

Use this for:
- Command line operations: `oe:module:activate oe_payments_stripe_wallet`
- Getting module URL: `oViewConf.getModuleUrl('oe_payments_stripe_wallet', 'out/css/buy-now.css')`

## Directory Convention

OXID 7.x with Twig uses **directory-based template discovery**:

```
Module: oe_payments_stripe_wallet
Override: page/details/inc/productmain.html.twig
Location: views/twig/extensions/themes/default/page/details/inc/productmain.html.twig
```

This matches the PayPal module pattern exactly.

## What's Different from PayPal?

### Similarities ✅
- Template location: `views/twig/extensions/themes/default/`
- Extends parent: `{% extends "page/details/inc/productmain.html.twig" %}`
- Uses blocks: `{% block details_productmain_tobasket %}`
- Calls parent: `{{ parent() }}`

### Differences
- **PayPal:** Includes external button components from `@osc_paypal/frontend/`
- **Stripe:** Inline button with JavaScript in same file
- **PayPal:** Uses `ArticleDetails` widget extension
- **Stripe:** Uses standalone controller

## Next Steps

After successful activation:

1. **Test on product page** - Button should appear
2. **Test Buy Now flow** - Should redirect to checkout
3. **Test payment** - Complete a test order
4. **Customize styling** - Match your theme
5. **Track analytics** - Add tracking code (see docs)

## Documentation

See `/docs/one-page-checkout/` for complete documentation:
- `BUY_NOW_FEATURE.md` - Full feature documentation
- `ONE_PAGE_CHECKOUT_IMPLEMENTATION.md` - Checkout implementation
- `USAGE_EXAMPLES.md` - Code examples
- `README.md` - Documentation index

---

**Last Updated:** 2025-11-12
**Module Version:** 1.0.0
**OXID Compatibility:** 7.x with Twig
