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
import { withEventBus } from "../mixins/event_bus_mixin.js"

export default class extends withEventBus(Controller) {
  static values = {
    publishableKey: String,
    mode: String,
    returnUrl: String,
    apiUrl: String,  // API base URL (e.g., /index.php?cl=OeCheckoutApi)
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
    this.listen('oe:footer:submit-clicked', this.handleFooterSubmit.bind(this))

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
   * Event Data:
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
  async handleMethodSelected(data) {
    const { paymentMethodId } = data

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
   * Handle oe:footer:submit-clicked event
   *
   * Event Data:
   * {
   *   paymentMethod: string,
   *   basketId: string,
   *   totalPrice: number,
   *   currency: string,
   *   confirmed: boolean
   * }
   *
   * Responsibility:
   * - Process full payment flow: create contract → confirm payment → place order
   */
  async handleFooterSubmit(data) {
    const { paymentMethod, basketId, totalPrice, currency } = data

    console.log('[OnePageStripeController] Footer submit clicked:', {
      paymentMethod,
      basketId,
      totalPrice,
      currency
    })

    if (!this.isStripeMethod(paymentMethod)) {
      return // Not Stripe payment
    }

    // Show Stripe UI (wrapper and element container)
    this.showStripeUI()

    // Show loader
    this.showLoader()
    this.hideError()

    // Broadcast processing event
    this.broadcast('oe:payment:processing', {
      paymentMethod: paymentMethod
    })

    try {
      // Step 1: Create contract via OPC API (which creates Checkout Session)
      console.log('[OnePageStripeController] Step 1: Creating payment contract...')
      const contractResult = await this.createContract(paymentMethod)

      if (!contractResult.success) {
        throw new Error(contractResult.errorMessage || 'Failed to create payment contract')
      }

      console.log('[OnePageStripeController] Contract created:', {
        contractId: contractResult.contractId,
        metadata: contractResult.metadata
      })

      // Step 2: Check if we have redirect URL (Checkout Session)
      const redirectUrl = contractResult.metadata?.redirectUrl || contractResult.metadata?.checkoutUrl

      if (!redirectUrl) {
        throw new Error('No redirect URL provided by payment handler')
      }

      console.log('[OnePageStripeController] Redirecting to Stripe Checkout:', redirectUrl)

      // Broadcast processing event
      this.broadcast('oe:payment:redirect', {
        provider: 'stripe',
        contractId: contractResult.contractId,
        redirectUrl: redirectUrl
      })

      // Redirect to Stripe Checkout
      window.location.href = redirectUrl

    } catch (error) {
      console.error('[OnePageStripeController] Payment processing failed:', error)

      // Show error
      this.showError(error.message || 'Payment processing failed')

      // Broadcast error
      this.broadcast('oe:payment:error', {
        error: error.message,
        paymentMethod: paymentMethod
      })
    } finally {
      this.hideLoader()
    }
  }

  /**
   * Handle oe:payment:confirm-requested event
   *
   * Event Data:
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
  async handleConfirmRequest(data) {
    const { paymentMethodId, clientSecret, contractId, orderId } = data

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
      'oe_payments_stripe_wallet',  // Module ID
      'stripe',
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
   * Initialize Stripe Payment Element with client secret
   *
   * @param {string} clientSecret - Stripe PaymentIntent client secret
   */
  async initializePaymentElement(clientSecret = null) {
    if (!this.stripe) {
      console.error('[OnePageStripeController] Stripe SDK not loaded')
      return
    }

    if (this.paymentElement) {
      // Already initialized - destroy and recreate with new client secret
      console.log('[OnePageStripeController] Destroying existing Payment Element...')
      this.paymentElement.destroy()
      this.paymentElement = null
      this.elements = null
    }

    console.log('[OnePageStripeController] Initializing Payment Element...', {
      hasClientSecret: !!clientSecret
    })

    // Make sure the element container is visible before mounting
    if (this.hasElementTarget) {
      this.elementTarget.style.display = 'block'
    }

    // Create Elements instance
    const elementsOptions = {
      appearance: {
        theme: 'stripe',
      },
    }

    // If we have a client secret, use it. Otherwise use placeholder mode.
    if (clientSecret) {
      elementsOptions.clientSecret = clientSecret
    } else {
      // Placeholder mode for initial UI rendering
      elementsOptions.mode = 'payment'
      elementsOptions.amount = 1000
      elementsOptions.currency = 'eur'
    }

    this.elements = this.stripe.elements(elementsOptions)

    // Create and mount Payment Element
    this.paymentElement = this.elements.create('payment')

    // Ensure target exists and is visible
    if (!this.hasElementTarget) {
      throw new Error('Payment Element target not found')
    }

    try {
      this.paymentElement.mount(this.elementTarget)
      console.log('[OnePageStripeController] Payment Element mounted successfully')
    } catch (error) {
      console.error('[OnePageStripeController] Failed to mount Payment Element:', error)
      throw error
    }

    console.log('[OnePageStripeController] Payment Element initialized')
  }

  /**
   * Confirm payment with Stripe SDK
   *
   * @param {string} clientSecret - Stripe PaymentIntent client secret (not used - Elements already has it)
   * @returns {Promise<Object>} - Payment result
   */
  async confirmPayment(clientSecret) {
    if (!this.stripe || !this.elements) {
      throw new Error('Stripe SDK not initialized')
    }

    console.log('[OnePageStripeController] Confirming payment with Stripe...', {
      hasClientSecret: !!clientSecret
    })

    // Confirm payment (Elements instance already has the client secret)
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

  /**
   * API: Create payment contract
   *
   * @param {string} paymentMethodId - Payment method ID
   * @returns {Promise<Object>} - Contract result with clientSecret
   */
  async createContract(paymentMethodId) {
    const apiUrl = this.apiUrlValue || '/index.php?cl=OeCheckoutApi'

    console.log('[OnePageStripeController] Creating contract via API:', apiUrl)

    const response = await fetch(`${apiUrl}&fnc=processCheckout`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        paymentMethodId: paymentMethodId,
        returnUrl: this.returnUrlValue,
        cancelUrl: window.location.href,
      })
    })

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`)
    }

    const data = await response.json()
    console.log('[OnePageStripeController] Contract API response:', data)

    return data
  }

  /**
   * API: Place order
   *
   * @param {string} contractId - Contract ID
   * @returns {Promise<Object>} - Order result
   */
  async placeOrder(contractId) {
    const apiUrl = this.apiUrlValue || '/index.php?cl=OeCheckoutApi'

    console.log('[OnePageStripeController] Placing order via API:', apiUrl)

    const response = await fetch(`${apiUrl}&fnc=placeOrder`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        contractId: contractId,
        confirmTermsAndConditions: true,  // Already confirmed by footer
        remark: ''
      })
    })

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`)
    }

    const data = await response.json()
    console.log('[OnePageStripeController] Order API response:', data)

    return data
  }
}