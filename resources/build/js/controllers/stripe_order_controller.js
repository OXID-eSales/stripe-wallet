import { Controller } from "@hotwired/stimulus"

/**
 * Stimulus Controller for Stripe Payment Element on Order Page
 *
 * Handles Stripe payment form initialization and submission on the order confirmation page
 *
 * Usage in Twig:
 * <div data-controller="stripe-order"
 *      data-stripe-order-publishable-key-value="pk_..."
 *      data-stripe-order-client-secret-value="pi_..._secret_...">
 *   <div id="payment-element"></div>
 *   <div id="payment-errors" style="display:none">
 *     <span data-stripe-order-target="errorMessage"></span>
 *   </div>
 * </div>
 */
export default class extends Controller {
  static values = {
    publishableKey: String,
    clientSecret: String
  }

  static targets = ["errorMessage", "loading"]

  connect() {
    console.log('Stripe Order controller connected', {
      hasPublishableKey: !!this.publishableKeyValue,
      hasClientSecret: !!this.clientSecretValue,
      publishableKey: this.publishableKeyValue ? this.publishableKeyValue.substring(0, 10) + '...' : 'missing',
      clientSecretLength: this.clientSecretValue ? this.clientSecretValue.length : 0
    })
debugger
    // Get debug info from element
    const debugInfo = this.element.getAttribute('data-debug-info')
    if (debugInfo) {
      console.log('Debug info:', debugInfo)
    }

    // Validate required configuration
    if (!this.publishableKeyValue) {
      console.error('Stripe publishable key not configured')
      this.showError('Stripe configuration error. Please contact support.')
      return
    }

    if (!this.clientSecretValue) {
      console.warn('⚠️ Stripe client secret not available', {
        message: 'The backend did not generate a PaymentIntent client secret.',
        possibleReasons: [
          '1. Payment method not detected as Stripe (check payment ID = osc_stripe_card)',
          '2. User not logged in or session issue',
          '3. Backend error creating PaymentIntent (check PHP logs)',
          '4. StripePaymentService not properly configured'
        ],
        nextSteps: 'Check browser Network tab and PHP error logs'
      })

      // Show user-friendly message
      this.showError('Payment initialization failed. Please refresh the page or contact support.')
      return
    }

    // Wait for Stripe.js to load
    this.initializeStripe()
  }

  disconnect() {
    // Cleanup if needed
    if (this.paymentElement) {
      this.paymentElement.unmount()
    }
  }

  /**
   * Initialize Stripe and mount Payment Element
   */
  async initializeStripe() {
    // Wait for Stripe.js to be available
    if (typeof Stripe === 'undefined') {
      console.log('Waiting for Stripe.js to load...')
      await this.waitForStripe()
    }

    try {
      // Initialize Stripe
      this.stripe = Stripe(this.publishableKeyValue)

      // Create Elements with styling
      const appearance = {
        theme: 'stripe',
        variables: {
          colorPrimary: '#0570de',
          colorBackground: '#ffffff',
          colorText: '#30313d',
          fontFamily: 'system-ui, sans-serif',
          borderRadius: '4px'
        }
      }

      this.elements = this.stripe.elements({
        clientSecret: this.clientSecretValue,
        appearance: appearance
      })

      // Create and mount Payment Element
      this.paymentElement = this.elements.create('payment')
      this.paymentElement.mount('#payment-element')

      // Handle ready event
      this.paymentElement.on('ready', () => {
        console.log('Payment Element ready')
        this.hideLoading()
      })

      // Handle validation errors
      this.paymentElement.on('change', (event) => {
        if (event.error) {
          this.showError(event.error.message)
        } else {
          this.hideError()
        }
      })

      console.log('Stripe Payment Element initialized successfully')

    } catch (error) {
      console.error('Failed to initialize Stripe:', error)
      this.showError('Failed to initialize payment form. Please refresh the page.')
    }
  }

  /**
   * Wait for Stripe.js library to load
   * @returns {Promise}
   */
  waitForStripe() {
    return new Promise((resolve) => {
      const checkStripe = () => {
        if (typeof Stripe !== 'undefined') {
          resolve()
        } else {
          setTimeout(checkStripe, 100)
        }
      }
      checkStripe()
    })
  }

  /**
   * Show loading indicator
   */
  showLoading() {
    if (this.hasLoadingTarget) {
      this.loadingTarget.style.display = 'block'
    }
  }

  /**
   * Show error message
   * @param {String} message
   */
  showError(message) {
    const errorDiv = document.getElementById('payment-errors')
    if (errorDiv && this.hasErrorMessageTarget) {
      errorDiv.style.display = 'block'
      this.errorMessageTarget.textContent = message
    }
  }

  /**
   * Hide error message
   */
  hideError() {
    const errorDiv = document.getElementById('payment-errors')
    if (errorDiv) {
      errorDiv.style.display = 'none'
      if (this.hasErrorMessageTarget) {
        this.errorMessageTarget.textContent = ''
      }
    }
  }

  /**
   * Hide loading indicator
   */
  hideLoading() {
    if (this.hasLoadingTarget) {
      this.loadingTarget.style.display = 'none'
    }
  }

  /**
   * Get Stripe instance (for form submission handler)
   * @returns {Object} Stripe instance
   */
  getStripe() {
    return this.stripe
  }

  /**
   * Get Elements instance (for form submission handler)
   * @returns {Object} Elements instance
   */
  getElements() {
    return this.elements
  }

  /**
   * Handle order form submission
   * This method should be called when the order confirmation button is clicked
   * @param {Event} event - Form submission event
   */
  async handlePayment(event) {
    event.preventDefault()

    if (!this.stripe || !this.elements) {
      this.showError('Payment form not initialized. Please refresh the page.')
      return
    }

    this.showLoading()
    this.hideError()

    try {
      // Get the return URL from current location
      const shopUrl = window.location.origin + window.location.pathname.split('/index.php')[0]
      const returnUrl = shopUrl + '/index.php?cl=order&fnc=stripeReturn'

      console.log('Confirming payment with return URL:', returnUrl)

      // Confirm payment with Stripe
      const { error } = await this.stripe.confirmPayment({
        elements: this.elements,
        confirmParams: {
          return_url: returnUrl,
        },
      })

      // This code will only execute if there's an immediate error
      // If payment succeeds or requires redirect, user will be redirected
      if (error) {
        console.error('Payment confirmation error:', error)

        // Show error to customer
        if (error.type === 'card_error' || error.type === 'validation_error') {
          this.showError(error.message)
        } else {
          this.showError('An unexpected error occurred. Please try again.')
        }
      }

    } catch (error) {
      console.error('Payment processing error:', error)
      this.showError('Payment processing failed. Please try again.')
    } finally {
      this.hideLoading()
    }
  }
}
