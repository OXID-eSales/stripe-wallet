# Twig Templates Guide - One-Page Checkout

## Overview

This guide covers the Twig template integration for the one-page checkout in OXID eShop.

## Architecture

```
┌─────────────────────────────────────────────┐
│  Browser (User)                              │
└────────────┬────────────────────────────────┘
             │
             ├─→ Initial Page Load (Server-Side)
             │   ├─ Twig Templates Render HTML
             │   ├─ Cart Data Embedded
             │   └─ JavaScript Initialized
             │
             └─→ User Interactions (Client-Side)
                 ├─ Form Submissions → GraphQL API
                 ├─ Real-time Validation
                 ├─ Error Display (No Reload)
                 └─ Step Navigation
```

## Template Structure

### Main Template

**File:** `views/twig/page/checkout/onepage.html.twig`

**Purpose:** Main layout for one-page checkout

**Features:**
- Extends base page layout
- Includes all component templates
- Embeds JavaScript configuration
- Progressive enhancement (works without JS)

```twig
{% extends "layout/page.html.twig" %}

{% block content %}
    {# Checkout sections #}
    {% include "page/checkout/inc/address_form.html.twig" %}
    {% include "page/checkout/inc/payment_form.html.twig" %}
    {% include "page/checkout/inc/review_summary.html.twig" %}
{% endblock %}
```

### Component Templates

#### 1. Address Form
**File:** `views/twig/page/checkout/inc/address_form.html.twig`

**Features:**
- Billing and shipping address forms
- Country dropdown populated from OXID
- Pre-filled with user data
- Client-side validation
- Accessibility (ARIA, autocomplete)

#### 2. Payment Form
**File:** `views/twig/page/checkout/inc/payment_form.html.twig`

**Features:**
- Payment method selection
- Credit card input fields
- Saved payment methods (for logged-in users)
- Card brand detection
- PCI compliance indicators

#### 3. Cart Summary
**File:** `views/twig/page/checkout/inc/cart_summary.html.twig`

**Features:**
- Cart items display
- Price breakdown
- Voucher/coupon application
- Shipping cost calculator

#### 4. Review Summary
**File:** `views/twig/page/checkout/inc/review_summary.html.twig`

**Features:**
- Order review before placement
- Edit links back to previous steps
- Final confirmation

#### 5. Order Success
**File:** `views/twig/page/checkout/inc/order_success.html.twig`

**Features:**
- Success confirmation
- Order details
- Next steps information

## Controller

### CheckoutOnePageController

**File:** `src/Component/Controller/CheckoutOnePageController.php`

**Extends:** `OxidEsales\Eshop\Application\Controller\FrontendController`

**Key Methods:**

```php
class CheckoutOnePageController extends FrontendController
{
    // Render template
    public function render(): string

    // Get checkout session ID
    public function getCheckoutSessionId(): string

    // Get cart items as JSON
    public function getCartItemsJson(): string

    // Get country list
    public function getCountryList()

    // Get payment methods
    public function getPaymentList(): array

    // Handle 3D Secure return
    public function handleReturn()
}
```

## Template Variables

### Available in Templates

```twig
{# User Data #}
{{ oxcmp_user.oxuser__oxfname.value }}        {# First name #}
{{ oxcmp_user.oxuser__oxlname.value }}        {# Last name #}
{{ oxcmp_user.oxuser__oxusername.value }}     {# Email #}

{# Basket Data #}
{{ oxcmp_basket.getProductsCount() }}         {# Item count #}
{{ oxcmp_basket.getPrice().getBruttoPrice() }} {# Total #}
{{ oxcmp_basket.getBasketCurrency().sign }}   {# Currency #}

{# Delivery Address #}
{{ deliveryAddress.oxaddress__oxstreet.value }}
{{ deliveryAddress.oxaddress__oxcity.value }}

{# Custom Controller Methods #}
{{ oView.getCheckoutSessionId() }}
{{ oView.getCartItemsJson()|raw }}
{{ oView.getCountryList() }}
{{ oView.getPaymentList() }}
```

## JavaScript Integration

### Configuration Embedding

The template embeds JavaScript configuration from PHP:

```twig
<script>
    const checkoutConfig = {
        graphqlEndpoint: '{{ oViewConf.getGraphQLEndpoint() }}',
        encryptionKey: '{{ oViewConf.getEncryptionKey() }}',
        sessionId: '{{ oView.getCheckoutSessionId() }}',
        customerId: '{{ oxcmp_user.oxuser__oxid.value }}',
        cartTotal: {{ oxcmp_basket.getPrice().getBruttoPrice() }},
        currency: '{{ oxcmp_basket.getBasketCurrency().name }}',
        returnUrl: '{{ oViewConf.getSelfLink()|cat:"cl=checkout_onepage&fnc=handleReturn" }}',
        locale: '{{ oView.getActiveLangAbbr() }}'
    };
</script>
```

### Initialization

```javascript
// Initialize checkout
const checkout = new EnhancedCheckoutClient(
    checkoutConfig.graphqlEndpoint,
    checkoutConfig.encryptionKey
);

// Initialize abandonment tracking
const abandonmentTracker = new CheckoutAbandonmentTracker(checkout);

// Initialize checkout flow
window.oxCheckout = new CheckoutFlow(checkout, abandonmentTracker, checkoutConfig);
```

## Progressive Enhancement

### Works Without JavaScript

All forms have fallback POST actions:

```twig
<form action="{{ oViewConf.getSelfLink() }}" method="post">
    <input type="hidden" name="cl" value="checkout_onepage">
    <input type="hidden" name="fnc" value="updateAddress">
    <input type="hidden" name="stoken" value="{{ oViewConf.getSessionChallengeToken() }}">
    {# Form fields #}
</form>
```

If JavaScript is disabled:
- Forms submit via POST
- Page reloads with results
- Server-side validation
- Traditional multi-page checkout flow

### Enhanced With JavaScript

When JavaScript is enabled:
- Forms submit via GraphQL
- No page reloads
- Real-time validation
- Toast notifications
- Smooth step transitions

## Translation System

### Language Files

**Location:** `translations/[lang]/stripe_lang.php`

**Example:**

```php
$aLang = [
    'CHECKOUT_TITLE' => 'Checkout',
    'CHECKOUT_CONTINUE_TO_PAYMENT' => 'Continue to Payment',
    'CHECKOUT_PAY_NOW' => 'Pay Now',
    // ... more translations
];
```

### Usage in Templates

```twig
{{ "CHECKOUT_TITLE"|translate }}
{{ "CHECKOUT_CONTINUE_TO_PAYMENT"|translate }}
```

### Multilingual Support

Automatically uses OXID's active language:

```twig
{% set lang = oView.getActiveLangAbbr() %}
<html lang="{{ lang }}">
```

## Styling

### CSS Structure

**File:** `out/css/checkout-onepage.css`

**Includes:**
- Checkout grid layout
- Form styling
- Progress indicator
- Cart summary
- Responsive design
- Error states
- Loading states

### OXID Theme Integration

Templates extend OXID's base templates:

```twig
{% extends "layout/page.html.twig" %}
```

Inherits:
- Header
- Footer
- Navigation
- Theme styles
- JavaScript libraries

## Routing

### URL Structure

```
/checkout-onepage           {# Main checkout page #}
/checkout-onepage?fnc=handleReturn  {# 3D Secure return #}
```

### OXID Configuration

Add to module's `metadata.php`:

```php
'controllers' => [
    'checkout_onepage' => OxidEsales\StripeWallet\Component\Controller\CheckoutOnePageController::class,
],

'templates' => [
    '@stripe/page/checkout/onepage.html.twig' => 'stripe/views/twig/page/checkout/onepage.html.twig',
],
```

## Security

### CSRF Protection

All forms include CSRF token:

```twig
<input type="hidden" name="stoken" value="{{ oViewConf.getSessionChallengeToken() }}">
```

Validated in controller:

```php
private function isValidRequest(): bool
{
    $token = Registry::getRequest()->getRequestParameter('stoken');
    $sessionToken = Registry::getSession()->getSessionChallengeToken();
    return $token === $sessionToken;
}
```

### XSS Prevention

Twig auto-escapes output:

```twig
{{ user.name }}                {# Auto-escaped #}
{{ user.name|e }}             {# Explicitly escaped #}
{{ htmlContent|raw }}          {# Bypass escaping (use carefully!) #}
```

### PCI Compliance

Card data never hits the server:
- Encrypted client-side
- Submitted via GraphQL
- Decrypted in memory only
- Never stored in database

## SEO Considerations

### Meta Tags

```twig
{% block head_meta_robots %}
    <meta name="robots" content="noindex, nofollow">
{% endblock %}
```

### Canonical URL

```twig
{% block head_canonical %}
    <link rel="canonical" href="{{ oViewConf.getActUrl() }}">
{% endblock %}
```

### Schema.org Markup

```twig
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "CheckoutPage",
    "name": "{{ "CHECKOUT_TITLE"|translate }}",
    "url": "{{ oViewConf.getActUrl() }}"
}
</script>
```

## Testing

### Template Testing

```bash
# Clear Twig cache
rm -rf source/tmp/*

# Test template rendering
curl http://localhost/checkout-onepage

# Check for errors
tail -f source/log/oxideshop.log
```

### JavaScript Testing

```javascript
// Test configuration loading
console.log(window.oxCheckout);
console.log(window.checkoutConfig);

// Test form submission
document.getElementById('address-form').dispatchEvent(new Event('submit'));
```

## Customization

### Override Templates

In your theme, create:

```
application/views/twig/page/checkout/onepage.html.twig
```

OXID will use your version instead.

### Extend Templates

```twig
{% extends "@stripe/page/checkout/onepage.html.twig" %}

{% block content %}
    <div class="my-custom-section">
        {# Custom content #}
    </div>
    {{ parent() }}
{% endblock %}
```

### Add Custom JavaScript

```twig
{% block footer_js %}
    {{ parent() }}
    <script src="/custom/checkout.js"></script>
{% endblock %}
```

## Troubleshooting

### Template Not Found

**Error:** `Unable to find template "@stripe/page/checkout/onepage.html.twig"`

**Solution:**
1. Check module activation
2. Clear cache: `rm -rf source/tmp/*`
3. Check file path matches `metadata.php`

### Variables Not Available

**Error:** `Variable "oView" does not exist`

**Solution:**
- Use correct variable names (`oView`, `oxcmp_user`, `oxcmp_basket`)
- Check controller extends `FrontendController`
- Verify method is public

### JavaScript Not Loading

**Error:** `CheckoutFlow is not defined`

**Solution:**
- Check script inclusion order
- Verify file paths
- Check browser console for 404 errors
- Clear browser cache

## Performance

### Template Caching

Twig automatically caches compiled templates:

```
source/tmp/smarty/
```

### Asset Optimization

Combine and minify JavaScript:

```bash
uglifyjs checkout-flow.js \
    error-handling-system.js \
    checkout-abandonment-tracking.js \
    -o checkout.min.js
```

### Lazy Loading

Load non-critical assets after page load:

```javascript
window.addEventListener('load', () => {
    // Load analytics
    // Load chat widget
});
```

## Best Practices

### 1. Keep Templates Simple

✅ **Good:** Logic in controller, display in template
❌ **Bad:** Complex logic in templates

### 2. Reuse Components

✅ **Good:** Create `inc/*.html.twig` files
❌ **Bad:** Copy-paste between templates

### 3. Test Without JavaScript

✅ **Good:** Ensure forms work via POST
❌ **Bad:** JavaScript-only checkout

### 4. Use Semantic HTML

✅ **Good:** `<button type="submit">`, `<label for="field">`
❌ **Bad:** `<div onclick="submit()">`

### 5. Optimize Images

✅ **Good:** Lazy load, responsive images
❌ **Bad:** Load all images immediately

## Further Reading

- [Twig Documentation](https://twig.symfony.com/doc/)
- [OXID Template System](https://docs.oxid-esales.com/developer/en/latest/development/modules_components_themes/theme/)
- [Progressive Enhancement](https://developer.mozilla.org/en-US/docs/Glossary/Progressive_Enhancement)
