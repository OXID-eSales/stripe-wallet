# Stripe Card Payment Implementation

## Overview

This document describes the simple Stripe card payment implementation following the [Stripe Payment Element documentation](https://docs.stripe.com/payments/accept-a-payment?ui=elements&client=html).

## Implementation Components

### 1. Backend (PHP) - OrderController.php

**File**: `src/Stripe/Controller/OrderController.php`

**Key Methods**:
- `render()` (lines 54-152): Creates a PaymentIntent on the order page and passes the `clientSecret` to the template
- `execute()` (lines 160-169): Routes Stripe payments to the Stripe-specific execution flow
- `executeStripePayment()` (lines 177-244): Handles the payment confirmation after Stripe redirect
- `stripeReturn()` (lines 713-769): Handles the return callback from Stripe after payment confirmation

**Flow**:
1. When user reaches order page, a PaymentIntent is created
2. The `clientSecret` is passed to the frontend
3. After payment confirmation, user is redirected back to `stripeReturn()`
4. Order is created upon successful payment

### 2. Frontend Template - order.html.twig

**File**: `views/twig/extensions/themes/default/page/checkout/order.html.twig`

**Payment Form Section** (lines 39-90):
- Displays the Stripe Payment Element container
- Uses Stimulus controller `data-controller="stripe-order"`
- Shows loading indicator and error messages

**Order Confirmation Button** (lines 94-131):
- Custom submit form for Stripe payments
- Intercepts form submission with `data-action="submit->stripe-order#handlePayment"`
- Includes terms & conditions checkbox
- Shows processing indicator during payment

### 3. JavaScript Controller - stripe_order_controller.js

**File**: `resources/build/js/controllers/stripe_order_controller.js`

**Key Methods**:
- `connect()` (line 26): Validates configuration and initializes Stripe
- `initializeStripe()` (line 78): Creates and mounts the Payment Element
- `handlePayment()` (line 214): Handles order form submission and confirms payment with Stripe

**Flow**:
1. Controller connects and validates publishable key and client secret
2. Initializes Stripe with Payment Element (card input form)
3. When user clicks "Submit Order", `handlePayment()` is called
4. Calls `stripe.confirmPayment()` which redirects to Stripe for 3D Secure if needed
5. User is redirected back to shop after payment confirmation

### 4. Stripe.js Library

**Loaded in**: `views/twig/frontend/base_js.html.twig` (line 2)

```html
<script src="https://js.stripe.com/v3/"></script>
```

## Payment Flow

```
1. User reaches Order Page
   └─> OrderController::render() creates PaymentIntent
       └─> Passes clientSecret to template

2. Template loads
   └─> Stripe Payment Element initialized
       └─> Card input form displayed

3. User enters card details and clicks "Submit Order"
   └─> stripe_order_controller::handlePayment() called
       └─> stripe.confirmPayment() called
           ├─> Success: Redirects to stripeReturn()
           ├─> 3DS Required: Redirects to Stripe auth page
           └─> Error: Shows error message

4. Return to shop (stripeReturn)
   └─> OrderController::stripeReturn() checks payment status
       └─> executeStripePayment() handles payment
           ├─> Success: Creates order via finalizeOrder()
           └─> Failure: Returns to payment page

5. Order Created
   └─> Redirect to Thank You page
```

## Key Configuration

### Payment Method ID
The payment method must be identified as `osc_stripe_card` in:
- OrderController line 409: `isStripePayment()` check
- order.html.twig line 39 & 96: Template conditionals

### Return URL
Configured in `stripe_order_controller.js` line 228:
```javascript
const returnUrl = shopUrl + '/index.php?cl=order&fnc=stripeReturn'
```

This URL is handled by `OrderController::stripeReturn()` (line 713).

## Testing

### Test Card Numbers
Use Stripe test cards for development:
- **Success**: `4242 4242 4242 4242`
- **3D Secure**: `4000 0027 6000 3184`
- **Decline**: `4000 0000 0000 0002`

### Test Mode
Ensure test mode is enabled in module configuration:
- Use test publishable key (starts with `pk_test_`)
- Use test secret key (starts with `sk_test_`)

## Build Process

After modifying JavaScript files, run:

```bash
npm run build:dev    # Development build with sourcemaps
npm run build:prod   # Production build (minified)
```

## Future Enhancements

As mentioned by the user, other payment methods (Apple Pay, Google Pay, etc.) will be handled in future iterations. The current implementation focuses on simple card payments only.

## Troubleshooting

### Payment Form Not Showing
- Check browser console for errors
- Verify Stripe publishable key is configured
- Ensure payment method is `osc_stripe_card`
- Check PHP logs for PaymentIntent creation errors

### Payment Not Processing
- Check browser console for JavaScript errors
- Verify return URL is accessible
- Check PHP logs in OrderController::stripeReturn()
- Ensure Stripe webhook is configured (for async events)

## References

- [Stripe Payment Element Documentation](https://docs.stripe.com/payments/accept-a-payment?ui=elements&client=html)
- [Stripe.js Reference](https://stripe.com/docs/js)
- [Stimulus Framework](https://stimulus.hotwired.dev/)
