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
import { createDebugLogger } from "./debug.js"

// Start Stimulus application
window.Stimulus = Application.start()

// Register controllers
Stimulus.register("stripe-order", StripeOrderController)
Stimulus.register("order-submit", OrderSubmitController)
Stimulus.register("agb-validation", AgbValidationController)

// Frontend debug is gated by the module's log level (sStripeLogLevel === 'debug'),
// surfaced to JS as window.oStripe.debug by stripe_i18n.html.twig. It is NOT tied
// to the build target or domain, so switching the logging feature off silences the
// console — including Stimulus's own lifecycle logging — even on dev domains.
const stripeDebugEnabled = () => window.oStripe?.debug === true
const debug = createDebugLogger(stripeDebugEnabled)

Stimulus.debug = stripeDebugEnabled()
debug('Stripe Module: Stimulus initialized with controllers:', Stimulus.router.modulesByIdentifier)
debug('Stripe Module: JavaScript loaded and ready')
