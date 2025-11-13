/**
 * Stripe Module - JavaScript Entry Point
 *
 * Initializes Stimulus.js and registers all controllers
 */

import { Application } from "@hotwired/stimulus"

// Import controllers
import BuyNowController from "./controllers/buy_now_controller"

// Start Stimulus application
window.Stimulus = Application.start()

// Register controllers
Stimulus.register("buy-now", BuyNowController)

// Debug mode in development
if (process.env.NODE_ENV === 'development') {
  Stimulus.debug = true
  console.log('Stripe Module: Stimulus initialized with controllers:', Stimulus.router.modulesByIdentifier)
}

console.log('Stripe Module: JavaScript loaded and ready')
