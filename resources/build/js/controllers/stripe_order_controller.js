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
      publishableKey: this.publishableKeyValue ? this.publishableKeyValue.substring(0, 10) + '...' : 'missing',
    })

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
        appearance: appearance
      })

      const card = this.elements.create('card');
      card.mount('#card-element');

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

}
