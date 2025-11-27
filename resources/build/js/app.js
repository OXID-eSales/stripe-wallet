/**
 * Stripe Module - JavaScript Entry Point
 *
 * Initializes Stimulus.js and registers all controllers
 */

import { Application } from "@hotwired/stimulus"

// Import controllers
import BuyNowController from "./controllers/buy_now_controller"
import StripeOrderController from "./controllers/stripe_order_controller"
import OrderSubmitController from "./controllers/order_submit_controller"
import AgbValidationController from "./controllers/agb_validation_controller"

// Start Stimulus application
window.Stimulus = Application.start()

// Register controllers
Stimulus.register("buy-now", BuyNowController)
Stimulus.register("stripe-order", StripeOrderController)
Stimulus.register("order-submit", OrderSubmitController)
Stimulus.register("agb-validation", AgbValidationController)

// Debug mode in development
if (process.env.NODE_ENV === 'development') {
  Stimulus.debug = true
  console.log('Stripe Module: Stimulus initialized with controllers:', Stimulus.router.modulesByIdentifier)
}

console.log('Stripe Module: JavaScript loaded and ready')
