# Stripe Card Payment Form Integration Options

**Complete Guide to Stripe Payment Form Integration Methods**
**Version:** 1.0.0
**Date:** 2025-01-14
**Last Updated:** Based on Stripe API 2025

---

## Overview

This document compares all available Stripe payment form integration methods, helping you choose the best approach for your OXID eShop implementation.

**Quick Recommendation:** For most implementations, use **Payment Element** (embedded) for best conversion rates, security, and future payment method support.

---

## Integration Methods Comparison

| Method | Hosting | PCI SAQ Level | Customization | Payment Methods | Best For |
|--------|---------|---------------|---------------|-----------------|----------|
| **Payment Element** | Embedded iframe | SAQ A (easiest) | High | 100+ methods | ✅ **Recommended** - Modern checkout |
| **Card Element** | Embedded iframe | SAQ A | High | Card only | Legacy - card-only needs |
| **Embedded Checkout** | Stripe iframe | SAQ A | Medium | 100+ methods | Quick setup, less control |
| **Hosted Checkout** | Stripe redirect | SAQ A | Low | 100+ methods | Fastest implementation |
| **Direct API** | Your server | SAQ D (hardest) | Full | All | ❌ **Not Recommended** - Complex compliance |

---

## Method 1: Payment Element (Recommended) ⭐

### Overview

The **Payment Element** is Stripe's modern, unified payment component that accepts 100+ payment methods through a single integration. It combines the best of Elements with automatic payment method selection.

### Key Features

✅ **Single integration** for 100+ payment methods (cards, wallets, bank transfers, Buy Now Pay Later)
✅ **11.9% higher conversion** on average vs. card-only
✅ **Automatic payment method optimization** based on customer location
✅ **Built-in validation** and error handling
✅ **PCI SAQ A compliance** (easiest level)
✅ **Highly customizable** styling and layout
✅ **Dynamic 3D Secure** (SCA) handling
✅ **Localization** for 40+ languages
✅ **Mobile-optimized** UI

### Architecture

```
┌────────────────────────────────────────────────────────────┐
│                    Your Website/App                         │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Your Checkout Page (HTML/CSS/JS)                    │  │
│  │                                                       │  │
│  │  ┌────────────────────────────────────────────────┐  │  │
│  │  │ Payment Element (Stripe-hosted iframe)         │  │  │
│  │  │                                                 │  │  │
│  │  │  [Card Number Field        ]  🔒 Secure       │  │  │
│  │  │  [Expiry]  [CVC]                               │  │  │
│  │  │                                                 │  │  │
│  │  │  OR                                             │  │  │
│  │  │                                                 │  │  │
│  │  │  [🍎 Apple Pay]  [G Pay]  [💳 Card]          │  │  │
│  │  │                                                 │  │  │
│  │  └─────────────────────────────────────────────────┘  │  │
│  │                                                       │  │
│  │  [Submit Payment Button] ← Your control              │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                             │
└─────────────────────────────┬───────────────────────────────┘
                              │
                              │ HTTPS (Secure)
                              ▼
┌────────────────────────────────────────────────────────────┐
│                    Stripe Backend                           │
│  • Payment processing                                       │
│  • Card data never touches your server                     │
│  • 3D Secure authentication                                 │
│  • Fraud detection (Stripe Radar)                          │
└────────────────────────────────────────────────────────────┘
```

### Implementation Steps

#### Step 1: Include Stripe.js

```html
<!-- Load Stripe.js from Stripe's CDN -->
<script src="https://js.stripe.com/v3/"></script>
```

#### Step 2: Create HTML Container

```html
<form id="payment-form">
    <!-- Payment Element will be inserted here -->
    <div id="payment-element"></div>

    <button id="submit">Submit Payment</button>

    <div id="error-message"></div>
</form>
```

#### Step 3: Initialize Stripe.js

```javascript
// Initialize Stripe with your publishable key
const stripe = Stripe('pk_test_YOUR_PUBLISHABLE_KEY');

// Create Payment Element options
const options = {
    clientSecret: 'pi_xxx_secret_xxx', // From server
    appearance: {
        theme: 'stripe', // or 'night', 'flat'
        variables: {
            colorPrimary: '#0570de',
            colorBackground: '#ffffff',
            colorText: '#30313d',
            colorDanger: '#df1b41',
            fontFamily: 'Ideal Sans, system-ui, sans-serif',
            spacingUnit: '4px',
            borderRadius: '4px'
        }
    }
};

// Create Elements instance
const elements = stripe.elements(options);

// Create and mount Payment Element
const paymentElement = elements.create('payment');
paymentElement.mount('#payment-element');
```

#### Step 4: Handle Form Submission

```javascript
const form = document.getElementById('payment-form');
const submitButton = document.getElementById('submit');
const errorMessage = document.getElementById('error-message');

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    submitButton.disabled = true;

    const {error} = await stripe.confirmPayment({
        elements,
        confirmParams: {
            return_url: 'https://your-site.com/order/complete'
        }
    });

    if (error) {
        errorMessage.textContent = error.message;
        submitButton.disabled = false;
    }
    // Customer will be redirected on success
});
```

#### Step 5: Server-Side Setup (PHP)

```php
<?php
// Create PaymentIntent on server
$stripe = new \Stripe\StripeClient('sk_test_YOUR_SECRET_KEY');

$paymentIntent = $stripe->paymentIntents->create([
    'amount' => 9999, // Amount in cents
    'currency' => 'eur',
    'automatic_payment_methods' => [
        'enabled' => true,
    ],
]);

// Return client_secret to frontend
echo json_encode(['clientSecret' => $paymentIntent->client_secret]);
```

### Customization Options

#### Layout Variations

```javascript
// Tabs layout (default)
const paymentElement = elements.create('payment', {
    layout: 'tabs'
});

// Accordion layout
const paymentElement = elements.create('payment', {
    layout: 'accordion'
});

// Auto layout (adaptive)
const paymentElement = elements.create('payment', {
    layout: {
        type: 'auto',
        defaultCollapsed: false
    }
});
```

#### Advanced Styling

```javascript
const appearance = {
    theme: 'stripe',
    variables: {
        colorPrimary: '#0570de',
        colorBackground: '#ffffff',
        colorText: '#30313d',
        colorDanger: '#df1b41',
        fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
        spacingUnit: '4px',
        borderRadius: '8px',
        fontSizeBase: '16px',
        fontWeightNormal: '400',
        fontWeightBold: '600'
    },
    rules: {
        '.Input': {
            border: '1px solid #e6e6e6',
            boxShadow: 'none'
        },
        '.Input:focus': {
            border: '1px solid #0570de',
            boxShadow: '0 0 0 3px rgba(5, 112, 222, 0.1)'
        },
        '.Label': {
            fontSize: '14px',
            fontWeight: '500',
            marginBottom: '8px'
        }
    }
};
```

### Payment Method Filtering

```javascript
// Limit to specific payment methods
const paymentElement = elements.create('payment', {
    paymentMethodOrder: ['card', 'apple_pay', 'google_pay'],
    // Or restrict to only cards
    // paymentMethodOrder: ['card']
});
```

### Pros & Cons

**Pros:**
- ✅ One integration for 100+ payment methods
- ✅ Highest conversion rates (11.9% increase on average)
- ✅ Automatic regional payment method optimization
- ✅ Future-proof (new methods added automatically)
- ✅ PCI SAQ A compliance (easiest)
- ✅ Built-in fraud prevention (Stripe Radar)
- ✅ Responsive and mobile-optimized
- ✅ Strong TypeScript support

**Cons:**
- ⚠️ Requires Stripe.js (external dependency)
- ⚠️ Limited control over individual field layout
- ⚠️ Customers must have JavaScript enabled

### Best Use Cases

- ✅ New integrations (always choose this)
- ✅ International businesses needing multiple payment methods
- ✅ Mobile-first checkout experiences
- ✅ Subscription and recurring payments
- ✅ B2C e-commerce platforms

---

## Method 2: Card Element (Legacy)

### Overview

The **Card Element** is Stripe's original card-specific component. It provides a single input field that collects card number, expiry, and CVC.

**⚠️ Note:** Stripe recommends migrating to Payment Element for new integrations.

### Key Features

✅ **PCI SAQ A compliance**
✅ **Single field** for all card details
✅ **High customization** of styling
✅ **Card brand detection** (Visa, Mastercard, etc.)
✅ **Built-in validation**
⚠️ **Card payments only** (no wallets, BNPL, etc.)

### Implementation

```javascript
const stripe = Stripe('pk_test_YOUR_KEY');
const elements = stripe.elements();

// Create card element
const cardElement = elements.create('card', {
    style: {
        base: {
            color: '#32325d',
            fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
            fontSmoothing: 'antialiased',
            fontSize: '16px',
            '::placeholder': {
                color: '#aab7c4'
            }
        },
        invalid: {
            color: '#fa755a',
            iconColor: '#fa755a'
        }
    }
});

cardElement.mount('#card-element');

// Handle submission
form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const {error, paymentMethod} = await stripe.createPaymentMethod({
        type: 'card',
        card: cardElement,
        billing_details: {
            name: 'Customer Name'
        }
    });

    if (error) {
        console.error(error);
    } else {
        // Send paymentMethod.id to server
        console.log(paymentMethod);
    }
});
```

### Alternative: Split Card Elements

For more granular layout control:

```javascript
// Individual elements for each card field
const cardNumber = elements.create('cardNumber');
const cardExpiry = elements.create('cardExpiry');
const cardCvc = elements.create('cardCvc');

cardNumber.mount('#card-number-element');
cardExpiry.mount('#card-expiry-element');
cardCvc.mount('#card-cvc-element');
```

### When to Use

- ✅ Legacy systems already using Card Element
- ✅ Card-only checkout (no other payment methods needed)
- ✅ Need very specific card field layout
- ⚠️ Otherwise, migrate to Payment Element

---

## Method 3: Embedded Checkout

### Overview

**Embedded Checkout** is Stripe's prebuilt checkout form that embeds directly into your website as an iframe. It handles the entire payment UI without requiring custom code.

### Key Features

✅ **Fastest implementation** (no custom UI needed)
✅ **100+ payment methods** supported
✅ **Fully managed by Stripe** (automatic updates)
✅ **PCI SAQ A compliance**
✅ **Built-in address collection**
✅ **Tax calculation** (Stripe Tax integration)
⚠️ **Less customization** than Elements

### Architecture

```
┌────────────────────────────────────────────────────────────┐
│                    Your Website                             │
├────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │                                                       │  │
│  │  <div id="checkout">                                 │  │
│  │                                                       │  │
│  │    ┌─────────────────────────────────────────────┐   │  │
│  │    │ Stripe Embedded Checkout (iframe)           │   │  │
│  │    │                                              │   │  │
│  │    │  Order Summary: $99.99                      │   │  │
│  │    │                                              │   │  │
│  │    │  Email: [                ]                  │   │  │
│  │    │                                              │   │  │
│  │    │  Card: [                  ]                 │   │  │
│  │    │  [Exp] [CVC]                                │   │  │
│  │    │                                              │   │  │
│  │    │  Name: [                  ]                 │   │  │
│  │    │  Address: [               ]                 │   │  │
│  │    │                                              │   │  │
│  │    │  [Complete Payment]                         │   │  │
│  │    │                                              │   │  │
│  │    └─────────────────────────────────────────────┘   │  │
│  │                                                       │  │
│  │  </div>                                               │  │
│  │                                                       │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                             │
└────────────────────────────────────────────────────────────┘
```

### Implementation

#### Step 1: Create Checkout Session (Server)

```php
<?php
$stripe = new \Stripe\StripeClient('sk_test_YOUR_SECRET_KEY');

$checkout_session = $stripe->checkout->sessions->create([
    'ui_mode' => 'embedded',
    'line_items' => [[
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => 'T-shirt',
            ],
            'unit_amount' => 2000,
        ],
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'return_url' => 'https://example.com/checkout/return?session_id={CHECKOUT_SESSION_ID}',
]);

echo json_encode(['clientSecret' => $checkout_session->client_secret]);
```

#### Step 2: Initialize on Frontend

```html
<div id="checkout"></div>

<script src="https://js.stripe.com/v3/"></script>
<script>
const stripe = Stripe('pk_test_YOUR_KEY');

// Fetch client secret from server
fetch('/create-checkout-session', {
    method: 'POST',
})
.then(res => res.json())
.then(data => {
    const checkout = stripe.initEmbeddedCheckout({
        clientSecret: data.clientSecret,
    });

    checkout.mount('#checkout');
});
</script>
```

### Customization

Limited to appearance settings:

```javascript
const checkout = stripe.initEmbeddedCheckout({
    clientSecret: clientSecret,
    appearance: {
        theme: 'stripe', // or 'night', 'flat'
        variables: {
            colorPrimary: '#0570de'
        }
    }
});
```

### Pros & Cons

**Pros:**
- ✅ Fastest implementation (5 minutes)
- ✅ Fully managed UI (automatic updates)
- ✅ Address collection built-in
- ✅ Tax calculation available
- ✅ Order summary included

**Cons:**
- ⚠️ Limited customization
- ⚠️ Less control over layout
- ⚠️ Larger iframe footprint

### Best Use Cases

- ✅ MVP/prototype checkout
- ✅ Simple product sales
- ✅ Teams without frontend developers
- ✅ When speed to market is priority

---

## Method 4: Hosted Checkout (Stripe-Hosted Redirect)

### Overview

**Hosted Checkout** redirects customers to a Stripe-hosted payment page. This is the simplest integration method but provides the least control.

### Key Features

✅ **Simplest implementation** (5 lines of code)
✅ **Zero frontend maintenance**
✅ **PCI SAQ A compliance**
✅ **Stripe handles all UI/UX**
✅ **Automatic mobile optimization**
⚠️ **Customer leaves your site**
⚠️ **No customization**

### Flow

```
Your Site                      Stripe-Hosted Page           Your Site
┌──────────┐                  ┌──────────────────┐         ┌──────────┐
│          │                  │                  │         │          │
│ Checkout │  ──redirect──>  │ Payment Form     │  ──>   │ Success  │
│ Button   │                  │                  │         │ Page     │
│          │                  │ [Card Details]   │         │          │
└──────────┘                  │ [Submit]         │         └──────────┘
                              │                  │
                              └──────────────────┘
                              stripe.com/pay/...
```

### Implementation

#### Server-Side (Create Session)

```php
<?php
$stripe = new \Stripe\StripeClient('sk_test_YOUR_SECRET_KEY');

$checkout_session = $stripe->checkout->sessions->create([
    'line_items' => [[
        'price_data' => [
            'currency' => 'eur',
            'product_data' => [
                'name' => 'T-shirt',
            ],
            'unit_amount' => 2000,
        ],
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => 'https://example.com/success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => 'https://example.com/cancel',
]);

// Redirect to checkout URL
header("Location: " . $checkout_session->url);
exit;
```

#### Client-Side (Simple Button)

```html
<form action="/create-checkout-session" method="POST">
    <button type="submit">Checkout</button>
</form>
```

### Pros & Cons

**Pros:**
- ✅ Absolute simplest integration
- ✅ No frontend code required
- ✅ Stripe manages everything
- ✅ Mobile-optimized automatically
- ✅ Lowest development cost

**Cons:**
- ⚠️ Customer leaves your site
- ⚠️ No UI customization
- ⚠️ Branding limited to logo
- ⚠️ Can't integrate into existing flow

### Best Use Cases

- ✅ Quick proof of concept
- ✅ Small businesses with minimal dev resources
- ✅ Donation pages
- ✅ Simple product sales
- ⚠️ Not recommended for professional e-commerce

---

## Method 5: Direct API Integration (Not Recommended) ❌

### Overview

Sending card data directly to Stripe's API from your server. **Strongly discouraged** due to PCI compliance complexity.

### Why Not Recommended

❌ **PCI SAQ D compliance** - 50+ pages of requirements
❌ **Your server handles sensitive card data**
❌ **Increased security risk**
❌ **Annual security audits required**
❌ **Expensive compliance costs** ($10,000+/year)
❌ **Legal liability for data breaches**

### When It Might Be Used

- ⚠️ Call center / manual order entry (use Stripe Terminal instead)
- ⚠️ Backend subscription updates (use Payment Methods API)
- ⚠️ Legacy system migration (migrate to Elements ASAP)

### Alternative Solutions

Instead of direct API:
- ✅ **Stripe Terminal** - For in-person / call center
- ✅ **Payment Links** - For manual invoicing
- ✅ **Payment Element** - For online checkout

---

## PCI Compliance Comparison

| Method | SAQ Level | Form Length | Scope | Annual Requirements |
|--------|-----------|-------------|-------|---------------------|
| Payment Element | **SAQ A** | ~20 questions | Minimal | Self-assessment |
| Card Element | **SAQ A** | ~20 questions | Minimal | Self-assessment |
| Embedded Checkout | **SAQ A** | ~20 questions | Minimal | Self-assessment |
| Hosted Checkout | **SAQ A** | ~20 questions | Minimal | Self-assessment |
| Direct API | **SAQ D** | 50+ pages | Full network | Security audit |

### SAQ A Requirements (All iframe methods)

1. ✅ Serve payment pages over HTTPS
2. ✅ Use Stripe.js / Checkout (no direct card handling)
3. ✅ Keep systems patched
4. ✅ Use strong passwords
5. ✅ Complete annual self-assessment

**Total Cost:** $0-500/year (mostly time)

### SAQ D Requirements (Direct API)

1. ⚠️ Network segmentation
2. ⚠️ Firewall configuration
3. ⚠️ Vulnerability scanning
4. ⚠️ Penetration testing
5. ⚠️ Security policy documentation
6. ⚠️ Employee training
7. ⚠️ Quarterly security audits
8. ⚠️ Annual ASV scans

**Total Cost:** $10,000-50,000+/year

---

## Performance Comparison

### Load Time

| Method | Initial Load | Time to Interactive | Page Weight |
|--------|-------------|---------------------|-------------|
| Payment Element | ~150ms | ~200ms | ~45KB (gzipped) |
| Card Element | ~150ms | ~200ms | ~45KB (gzipped) |
| Embedded Checkout | ~200ms | ~300ms | ~60KB (gzipped) |
| Hosted Checkout | Redirect | N/A | 0KB (off-site) |

### Conversion Impact

| Method | Avg. Conversion Rate | Mobile Optimization | Payment Method Flexibility |
|--------|---------------------|---------------------|---------------------------|
| Payment Element | **+11.9%** ⭐ | Excellent | 100+ methods |
| Card Element | Baseline | Good | Card only |
| Embedded Checkout | +8-10% | Excellent | 100+ methods |
| Hosted Checkout | -5-10% | Excellent | 100+ methods |

*Note: Hosted Checkout typically has lower conversion due to redirect*

---

## Recommendation for OXID eShop

### For Standard Checkout Implementation

**Recommended: Payment Element (Embedded)**

**Reasoning:**
1. ✅ **Best conversion rates** (+11.9% vs card-only)
2. ✅ **Future-proof** - Automatically supports new payment methods
3. ✅ **PCI SAQ A** - Easiest compliance level
4. ✅ **High customization** - Match OXID theme
5. ✅ **Integrates seamlessly** into existing checkout flow
6. ✅ **Mobile-optimized** - Responsive design
7. ✅ **International** - 40+ languages, regional payment methods
8. ✅ **Strong TypeScript support** - Better developer experience

### Implementation Strategy

```
Phase 1: Basic Payment Element Integration
├─ Add Stripe.js to payment page
├─ Create PaymentIntent on server
├─ Mount Payment Element
└─ Handle form submission

Phase 2: Styling & UX
├─ Match OXID theme colors
├─ Add loading states
├─ Improve error messages
└─ Mobile testing

Phase 3: Advanced Features
├─ Add Express Checkout (Apple Pay, Google Pay)
├─ Implement saved payment methods
├─ Add postal code validation
└─ Setup webhook handling

Phase 4: Optimization
├─ A/B test layout options
├─ Monitor conversion rates
├─ Add fraud prevention rules
└─ Optimize for international markets
```

---

## Code Example: OXID Integration

### Template (payment_stripe.html.twig)

```twig
{# Stripe Payment Element for OXID eShop 7.0+ (Twig) #}
<div class="stripe-payment-wrapper">
    <form id="stripe-payment-form" method="post">

        {# Payment Element Container #}
        <div id="payment-element" class="stripe-payment-element"></div>

        {# Error Display #}
        <div id="payment-errors" class="stripe-errors" style="display: none;"></div>

        {# Submit Button #}
        <button type="submit" id="stripe-submit" class="btn btn-primary">
            <span id="button-text">{{ 'STRIPE_PAY_NOW'|translate }}</span>
            <span id="button-spinner" style="display: none;">
                <i class="fa fa-spinner fa-spin"></i>
            </span>
        </button>

    </form>
</div>

{# Load Stripe.js #}
<script src="https://js.stripe.com/v3/"></script>

<script>
// Configuration from OXID
const stripeConfig = {
    publishableKey: '{{ oViewConf.getStripePublishableKey() }}',
    clientSecret: '{{ clientSecret }}',
    returnUrl: '{{ oViewConf.getSslSelfLink() ~ "cl=order&fnc=stripeReturn" }}'
};

// Initialize Stripe
const stripe = Stripe(stripeConfig.publishableKey);

// Create Elements with OXID theme styling
const elements = stripe.elements({
    clientSecret: stripeConfig.clientSecret,
    appearance: {
        theme: 'stripe',
        variables: {
            colorPrimary: '#0570de', // OXID brand color
            colorBackground: '#ffffff',
            colorText: '#30313d',
            colorDanger: '#df1b41',
            fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
            spacingUnit: '4px',
            borderRadius: '4px'
        }
    }
});

// Create and mount Payment Element
const paymentElement = elements.create('payment');
paymentElement.mount('#payment-element');

// Handle form submission
const form = document.getElementById('stripe-payment-form');
const submitButton = document.getElementById('stripe-submit');
const buttonText = document.getElementById('button-text');
const buttonSpinner = document.getElementById('button-spinner');
const errorDiv = document.getElementById('payment-errors');

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    // Disable submit button
    submitButton.disabled = true;
    buttonText.style.display = 'none';
    buttonSpinner.style.display = 'inline-block';

    // Confirm payment
    const {error} = await stripe.confirmPayment({
        elements,
        confirmParams: {
            return_url: stripeConfig.returnUrl
        }
    });

    // Handle errors
    if (error) {
        errorDiv.textContent = error.message;
        errorDiv.style.display = 'block';

        submitButton.disabled = false;
        buttonText.style.display = 'inline-block';
        buttonSpinner.style.display = 'none';
    }
    // On success, customer is redirected to return_url
});
</script>

<style>
.stripe-payment-wrapper {
    max-width: 500px;
    margin: 20px auto;
}

.stripe-payment-element {
    margin-bottom: 20px;
}

.stripe-errors {
    color: #df1b41;
    padding: 10px;
    margin-bottom: 15px;
    background: #fff5f5;
    border: 1px solid #df1b41;
    border-radius: 4px;
}

#stripe-submit {
    width: 100%;
    padding: 12px;
    font-size: 16px;
    font-weight: 600;
}
</style>
```

**Twig Syntax Notes:**
- `{# comment #}` - Comments
- `{{ variable }}` - Output variables
- `{{ 'KEY'|translate }}` - Translation filter
- `{{ oViewConf.method() }}` - Method calls
- `{{ var ~ "string" }}` - String concatenation

### Controller (PaymentController.php)

```php
<?php

namespace OxidSolutionCatalysts\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\PaymentController as CorePaymentController;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory;

class PaymentController extends CorePaymentController
{
    private ModuleConfigurationService $stripeConfig;
    private PaymentAdapterFactory $adapterFactory;

    public function __construct(
        ModuleConfigurationService $stripeConfig,
        PaymentAdapterFactory $adapterFactory
    ) {
        parent::__construct();
        $this->stripeConfig = $stripeConfig;
        $this->adapterFactory = $adapterFactory;
    }

    /**
     * Create PaymentIntent when Stripe payment is selected
     */
    public function render()
    {
        $template = parent::render();

        // Check if Stripe payment method is selected
        $paymentId = $this->getSession()->getVariable('paymentid');

        if ($paymentId === 'osc_stripe_card') {
            try {
                $basket = $this->getSession()->getBasket();
                $user = $this->getUser();

                // Create PaymentIntent
                $paymentIntent = $this->stripeService->createPaymentIntent($basket, $user);

                // Pass client_secret to template
                $this->addTplParam('clientSecret', $paymentIntent['client_secret']);
                $this->addTplParam('paymentIntentId', $paymentIntent['id']);

            } catch (\Exception $e) {
                $this->addTplParam('stripeError', $e->getMessage());
            }
        }

        return $template;
    }
}
```

---

## Security Best Practices

### Do's ✅

1. ✅ **Always use HTTPS** for payment pages
2. ✅ **Use Stripe.js** (never collect card data directly)
3. ✅ **Validate on server-side** (never trust client)
4. ✅ **Implement CSRF protection**
5. ✅ **Use webhook signature verification**
6. ✅ **Log failed payment attempts**
7. ✅ **Implement rate limiting**
8. ✅ **Use Stripe Radar** for fraud prevention
9. ✅ **Store minimal customer data**
10. ✅ **Keep Stripe SDK updated**

### Don'ts ❌

1. ❌ **Never store card numbers**
2. ❌ **Never log card data**
3. ❌ **Never send card data to your server**
4. ❌ **Never use GET for payment forms**
5. ❌ **Never skip webhook signature verification**
6. ❌ **Never expose secret keys in frontend**
7. ❌ **Never trust client-side validation alone**
8. ❌ **Never disable HTTPS (even in dev)**

---

## Migration Guide

### From Card Element → Payment Element

```javascript
// OLD: Card Element
const cardElement = elements.create('card');
cardElement.mount('#card-element');

stripe.createPaymentMethod({
    type: 'card',
    card: cardElement
});

// NEW: Payment Element
const paymentElement = elements.create('payment');
paymentElement.mount('#payment-element');

stripe.confirmPayment({
    elements,
    confirmParams: {
        return_url: 'https://...'
    }
});
```

**Breaking Changes:**
- `createPaymentMethod()` → `confirmPayment()`
- Manual PaymentIntent confirmation → Automatic
- Separate billing fields → Integrated

**Benefits:**
- ✅ 11.9% conversion increase
- ✅ Multiple payment methods
- ✅ Better mobile UX

---

## Testing

### Test Cards

| Card Number | Description | Expected Result |
|-------------|-------------|-----------------|
| `4242 4242 4242 4242` | Visa | Success |
| `4000 0025 0000 3155` | Visa | 3D Secure required |
| `4000 0000 0000 9995` | Visa | Declined (insufficient funds) |
| `4000 0000 0000 0002` | Visa | Declined (generic) |
| `4000 0000 0000 0341` | Visa | Attach to customer fails |

**Complete list:** https://stripe.com/docs/testing

### Test 3D Secure

```javascript
// 3DS always triggered
4000 0025 0000 3155

// 3DS authentication required
4000 0027 6000 3184

// 3DS authentication failed
4000 0000 0000 0341
```

---

## Performance Optimization

### Lazy Load Stripe.js

```javascript
// Load Stripe.js only when needed
function loadStripe() {
    return new Promise((resolve) => {
        if (window.Stripe) {
            resolve(window.Stripe);
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.onload = () => resolve(window.Stripe);
        document.head.appendChild(script);
    });
}

// Use when payment method selected
document.querySelector('[value="stripe"]').addEventListener('change', async () => {
    const Stripe = await loadStripe();
    const stripe = Stripe('pk_test_...');
    // Initialize elements
});
```

### Prefetch DNS

```html
<!-- In <head> -->
<link rel="dns-prefetch" href="https://js.stripe.com">
<link rel="dns-prefetch" href="https://api.stripe.com">
```

---

## Troubleshooting

### Common Issues

**Issue:** "Stripe is not defined"
```javascript
// Ensure Stripe.js is loaded
<script src="https://js.stripe.com/v3/"></script>
```

**Issue:** Elements not mounting
```javascript
// Check container exists before mounting
const container = document.getElementById('payment-element');
if (container) {
    paymentElement.mount('#payment-element');
}
```

**Issue:** 3D Secure modal blocked
```javascript
// Ensure popup blockers are disabled
// Call confirmPayment directly in event handler (not async)
```

**Issue:** CORS errors
```
// Ensure your domain is added to Stripe Dashboard
// Settings → Payment methods → Domain whitelist
```

---

## Resources

### Official Documentation
- **Payment Element:** https://stripe.com/docs/payments/payment-element
- **Elements Reference:** https://stripe.com/docs/js/elements
- **Embedded Checkout:** https://stripe.com/docs/checkout/embedded/quickstart
- **Hosted Checkout:** https://stripe.com/docs/payments/checkout

### Tools
- **Stripe CLI:** https://stripe.com/docs/stripe-cli
- **Stripe Dashboard:** https://dashboard.stripe.com
- **Test Cards:** https://stripe.com/docs/testing

### Support
- **Stripe Support:** https://support.stripe.com
- **Community Forum:** https://github.com/stripe/stripe-js/discussions
- **Status Page:** https://status.stripe.com

---

## Summary & Decision Matrix

| Need | Recommended Method | Reason |
|------|-------------------|--------|
| **New OXID integration** | Payment Element | Best conversion, future-proof |
| **Card-only checkout** | Payment Element (filtered) | Still better than Card Element |
| **Quick MVP** | Embedded Checkout | Fastest setup |
| **Minimal dev resources** | Hosted Checkout | Zero frontend code |
| **Legacy migration** | Payment Element | Modern replacement |
| **International business** | Payment Element | Regional payment methods |
| **Mobile-first** | Payment Element | Best mobile UX |
| **Subscriptions** | Payment Element | Saved payment methods |

---

**Recommendation for OXID Standard Checkout:** Use **Payment Element** with embedded integration for maximum conversion, future payment method support, and easiest PCI compliance.

---

**Last Updated:** 2025-01-14
**Version:** 1.0.0
**Author:** Based on Stripe Documentation 2025
