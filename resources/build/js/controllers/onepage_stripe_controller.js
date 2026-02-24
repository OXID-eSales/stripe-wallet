/**
 * One-Page Checkout Stripe Integration Controller
 *
 * Integrates Stripe payments with the one-page checkout module via EventBus.
 * Implements the event contract defined in one-page checkout documentation.
 *
 * Required Events to Listen:
 * - oe:payment:method-selected - User selects payment method
 * - oe:payment:confirm-requested - Core requests payment confirmation
 *
 * Required Events to Emit:
 * - oe:payment:confirmed - Payment successfully confirmed
 * - oe:payment:failed - Payment failed
 *
 * @see docs/PAYMENT_PROVIDER_INTEGRATION_GUIDE.md
 * @see docs/diagrams/payment-provider-integration/03-event-contract-details.puml
 */

import { Controller } from "@hotwired/stimulus"
import { withEventBus } from "../../../../../onepage-checkout/resources/build/js/mixins/event_bus_mixin.js"

export default class extends withEventBus(Controller) {
  static values = {
    publishableKey: String,
    mode: String,
    returnUrl: String,
  }

  static targets = ["element", "loader", "error"]

  /**
   * Stimulus lifecycle: Controller connected to DOM
   */
  connect() {
    console.log('[OnePageStripeController] Connected')

    // Register EventBus listeners (automatic cleanup via withEventBus mixin)
    this.listen('oe:payment:method-selected', this.handleMethodSelected.bind(this))
    this.listen('oe:payment:confirm-requested', this.handleConfirmRequest.bind(this))

    // Initialize state
    this.stripe = null
    this.elements = null
    this.paymentElement = null
    this.currentContractId = null
    this.currentOrderId = null
  }

  /**
   * Stimulus lifecycle: Controller disconnected from DOM
   */
  disconnect() {
    console.log('[OnePageStripeController] Disconnected')

    // EventBus listeners are automatically cleaned up by withEventBus mixin

    // Cleanup Stripe resources
    if (this.paymentElement) {
      this.paymentElement.destroy()
      this.paymentElement = null
    }
    this.elements = null
    this.stripe = null
  }

  /**
   * Handle oe:payment:method-selected event
   *
   * Event Detail:
   * {
   *   paymentMethodId: string,  // e.g., 'oxidstripe', 'paypal'
   *   paymentMethodTitle: string // e.g., 'Credit Card (Stripe)'
   * }
   *
   * Responsibility:
   * - Check if paymentMethodId matches Stripe
   * - Show Stripe UI if match
   * - Hide Stripe UI if no match
   */
  async handleMethodSelected(event) {
    const { paymentMethodId } = event.detail

    console.log('[OnePageStripeController] Payment method selected:', paymentMethodId)

    if (!this.isStripeMethod(paymentMethodId)) {
      this.hideStripeUI()
      return
    }

    // Show Stripe UI
    this.showStripeUI()

    // Load Stripe.js SDK if not loaded
    if (!this.stripe) {
      await this.loadStripeSDK()
    }

    // Initialize Payment Element
    await this.initializePaymentElement()
  }

  /**
   * Handle oe:payment:confirm-requested event
   *
   * Event Detail:
   * {
   *   contractId: string,       // PaymentContract ID
   *   clientSecret: string,     // Stripe client secret (from PaymentIntent)
   *   paymentMethodId: string,  // e.g., 'oxidstripe'
   *   returnUrl: string         // URL to redirect after SCA
   * }
   *
   * Responsibility:
   * - Check if paymentMethodId matches Stripe
   * - Process payment with Stripe SDK
   * - Emit oe:payment:confirmed or oe:payment:failed
   */
  async handleConfirmRequest(event) {
    const { paymentMethodId, clientSecret, contractId, orderId } = event.detail

    console.log('[OnePageStripeController] Confirm request:', {
      paymentMethodId,
      clientSecret: clientSecret ? '***' : 'missing',
      contractId,
      orderId
    })

    if (!this.isStripeMethod(paymentMethodId)) {
      return // Not my responsibility
    }

    // Save state
    this.currentContractId = contractId
    this.currentOrderId = orderId

    // Show loader
    this.showLoader()
    this.hideError()

    try {
      // Confirm payment with Stripe
      const result = await this.confirmPayment(clientSecret)

      console.log('[OnePageStripeController] Payment confirmed:', result)

      // Emit success event
      this.broadcastPaymentConfirmed(result)
    } catch (error) {
      console.error('[OnePageStripeController] Payment failed:', error)

      // Show error
      this.showError(error.message)

      // Emit failure event
      this.broadcastPaymentFailed(error)
    } finally {
      this.hideLoader()
    }
  }

  /**
   * Check if payment method ID belongs to Stripe
   */
  isStripeMethod(paymentMethodId) {
    if (!paymentMethodId) {
      return false
    }

    const stripePaymentMethods = [
      'oxidstripe',
      'oxidstripe_card',
      'oxidstripe_wallet',
    ]

    return stripePaymentMethods.some(method =>
      paymentMethodId.toLowerCase().includes(method.toLowerCase())
    )
  }

  /**
   * Load Stripe.js SDK dynamically
   */
  async loadStripeSDK() {
    if (window.Stripe) {
      this.stripe = window.Stripe(this.publishableKeyValue)
      return
    }

    console.log('[OnePageStripeController] Loading Stripe.js SDK...')

    // Load Stripe.js script
    await new Promise((resolve, reject) => {
      const script = document.createElement('script')
      script.src = 'https://js.stripe.com/v3/'
      script.async = true
      script.onload = resolve
      script.onerror = reject
      document.head.appendChild(script)
    })

    this.stripe = window.Stripe(this.publishableKeyValue)
    console.log('[OnePageStripeController] Stripe.js SDK loaded')
  }

  /**
   * Initialize Stripe Payment Element
   */
  async initializePaymentElement() {
    if (!this.stripe) {
      console.error('[OnePageStripeController] Stripe SDK not loaded')
      return
    }

    if (this.paymentElement) {
      // Already initialized
      return
    }

    console.log('[OnePageStripeController] Initializing Payment Element...')

    // Create Elements instance (will be configured with client secret later)
    this.elements = this.stripe.elements({
      mode: 'payment',
      amount: 1000, // Placeholder, will be updated with real client secret
      currency: 'eur',
      appearance: {
        theme: 'stripe',
      },
    })

    // Create and mount Payment Element
    this.paymentElement = this.elements.create('payment')
    this.paymentElement.mount(this.elementTarget)

    console.log('[OnePageStripeController] Payment Element initialized')
  }

  /**
   * Confirm payment with Stripe SDK
   *
   * @param {string} clientSecret - Stripe PaymentIntent client secret
   * @returns {Promise<Object>} - Payment result
   */
  async confirmPayment(clientSecret) {
    if (!this.stripe || !this.elements) {
      throw new Error('Stripe SDK not initialized')
    }

    console.log('[OnePageStripeController] Confirming payment with Stripe...')

    // Update elements with client secret
    this.elements.update({
      clientSecret: clientSecret,
    })

    // Confirm payment
    const result = await this.stripe.confirmPayment({
      elements: this.elements,
      confirmParams: {
        return_url: this.returnUrlValue || window.location.origin + '/order',
      },
      redirect: 'if_required', // Only redirect if 3D Secure is needed
    })

    // Handle result
    if (result.error) {
      throw new Error(result.error.message || 'Payment confirmation failed')
    }

    if (result.paymentIntent?.status === 'succeeded') {
      return {
        paymentIntentId: result.paymentIntent.id,
        status: result.paymentIntent.status,
        amount: result.paymentIntent.amount,
        currency: result.paymentIntent.currency,
      }
    }

    // Payment not succeeded yet (e.g., requires action)
    throw new Error(`Payment not confirmed. Status: ${result.paymentIntent?.status || 'unknown'}`)
  }

  /**
   * Broadcast oe:payment:confirmed event
   */
  broadcastPaymentConfirmed(paymentResult) {
    this.broadcast('oe:payment:confirmed', {
      provider: 'stripe',
      contractId: this.currentContractId,
      orderId: this.currentOrderId,
      transactionId: paymentResult.paymentIntentId,
      metadata: paymentResult,
    })

    console.log('[OnePageStripeController] Payment confirmed event dispatched')
  }

  /**
   * Broadcast oe:payment:failed event
   */
  broadcastPaymentFailed(error) {
    this.broadcast('oe:payment:failed', {
      provider: 'stripe',
      contractId: this.currentContractId,
      orderId: this.currentOrderId,
      error: error.message || 'Payment failed',
      errorCode: error.code || 'STRIPE_ERROR',
    })

    console.log('[OnePageStripeController] Payment failed event dispatched')
  }

  /**
   * UI Helper: Show Stripe UI
   * Shows the entire Stripe provider wrapper (not just the payment element)
   */
  showStripeUI() {
    // Show the wrapper (controller element)
    this.element.style.display = 'block'

    // Also show the payment element container if it exists
    if (this.hasElementTarget) {
      this.elementTarget.style.display = 'block'
    }
  }

  /**
   * UI Helper: Hide Stripe UI
   * Hides the entire Stripe provider wrapper
   */
  hideStripeUI() {
    // Hide the wrapper (controller element)
    this.element.style.display = 'none'
  }

  /**
   * UI Helper: Show loader
   */
  showLoader() {
    if (this.hasLoaderTarget) {
      this.loaderTarget.style.display = 'block'
    }
  }

  /**
   * UI Helper: Hide loader
   */
  hideLoader() {
    if (this.hasLoaderTarget) {
      this.loaderTarget.style.display = 'none'
    }
  }

  /**
   * UI Helper: Show error message
   */
  showError(message) {
    if (this.hasErrorTarget) {
      this.errorTarget.textContent = message
      this.errorTarget.style.display = 'block'
    }
  }

  /**
   * UI Helper: Hide error message
   */
  hideError() {
    if (this.hasErrorTarget) {
      this.errorTarget.style.display = 'none'
      this.errorTarget.textContent = ''
    }
  }
}