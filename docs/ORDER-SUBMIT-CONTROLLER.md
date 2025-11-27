# Order Submit Controller - Stimulus.js

**Date:** 2025-11-19
**Controller:** `order-submit`
**Purpose:** Handle order submission on checkout order page

---

## Overview

The **Order Submit Controller** is a Stimulus.js controller that handles the order submission button click on the checkout order page. Currently, it shows an `alert(123)` for testing purposes, but it's designed to be extended with actual order submission logic.

---

## Implementation

### Controller File

**Location:** `resources/build/js/controllers/order_submit_controller.js`

```javascript
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
  connect() {
    console.log('Order Submit controller connected')
  }

  handleSubmit(event) {
    event.preventDefault()

    console.log('Order submit button clicked')

    // Show alert for testing
    alert(123)

    // TODO: Add actual order submission logic here
  }
}
```

---

## Usage in Template

**Location:** `views/twig/extensions/themes/default/page/checkout/order.html.twig`

```twig
{% block checkout_order_next_step_side %}
    <button id="{{ paymentId }}"
            type="button"
            class="btn btn-highlight btn-lg w-100"
            data-controller="order-submit"
            data-action="click->order-submit#handleSubmit">
        {{ translate({ ident: "SUBMIT_ORDER" }) }}
    </button>
{% endblock %}
```

---

## Stimulus Data Attributes Explained

### `data-controller="order-submit"`

Connects the button element to the `order-submit` Stimulus controller. When the element is added to the DOM, Stimulus automatically:
1. Finds the registered `order-submit` controller
2. Creates an instance of the controller
3. Calls the `connect()` method

### `data-action="click->order-submit#handleSubmit"`

Defines the action to execute when the button is clicked:
- **`click`** - The browser event to listen for
- **`order-submit`** - The controller name
- **`handleSubmit`** - The controller method to call

**Syntax:** `event->controller#method`

---

## Current Behavior

When the order submit button is clicked:

1. ✅ **Event is prevented** - `event.preventDefault()` stops default form submission
2. ✅ **Console log** - Logs button click to browser console
3. ✅ **Alert shown** - Shows `alert(123)` for testing
4. ⏳ **TODO** - Actual order submission logic to be added

---

## Testing

### 1. Open Browser Console

Press `F12` or right-click → "Inspect" → "Console" tab

### 2. Load Checkout Order Page

Navigate to: `/index.php?cl=order`

### 3. Verify Controller Connected

You should see in console:
```
Stripe Module: JavaScript loaded and ready
Order Submit controller connected
Button element: <button id="osc_stripe_card" ...>
```

### 4. Click Submit Button

When you click the "Submit Order" button:
- ✅ Alert shows: `123`
- ✅ Console logs: `Order submit button clicked`

---

## Building the JavaScript

After making changes to the controller, rebuild the JavaScript bundle:

```bash
# Development build (includes source maps)
node resources/build.js development

# Production build (minified)
node resources/build.js production

# Watch mode (auto-rebuild on changes)
node resources/build.js watch
```

The compiled JavaScript will be bundled into:
- `assets/js/stripe-frontend.js` (production)
- `assets/js/stripe-frontend.dev.js` (development)

---

## Next Steps - Implementing Real Order Submission

### 1. Add Stimulus Values

Pass order data to the controller via data attributes:

```twig
<button data-controller="order-submit"
        data-action="click->order-submit#handleSubmit"
        data-order-submit-order-id-value="{{ oView.getOrderId() }}"
        data-order-submit-total-value="{{ oView.getBasketPrice() }}"
        data-order-submit-payment-id-value="{{ paymentId }}">
    Submit Order
</button>
```

In controller:
```javascript
export default class extends Controller {
  static values = {
    orderId: String,
    total: Number,
    paymentId: String
  }

  handleSubmit(event) {
    event.preventDefault()

    console.log('Order data:', {
      orderId: this.orderIdValue,
      total: this.totalValue,
      paymentId: this.paymentIdValue
    })

    // Submit order...
  }
}
```

---

### 2. Add Loading State

Show loading indicator while processing:

```javascript
handleSubmit(event) {
  event.preventDefault()

  this.showLoading()

  // Submit order via AJAX
  fetch('/order/submit', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ orderId: this.orderIdValue })
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      window.location = data.successUrl
    } else {
      this.showError(data.error)
    }
  })
  .catch(error => {
    this.showError('Order submission failed')
  })
  .finally(() => {
    this.hideLoading()
  })
}

showLoading() {
  this.element.disabled = true
  this.element.textContent = 'Processing...'
}

hideLoading() {
  this.element.disabled = false
  this.element.textContent = this.originalText
}
```

---

### 3. Add Stripe Payment Intent Confirmation

For Stripe payments, confirm payment before submitting order:

```javascript
async handleSubmit(event) {
  event.preventDefault()

  if (this.hasStripePaymentValue) {
    // Confirm Stripe payment first
    const result = await this.confirmStripePayment()

    if (result.error) {
      this.showError(result.error.message)
      return
    }
  }

  // Submit order to backend
  this.submitOrder()
}

async confirmStripePayment() {
  const stripe = Stripe(this.stripePublishableKeyValue)

  return await stripe.confirmPayment({
    elements: this.elements,
    confirmParams: {
      return_url: this.returnUrlValue
    }
  })
}
```

---

### 4. Add Validation

Validate form before submission:

```javascript
handleSubmit(event) {
  event.preventDefault()

  if (!this.validateForm()) {
    return
  }

  // Proceed with submission...
}

validateForm() {
  // Check if terms & conditions accepted
  if (!this.termsAcceptedValue) {
    this.showError('Please accept terms and conditions')
    return false
  }

  // Check if payment method selected
  if (!this.paymentIdValue) {
    this.showError('Please select a payment method')
    return false
  }

  return true
}
```

---

## Debugging

### Enable Stimulus Debug Mode

In `resources/build/js/app.js`:

```javascript
if (process.env.NODE_ENV === 'development') {
  Stimulus.debug = true  // Already enabled
  console.log('Stimulus controllers:', Stimulus.router.modulesByIdentifier)
}
```

### Check Controller Registration

In browser console:
```javascript
// Check if controller is registered
window.Stimulus.router.modulesByIdentifier

// Should include:
// Map {
//   "order-submit" => {controller: OrderSubmitController, ...}
// }
```

### Inspect Controller Instance

```javascript
// Get controller instance from element
const button = document.querySelector('[data-controller="order-submit"]')
const controller = button[Object.keys(button).find(key => key.startsWith('__stimulus_'))]

console.log('Controller:', controller)
console.log('Values:', controller.values)
```

---

## Architecture

### Stimulus.js Concepts

**Controllers** - JavaScript classes that connect to HTML elements
**Actions** - Event handlers defined in HTML via `data-action`
**Targets** - Named elements within the controller's scope
**Values** - Typed data passed from HTML to JavaScript

### Benefits

✅ **Separation of Concerns** - HTML defines behavior, JS implements it
✅ **Reusable** - Controllers can be used on multiple elements
✅ **Progressive Enhancement** - Works without JavaScript (graceful degradation)
✅ **Testable** - Controllers are plain JavaScript classes
✅ **No Build Step Required** - Can work with CDN (though we use esbuild)

---

## File Structure

```
stripe-wallet/
├── resources/build/js/
│   ├── app.js                              # Stimulus application setup
│   └── controllers/
│       ├── order_submit_controller.js      # Order submit controller (source)
│       ├── stripe_order_controller.js      # Stripe payment controller
│       └── buy_now_controller.js          # Buy now controller
├── assets/js/
│   └── stripe-frontend.js                 # Compiled bundle (contains all controllers)
└── views/twig/extensions/themes/default/page/checkout/
    └── order.html.twig                    # Template using controller
```

---

## References

### Internal

- **Controller Source:** `resources/build/js/controllers/order_submit_controller.js`
- **Template:** `views/twig/extensions/themes/default/page/checkout/order.html.twig`
- **App Setup:** `resources/build/js/app.js`
- **Build Script:** `resources/build.js`

### External

- **Stimulus.js Documentation:** https://stimulus.hotwired.dev/
- **Stimulus Handbook:** https://stimulus.hotwired.dev/handbook/introduction
- **Stimulus Reference:** https://stimulus.hotwired.dev/reference/controllers

---

## Conclusion

The Order Submit Controller provides a clean, testable way to handle order submission using Stimulus.js. The current implementation shows an alert for testing, but it's designed to be extended with:

- ✅ Order validation
- ✅ Loading states
- ✅ Stripe payment confirmation
- ✅ AJAX order submission
- ✅ Error handling
- ✅ Success redirect

**Next Steps:**
1. ✅ Test the alert(123) works in browser
2. ⏳ Implement actual order submission logic
3. ⏳ Add Stripe PaymentIntent confirmation
4. ⏳ Add loading states and error handling

---

*Generated: 2025-11-19*
*Author: Claude (Anthropic)*
*Version: 1.0*
