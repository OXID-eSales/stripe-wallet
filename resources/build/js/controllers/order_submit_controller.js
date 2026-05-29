import { Controller } from "@hotwired/stimulus"

/**
 * Stimulus Controller for Order Submit Button
 *
 * Handles order submission on the checkout order page.
 * Supports two payment flows:
 * 1. Stripe Checkout (hosted page) - for wallet payments
 * 2. Payment Intent (card element) - for card payments
 *
 * Usage in Twig:
 * <button data-controller="order-submit"
 *         data-action="click->order-submit#handleSubmit"
 *         data-order-submit-url-value="..."
 *         data-order-submit-payment-type-value="wallet|card"
 *         type="button">
 *   Submit Order
 * </button>
 */
export default class extends Controller {
  static targets = ["status"]
  static values = {
    url: String,
    paymentType: String,
    publishableKey: String
  }

  /**
   * Called when controller is connected to DOM
   */
  connect() {
    console.log('Order Submit controller connected')
    console.log('Button element:', this.element)
  }

  /**
   * Called when controller is disconnected from DOM
   */
  disconnect() {
    console.log('Order Submit controller disconnected')
  }

  /**
   * Get the stripe-order controller instance
   * @returns {Controller|null}
   */
  getStripeOrderController() {
    const cardElement = document.getElementById('card-element')
    if (!cardElement) {
      console.error('Card element not found')
      return null
    }

    const controller = this.application.getControllerForElementAndIdentifier(
      cardElement,
      'stripe-order'
    )

    if (!controller) {
      console.error('Stripe order controller not found on card element')
      return null
    }

    console.log('Found stripe-order controller:', controller)
    return controller
  }

  /**
   * Handle order submit button click
   * Routes to appropriate payment flow based on payment type
   * @param {Event} event - The click event
   */
  async handleSubmit(event) {
    event.preventDefault()

    console.log('Order submit button clicked', {
      buttonId: this.element.id,
      paymentType: this.paymentTypeValue,
      timestamp: new Date().toISOString()
    })

    this.showLoading()

    try {
      // Route to appropriate payment flow
      if (this.paymentTypeValue === 'wallet') {
        await this.handleStripeCheckout()
      } else {
        await this.handlePaymentIntent()
      }
    } catch (error) {
      console.error('Order submission failed', error)
      this.showError(error.message || window.oStripe?.i18n?.PAYMENT_FAILED || 'Payment processing failed')
    } finally {
      this.hideLoading()
    }
  }

  /**
   * Handle Stripe Checkout flow (hosted payment page)
   * Used for wallet payments (Apple Pay, Google Pay)
   */
  async handleStripeCheckout() {
    if (!window.Stripe) {
      throw new Error(window.oStripe?.i18n?.JS_NOT_LOADED || 'Stripe.js not loaded')
    }

    // Get Stripe publishable key from Stimulus value
    if (!this.hasPublishableKeyValue || !this.publishableKeyValue) {
      throw new Error(window.oStripe?.i18n?.KEY_NOT_CONFIGURED || 'Stripe publishable key not configured')
    }

    const stripe = Stripe(this.publishableKeyValue)

    this.setStatus(window.oStripe?.i18n?.CREATING_SESSION || 'Creating checkout session...')

    // Create Checkout Session (include stoken for CSRF protection)
    const response = await fetch(this.appendAgbState(this.buildUrlWithCsrfToken(this.urlValue)), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        capture: 'automatic' // Can be made configurable
      }),
      credentials: 'same-origin'
    })

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}))
      // STRP-129: a 422 carries per-field validation messages in `errors[]`.
      // Render them in the standard OXID red error box (not the generic
      // fallback) so the shopper sees which field is invalid and which symbols
      // are allowed, then stop the checkout flow.
      const messages = this.collectValidationMessages(errorData)
      if (messages.length) {
        this.renderFieldValidationErrors(errorData.errors)
        this.showValidationBox(messages)
        return
      }
      throw new Error(errorData.error || window.oStripe?.i18n?.SESSION_FAILED || 'Failed to create checkout session')
    }

    const data = await response.json()

    if (!data.id) {
      throw new Error(window.oStripe?.i18n?.SESSION_INVALID || 'Invalid checkout session response')
    }

    console.log('Checkout Session created:', data.id, 'URL:', data.url)
    console.log('Debug info:', data._debug)

    // Redirect to Stripe Checkout using direct URL (more reliable)
    if (data.url) {
      window.location.href = data.url
      return
    }

    // Fallback to redirectToCheckout if URL not available
    const { error } = await stripe.redirectToCheckout({
      sessionId: data.id
    })

    if (error) {
      throw error
    }
  }

  /**
   * Handle Payment Intent flow (card element)
   * Used for card payments
   */
  async handlePaymentIntent() {
    // Get stripe-order controller instance
    const stripeOrderController = this.getStripeOrderController()

    if (!stripeOrderController) {
      throw new Error(window.oStripe?.i18n?.CONTROLLER_NOT_FOUND || 'Stripe payment controller not found. Please refresh the page.')
    }

    // Verify card element and stripe are available
    if (!stripeOrderController.card || !stripeOrderController.stripe) {
      console.error('Payment form not ready:', {
        hasCard: !!stripeOrderController.card,
        hasStripe: !!stripeOrderController.stripe
      })
      throw new Error(window.oStripe?.i18n?.FORM_NOT_READY || 'Payment form not initialized. Please refresh the page.')
    }

    console.log('Stripe controller ready:', {
      hasCard: !!stripeOrderController.card,
      hasStripe: !!stripeOrderController.stripe
    })

    const paymentIntentResponse = await this.handlePayment()
    const clientSecret = paymentIntentResponse.clientSecret

    const confirmPaymentResponse = await stripeOrderController.stripe.confirmCardPayment(clientSecret, {
      payment_method: {
        card: stripeOrderController.card
      }
    });

    if (confirmPaymentResponse.error) {
      throw new Error(confirmPaymentResponse.error.message)
    } else if (confirmPaymentResponse.paymentIntent && confirmPaymentResponse.paymentIntent.status === 'succeeded') {
      console.log('Payment succeeded', confirmPaymentResponse.paymentIntent)
      // TODO: Submit final order to backend
    } else {
      throw new Error(window.oStripe?.i18n?.PAYMENT_NOT_COMPLETED || 'Payment not completed')
    }
  }

  /**
   * Fetch payment intent creation URL and return response
   * @returns {Promise<Object>} Payment intent response with clientSecret, amount, currency
   * @throws {Error} If fetch fails or response is not ok
   */
  async handlePayment() {
    if (!this.hasUrlValue) {
      throw new Error(window.oStripe?.i18n?.URL_NOT_CONFIGURED || 'Payment URL is not configured')
    }

    console.log('Creating payment intent via URL:', this.urlValue)

    const response = await fetch(this.appendAgbState(this.buildUrlWithCsrfToken(this.urlValue)), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'same-origin'
    })

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`)
    }

    const responseData = await response.json()

    if (responseData.error) {
      throw new Error(responseData.error)
    }

    if (!responseData.success || !responseData.clientSecret) {
      throw new Error(window.oStripe?.i18n?.INTENT_INVALID || 'Invalid payment intent response')
    }

    return responseData
  }

  /**
   * Append stoken (CSRF token) to URL for session challenge validation.
   * OXID includes stoken in forms via oViewConf.getSessionChallengeToken().
   * @param {string} url - The base URL
   * @returns {string} URL with stoken parameter appended
   */
  buildUrlWithCsrfToken(url) {
    const stoken = document.querySelector('input[name="stoken"]')?.value || ''
    if (!stoken) {
      console.warn('CSRF token (stoken) not found in form')
      return url
    }
    const separator = url.includes('?') ? '&' : '?'
    return url + separator + 'stoken=' + encodeURIComponent(stoken)
  }

  /**
   * Append the AGB acceptance flag (ord_agb=1) when the customer has ticked
   * the apex Terms-and-Conditions checkbox (#checkAgbTop). The Stripe order
   * fetch posts a JSON body, which OXID's Registry::getRequest() does not
   * parse — placing ord_agb in the query string is the simplest way to make
   * StripeOrderController::createCheckoutSession() see it.
   *
   * @param {string} url
   * @returns {string}
   */
  appendAgbState(url) {
    const checkbox = document.getElementById('checkAgbTop')
    if (!checkbox || !checkbox.checked) {
      return url
    }
    const separator = url.includes('?') ? '&' : '?'
    return url + separator + 'ord_agb=1'
  }

  /**
   * Show loading state on button
   */
  showLoading() {
    this.element.disabled = true
    this.originalText = this.element.textContent
    this.element.textContent = window.oStripe?.i18n?.PROCESSING || 'Processing...'
  }

  /**
   * Hide loading state on button
   */
  hideLoading() {
    this.element.disabled = false
    if (this.originalText) {
      this.element.textContent = this.originalText
    }
  }

  /**
   * Set status message
   * @param {string} message - Status message to display
   */
  setStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message
      this.statusTarget.className = 'mt-2 text-center text-muted'
    }
    console.log('Status:', message)
  }

  /**
   * Extract the per-field validation messages from a 422 payload.
   * @param {{errors?: Array<{message?: string}>}} errorData
   * @returns {string[]} per-field messages (empty if none)
   */
  collectValidationMessages(errorData) {
    if (!errorData || !Array.isArray(errorData.errors)) {
      return []
    }
    return errorData.errors.map((e) => e && e.message).filter(Boolean)
  }

  /**
   * Render the validation messages in the standard OXID red error box
   * (#stripe-validation-errors, placed above the checkout form). The box is
   * dismissed by the "Understand" button OR by pressing any key.
   * Falls back to the status target if the container is absent.
   * @param {string[]} messages
   */
  showValidationBox(messages) {
    const container = document.getElementById('stripe-validation-errors')
    if (!container) {
      this.showError(messages.join(' '))
      return
    }

    const understandText = container.getAttribute('data-stripe-validation-understand') || 'Understand'
    container.innerHTML = ''

    // One error -> one box.
    const dismissAll = () => {
      container.innerHTML = ''
      document.removeEventListener('keydown', dismissAll)
    }

    let firstBox = null
    for (const message of messages) {
      const box = this.buildErrorBox(message, understandText)
      container.appendChild(box)
      firstBox = firstBox || box
    }

    // Any keypress dismisses every box.
    document.addEventListener('keydown', dismissAll, { once: true })

    if (firstBox) {
      firstBox.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  }

  /**
   * Build a single OXID-style red error box for one message.
   * The "Understand" button removes just this box.
   * @param {string} message
   * @param {string} understandText
   * @returns {HTMLElement}
   */
  buildErrorBox(message, understandText) {
    const box = document.createElement('div')
    box.className = 'alert alert-danger d-flex justify-content-between align-items-center px-4'

    const textWrap = document.createElement('div')
    textWrap.className = 'ps-2 pe-3 text-start flex-grow-1'
    textWrap.style.textAlign = 'left'

    const p = document.createElement('p')
    p.className = 'mb-0'
    // Override the theme's `.alert-danger p { text-align: center }` rule.
    p.style.textAlign = 'left'
    p.textContent = message
    textWrap.appendChild(p)

    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'btn btn-outline-light btn-sm text-white border border-white flex-shrink-0'
    button.textContent = understandText
    button.addEventListener('click', () => box.remove())

    box.appendChild(textWrap)
    box.appendChild(button)
    return box
  }

  /**
   * Mark the corresponding OXID address inputs invalid + render inline feedback,
   * when such inputs exist in the DOM (editable checkout themes / cl=user step).
   * On the read-only order page this is a no-op; the error box carries the message.
   * @param {Array<{field?: string, message?: string}>} errors
   */
  renderFieldValidationErrors(errors) {
    if (!Array.isArray(errors)) {
      return
    }
    const NAME_MAP = {
      firstName: 'oxuser__oxfname', lastName: 'oxuser__oxlname',
      additionalInfo: 'oxuser__oxaddinfo', street: 'oxuser__oxstreet',
      houseNumber: 'oxuser__oxstreetnr', postalCode: 'oxuser__oxzip',
      city: 'oxuser__oxcity', company: 'oxuser__oxcompany', vatId: 'oxuser__oxustid',
      phone: 'oxuser__oxfon', cellPhone: 'oxuser__oxprivfon',
      personalPhone: 'oxuser__oxmobfon', fax: 'oxuser__oxfax'
    }
    for (const err of errors) {
      const name = NAME_MAP[err && err.field]
      const el = name ? document.querySelector('[name="' + name + '"]') : null
      if (!el) {
        continue
      }
      el.classList.add('is-invalid')
      const existing = el.parentElement && el.parentElement.querySelector('.invalid-feedback[data-stripe-validation]')
      if (existing) existing.remove()
      const feedback = document.createElement('div')
      feedback.className = 'invalid-feedback'
      feedback.setAttribute('data-stripe-validation', 'true')
      feedback.textContent = err.message || ('Invalid value for ' + err.field)
      el.insertAdjacentElement('afterend', feedback)
    }
  }

  /**
   * Show error message
   * @param {string} message - Error message to display
   */
  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message
      this.statusTarget.className = 'mt-2 text-center text-danger'
    } else {
      alert('Error: ' + message)
    }
  }
}
