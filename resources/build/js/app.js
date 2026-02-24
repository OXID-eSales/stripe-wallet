/**
 * Stripe Module - JavaScript Entry Point
 *
 * Initializes Stimulus.js and registers all controllers
 */

import { Application } from "@hotwired/stimulus"

// Import controllers
import StripeOrderController from "./controllers/stripe_order_controller"
import OrderSubmitController from "./controllers/order_submit_controller"
import AgbValidationController from "./controllers/agb_validation_controller"
import OnePageStripeController from "./controllers/onepage_stripe_controller"
import StripeCheckoutFooterController from "./controllers/stripe_checkout_footer_controller"

// Start Stimulus application
window.Stimulus = Application.start()

// Register controllers
Stimulus.register("stripe-order", StripeOrderController)
Stimulus.register("order-submit", OrderSubmitController)
Stimulus.register("agb-validation", AgbValidationController)
Stimulus.register("onepage-stripe", OnePageStripeController)
Stimulus.register("stripe-checkout-footer", StripeCheckoutFooterController)

// Debug mode in development
if (process.env.NODE_ENV === 'development') {
  Stimulus.debug = true
  console.log('Stripe Module: Stimulus initialized with controllers:', Stimulus.router.modulesByIdentifier)
}

console.log('Stripe Module: JavaScript loaded and ready')
