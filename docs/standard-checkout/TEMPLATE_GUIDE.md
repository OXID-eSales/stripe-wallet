# Template Integration Guide

**Frontend Implementation with Stripe.js**
**Version:** 1.0.0
**Date:** 2025-11-13

---

## Overview

This guide covers the frontend implementation of Stripe payments in OXID templates using Stripe.js for secure card handling.

---

## Template Architecture

```
OXID Templates
      │
      ├── page/checkout/payment.tpl (OXID core)
      │        │
      │        └── Block: select_payment
      │                  │
      │                  └── payment_stripe_method.tpl (your block)
      │                           - Display Stripe option
      │                           - Load Stripe.js
      │                           - Create Card Element
      │
      └── page/checkout/order.tpl (OXID core)
               │
               └── Your modifications
                        - Handle payment confirmation
                        - Display loading state
```

---

## Payment Method Block

Add Stripe as a payment option on the payment selection page.

### File: `views/blocks/payment_stripe_method.tpl`

```smarty
[{* Stripe payment method block *}]
[{$smarty.block.parent}]

[{if $oViewConf->getActiveClassName() == 'payment'}]
    [{assign var="payment" value=$oView->getPayment()}]

    [{if $payment && $payment->oxpayments__oxid->value == 'osc_stripe_card'}]
        <div class="stripe-payment-container" id="stripe-payment-form">
            <div class="payment-description">
                <p>[{oxmultilang ident="OSC_STRIPE_PAYMENT_DESC"}]</p>
            </div>

            [{* Stripe Card Element Container *}]
            <div class="form-group">
                <label for="stripe-card-element">
                    [{oxmultilang ident="OSC_STRIPE_CARD_DETAILS"}]
                </label>
                <div id="stripe-card-element" class="stripe-card-element">
                    <!-- Stripe.js will inject card input here -->
                </div>
                <div id="stripe-card-errors" role="alert" class="alert alert-danger" style="display:none;">
                    <!-- Display card errors here -->
                </div>
            </div>

            [{* Hidden field for payment method ID *}]
            <input type="hidden" id="stripe-payment-method-id" name="stripe_payment_method_id" value="">
            <input type="hidden" id="stripe-payment-intent-id" name="stripe_payment_intent_id" value="">

            [{* Load Stripe.js *}]
            <script src="https://js.stripe.com/v3/"></script>
            <script>
                // Pass PHP variables to JavaScript
                window.stripeConfig = {
                    publicKey: '[{$stripePublicKey}]',
                    testMode: [{if $stripeTestMode}]true[{else}]false[{/if}],
                    amount: [{$oxcmp_basket->getPrice()->getBruttoPrice()}],
                    currency: '[{$oxcmp_basket->getBasketCurrency()->name}]'
                };
            </script>
            <script src="[{$oViewConf->getModuleUrl('osc_stripe', 'out/js/stripe_payment.js')}]"></script>
        </div>
    [{/if}]
[{/if}]
```

---

## Stripe.js Implementation

Client-side JavaScript for handling card input and payment confirmation.

### File: `views/js/stripe_payment.js`

```javascript
/**
 * Stripe Payment Handler
 * Handles card input, payment method creation, and 3D Secure
 */
(function() {
    'use strict';

    // Check if Stripe config is available
    if (typeof window.stripeConfig === 'undefined') {
        console.error('Stripe configuration not found');
        return;
    }

    // Initialize Stripe
    const stripe = Stripe(window.stripeConfig.publicKey);

    // Create Elements instance
    const elements = stripe.elements();

    // Custom styling for Card Element
    const style = {
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
    };

    // Create Card Element
    const cardElement = elements.create('card', {style: style});

    // Mount Card Element when page loads
    document.addEventListener('DOMContentLoaded', function() {
        const cardElementDiv = document.getElementById('stripe-card-element');

        if (cardElementDiv) {
            cardElement.mount('#stripe-card-element');

            // Handle real-time validation errors
            cardElement.on('change', function(event) {
                displayError(event);
            });

            // Intercept form submission
            setupFormHandler();
        }
    });

    /**
     * Setup form submission handler
     */
    function setupFormHandler() {
        const form = document.getElementById('payment');

        if (!form) {
            return;
        }

        // Find the "Continue" button
        const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');

        if (submitButton) {
            submitButton.addEventListener('click', async function(event) {
                // Check if Stripe payment is selected
                const stripePaymentRadio = document.querySelector('input[name="paymentid"][value="osc_stripe_card"]');

                if (stripePaymentRadio && stripePaymentRadio.checked) {
                    event.preventDefault();
                    await handleStripePayment(form, submitButton);
                }
            });
        }
    }

    /**
     * Handle Stripe payment submission
     */
    async function handleStripePayment(form, submitButton) {
        // Disable submit button to prevent double submission
        const originalButtonText = submitButton.textContent || submitButton.value;
        submitButton.disabled = true;
        submitButton.textContent = 'Processing...';

        try {
            // Create payment method
            const {paymentMethod, error} = await stripe.createPaymentMethod({
                type: 'card',
                card: cardElement,
                billing_details: getBillingDetails()
            });

            if (error) {
                throw new Error(error.message);
            }

            // Store payment method ID in hidden field
            document.getElementById('stripe-payment-method-id').value = paymentMethod.id;

            // Create PaymentIntent on server
            const paymentIntent = await createPaymentIntent();

            if (paymentIntent.error) {
                throw new Error(paymentIntent.error);
            }

            // Confirm payment with the PaymentIntent
            const confirmResult = await stripe.confirmCardPayment(
                paymentIntent.clientSecret,
                {
                    payment_method: paymentMethod.id
                }
            );

            if (confirmResult.error) {
                throw new Error(confirmResult.error.message);
            }

            // Check if payment requires action (3D Secure)
            if (confirmResult.paymentIntent.status === 'requires_action') {
                // Handle 3D Secure authentication
                await handle3DSecure(confirmResult.paymentIntent);
            }

            // Payment successful, store PaymentIntent ID
            document.getElementById('stripe-payment-intent-id').value = confirmResult.paymentIntent.id;

            // Submit form to proceed to order page
            form.submit();

        } catch (error) {
            // Show error to user
            displayError({error: {message: error.message}});

            // Re-enable submit button
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
    }

    /**
     * Create PaymentIntent on server via AJAX
     */
    async function createPaymentIntent() {
        const response = await fetch('?cl=payment&fnc=createPaymentIntent', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                amount: window.stripeConfig.amount,
                currency: window.stripeConfig.currency
            })
        });

        if (!response.ok) {
            throw new Error('Server error: ' + response.status);
        }

        return await response.json();
    }

    /**
     * Handle 3D Secure authentication
     */
    async function handle3DSecure(paymentIntent) {
        // Stripe.js will automatically handle 3DS redirect
        // This method can be extended if needed
        console.log('3D Secure authentication required');
    }

    /**
     * Get billing details from form
     */
    function getBillingDetails() {
        // Get user data from OXID form fields
        const firstName = document.querySelector('input[name="invadr[oxuser__oxfname]"]');
        const lastName = document.querySelector('input[name="invadr[oxuser__oxlname]"]');
        const email = document.querySelector('input[name="invadr[oxuser__oxusername]"]');
        const street = document.querySelector('input[name="invadr[oxuser__oxstreet]"]');
        const city = document.querySelector('input[name="invadr[oxuser__oxcity]"]');
        const zip = document.querySelector('input[name="invadr[oxuser__oxzip]"]');
        const country = document.querySelector('select[name="invadr[oxuser__oxcountryid]"]');

        return {
            name: (firstName?.value || '') + ' ' + (lastName?.value || ''),
            email: email?.value || '',
            address: {
                line1: street?.value || '',
                city: city?.value || '',
                postal_code: zip?.value || '',
                country: country?.value || ''
            }
        };
    }

    /**
     * Display error message
     */
    function displayError(event) {
        const errorDiv = document.getElementById('stripe-card-errors');

        if (event.error) {
            errorDiv.textContent = event.error.message;
            errorDiv.style.display = 'block';
        } else {
            errorDiv.textContent = '';
            errorDiv.style.display = 'none';
        }
    }
})();
```

---

## Order Review Page

Modify the order review page to handle payment confirmation.

### File: `views/tpl/page/checkout/order_stripe.tpl`

```smarty
[{* Stripe-specific order page modifications *}]
[{include file="layout/page.tpl"}]

[{block name="content"}]
    <div class="order-review-container">
        <h1>[{oxmultilang ident="ORDER_REVIEW"}]</h1>

        [{* Display order summary *}]
        <div class="order-summary">
            <h2>[{oxmultilang ident="YOUR_ORDER"}]</h2>

            [{* Basket items *}]
            <div class="basket-items">
                [{foreach from=$oxcmp_basket->getContents() item=basketitem}]
                    <div class="basket-item">
                        <span class="item-title">[{$basketitem->getTitle()}]</span>
                        <span class="item-amount">[{$basketitem->getAmount()}]x</span>
                        <span class="item-price">[{$basketitem->getPrice()}]</span>
                    </div>
                [{/foreach}]
            </div>

            [{* Totals *}]
            <div class="order-totals">
                <div class="total-line">
                    <span>[{oxmultilang ident="SUBTOTAL"}]:</span>
                    <span>[{$oxcmp_basket->getProductsNetPrice()}]</span>
                </div>
                <div class="total-line">
                    <span>[{oxmultilang ident="VAT"}]:</span>
                    <span>[{$oxcmp_basket->getVATValue()}]</span>
                </div>
                <div class="total-line grand-total">
                    <span>[{oxmultilang ident="TOTAL"}]:</span>
                    <span>[{$oxcmp_basket->getPrice()->getBruttoPrice()}]</span>
                </div>
            </div>
        </div>

        [{* Payment information *}]
        <div class="payment-info">
            <h3>[{oxmultilang ident="PAYMENT_METHOD"}]</h3>
            <p>[{oxmultilang ident="OSC_STRIPE_CARD_PAYMENT"}]</p>
        </div>

        [{* Submit order form *}]
        <form id="orderConfirmForm" method="post" action="[{$oViewConf->getSslSelfLink()}]">
            [{$oViewConf->getHiddenSid()}]
            <input type="hidden" name="cl" value="order">
            <input type="hidden" name="fnc" value="execute">
            <input type="hidden" name="sDeliveryAddressMD5" value="[{$oView->getDeliveryAddressMD5()}]">
            <input type="hidden" id="payment_intent_id" name="payment_intent_id" value="">

            [{* Terms and conditions *}]
            <div class="form-group">
                <label>
                    <input type="checkbox" name="ord_agb" value="1" required>
                    [{oxmultilang ident="I_ACCEPT_TERMS"}]
                    <a href="[{$oViewConf->getTermsLink()}]" target="_blank">
                        [{oxmultilang ident="TERMS_AND_CONDITIONS"}]
                    </a>
                </label>
            </div>

            <button type="submit" id="submitOrderButton" class="btn btn-primary">
                [{oxmultilang ident="PLACE_ORDER"}]
            </button>

            <div id="payment-processing" class="alert alert-info" style="display:none;">
                <div class="spinner"></div>
                <p>[{oxmultilang ident="OSC_STRIPE_PROCESSING"}]</p>
            </div>

            <div id="payment-error" class="alert alert-danger" style="display:none;">
                <!-- Error messages -->
            </div>
        </form>
    </div>

    <script>
        // Handle order submission
        document.getElementById('orderConfirmForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const submitButton = document.getElementById('submitOrderButton');
            const processingDiv = document.getElementById('payment-processing');

            // Show processing indicator
            submitButton.disabled = true;
            processingDiv.style.display = 'block';

            // Get payment intent ID from session/payment page
            const paymentIntentId = '[{$smarty.session.stripe_payment_intent_id}]';

            if (paymentIntentId) {
                document.getElementById('payment_intent_id').value = paymentIntentId;
                this.submit();
            } else {
                // No payment intent found
                showError('Payment information missing. Please go back to payment page.');
                submitButton.disabled = false;
                processingDiv.style.display = 'none';
            }
        });

        function showError(message) {
            const errorDiv = document.getElementById('payment-error');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
        }
    </script>
[{/block}]
```

---

## 3D Secure Authentication Page

Special page for handling 3D Secure redirects.

### File: `views/tpl/page/checkout/stripe_3ds.tpl`

```smarty
[{* 3D Secure authentication page *}]
[{include file="layout/page.tpl"}]

[{block name="content"}]
    <div class="stripe-3ds-container">
        <h1>[{oxmultilang ident="OSC_STRIPE_3DS_TITLE"}]</h1>

        <div class="alert alert-info">
            <p>[{oxmultilang ident="OSC_STRIPE_3DS_INFO"}]</p>
        </div>

        <div id="stripe-3ds-element">
            <!-- 3D Secure iframe will be loaded here -->
        </div>

        <div id="stripe-3ds-processing" class="text-center">
            <div class="spinner"></div>
            <p>[{oxmultilang ident="OSC_STRIPE_AUTHENTICATING"}]</p>
        </div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe('[{$stripePublicKey}]');
        const clientSecret = '[{$stripeClientSecret}]';

        // Handle 3D Secure authentication
        stripe.handleCardAction(clientSecret).then(function(result) {
            if (result.error) {
                // Show error
                alert(result.error.message);
                window.location.href = '[{$oViewConf->getSelfLink()}]cl=payment';
            } else {
                // Authentication complete, return to order controller
                window.location.href = '[{$oViewConf->getSelfLink()}]cl=order&fnc=return3DS';
            }
        });
    </script>
[{/block}]
```

---

## CSS Styling

Add CSS for Stripe elements.

### File: `views/css/stripe.css`

```css
/* Stripe Payment Form Styles */

.stripe-payment-container {
    margin: 20px 0;
    padding: 20px;
    background: #f9f9f9;
    border-radius: 8px;
}

.stripe-card-element {
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 4px;
    background: white;
    transition: border-color 0.2s;
}

.stripe-card-element:focus-within {
    border-color: #5469d4;
    box-shadow: 0 0 0 3px rgba(84, 105, 212, 0.1);
}

.stripe-card-element.StripeElement--invalid {
    border-color: #fa755a;
}

#stripe-card-errors {
    margin-top: 10px;
    padding: 10px;
    font-size: 14px;
}

/* Processing State */
#payment-processing {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    margin-top: 15px;
}

.spinner {
    border: 3px solid #f3f3f3;
    border-top: 3px solid #5469d4;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* 3D Secure Container */
.stripe-3ds-container {
    max-width: 600px;
    margin: 40px auto;
    text-align: center;
}

#stripe-3ds-element {
    min-height: 400px;
    margin: 20px 0;
}

/* Responsive Design */
@media (max-width: 768px) {
    .stripe-payment-container {
        padding: 15px;
    }

    .stripe-card-element {
        padding: 10px;
    }
}
```

---

## Language Files

Add translation keys for Stripe messages.

### File: `translations/en/stripe_lang.php`

```php
<?php

$aLang = [
    'charset' => 'UTF-8',

    'OSC_STRIPE_PAYMENT_DESC' => 'Pay securely with your credit or debit card via Stripe',
    'OSC_STRIPE_CARD_DETAILS' => 'Card Details',
    'OSC_STRIPE_CARD_PAYMENT' => 'Credit Card (Stripe)',
    'OSC_STRIPE_PROCESSING' => 'Processing your payment...',
    'OSC_STRIPE_3DS_TITLE' => 'Card Authentication Required',
    'OSC_STRIPE_3DS_INFO' => 'Your bank requires additional authentication for this payment.',
    'OSC_STRIPE_AUTHENTICATING' => 'Authenticating your card...',
];
```

### File: `translations/de/stripe_lang.php`

```php
<?php

$aLang = [
    'charset' => 'UTF-8',

    'OSC_STRIPE_PAYMENT_DESC' => 'Bezahlen Sie sicher mit Ihrer Kredit- oder Debitkarte über Stripe',
    'OSC_STRIPE_CARD_DETAILS' => 'Kartendetails',
    'OSC_STRIPE_CARD_PAYMENT' => 'Kreditkarte (Stripe)',
    'OSC_STRIPE_PROCESSING' => 'Ihre Zahlung wird verarbeitet...',
    'OSC_STRIPE_3DS_TITLE' => 'Kartenauthentifizierung erforderlich',
    'OSC_STRIPE_3DS_INFO' => 'Ihre Bank benötigt eine zusätzliche Authentifizierung für diese Zahlung.',
    'OSC_STRIPE_AUTHENTICATING' => 'Karte wird authentifiziert...',
];
```

---

## Testing

### Test Cards for Frontend Testing

```javascript
// Success (no 3DS)
Card: 4242 4242 4242 4242
Exp: Any future date
CVC: Any 3 digits

// Success (with 3DS)
Card: 4000 0027 6000 3184
Exp: Any future date
CVC: Any 3 digits

// Declined
Card: 4000 0000 0000 0002
Exp: Any future date
CVC: Any 3 digits

// Insufficient funds
Card: 4000 0000 0000 9995
Exp: Any future date
CVC: Any 3 digits
```

---

## Next Steps

1. Read [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md) for webhook implementation
2. Read [ERROR_HANDLING.md](ERROR_HANDLING.md) for error scenarios
3. Read [TESTING_GUIDE.md](TESTING_GUIDE.md) for comprehensive testing

