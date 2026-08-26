import { Controller } from "@hotwired/stimulus"
import { createDebugLogger } from '../debug.js'
import { initEmbeddedCheckoutOnce, forgetEmbeddedCheckout } from '../embedded_checkout_registry.js'

/**
 * Stimulus Controller for Order Submit Button
 *
 * Handles order submission on the checkout order page.
 * Supports two payment flows:
 * 1. Stripe Checkout (hosted page) - for wallet payments
 * 2. Payment Intent (card element) - for card payments
 *
 * Usage in Twig (IFRAME-02f: controller hosted on a wrapper so the embedded
 * sheet can mount without the button being painted first):
 * <div data-controller="order-submit"
 *      data-order-submit-url-value="..."
 *      data-order-submit-payment-type-value="wallet|card"
 *      data-order-submit-render-mode-value="iframe|redirect"
 *      data-order-submit-eager-value="true|false"
 *      data-order-submit-stripe-debug-value="false">
 *   <button data-order-submit-target="button"
 *           data-action="click->order-submit#handleSubmit"
 *           type="button">Submit Order</button>
 *   <div data-order-submit-target="embedded"></div>
 * </div>
 *
 * Phase 5: stripeDebug Stimulus value drives the shared debug() logger.
 * When false (production default), all debug() calls are no-ops.
 * When true (level=debug in admin), console output is enabled at runtime.
 */
export default class extends Controller {
  static targets = ["status", "embedded", "button"]
  static values = {
    url: String,
    paymentType: String,
    publishableKey: String,
    renderMode: { type: String, default: "redirect" },
    eager: { type: Boolean, default: false },
    stripeDebug: { type: Boolean, default: false }
  }

  /**
   * Called when controller is connected to DOM.
   *
   * Sprint 122: Register a pageshow listener so that when the browser restores
   * this page from the back-forward cache (bfcache) after a Stripe redirect,
   * hideLoading() clears the frozen mid-submit state and dispatches
   * 'oe:stripe:submit-end' — allowing agb-validation to recompute the resting
   * button state as the authoritative last step (see sprint plan §4.2).
   */
  connect() {
    this._debug = createDebugLogger(() => this.stripeDebugValue)

    this._debug('Order Submit controller connected')

    this._onPageShow = (e) => { if (e.persisted) this.hideLoading() }
    window.addEventListener('pageshow', this._onPageShow)

    // IFRAME-02f: eager (button-less) embedded mount. The template already
    // renders the button hidden in this mode, so the embedded sheet loads
    // directly with no button→iframe flash. If the eager mount cannot proceed
    // (AGB not accepted, validation error), revealButton() surfaces the button
    // as a fallback trigger.
    if (this.eagerValue && this.renderModeValue === 'iframe' && this.paymentTypeValue === 'wallet') {
      this.autoMountEmbedded()
    }
  }

  /**
   * IFRAME-02f eager mode: create the checkout session and mount the embedded
   * sheet on load. Reveals the fallback button if nothing mounted (error / validation).
   */
  async autoMountEmbedded() {
    try {
      await this.handleStripeCheckout()
    } catch (error) {
      console.error('[order-submit] eager embedded mount failed', error)
      this.presentError(error)
    }
    if (!this._embeddedCheckout) {
      this.revealButton()
    }
  }

  /**
   * Reveal the fallback Place-Order button (eager mount failed).
   */
  revealButton() {
    if (this.hasButtonTarget) {
      this.buttonTarget.hidden = false
    }
  }

  /**
   * Hide the Place-Order button (embedded sheet renders its own Pay button).
   */
  hideButton() {
    if (this.hasButtonTarget) {
      this.buttonTarget.hidden = true
    }
  }

  /**
   * Called when controller is disconnected from DOM.
   *
   * Sprint 122: Remove the pageshow listener using the exact same bound
   * reference stored in connect() — symmetric, leak-free.
   */
  disconnect() {
    forgetEmbeddedCheckout(this._embeddedCheckout)

    this._debug('Order Submit controller disconnected')

    window.removeEventListener('pageshow', this._onPageShow)
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

    this._debug('Found stripe-order controller:', controller)
    return controller
  }

  /**
   * Handle order submit button click
   * Routes to appropriate payment flow based on payment type
   * @param {Event} event - The click event
   */
  async handleSubmit(event) {
    event.preventDefault()

    this._debug('Order submit button clicked', {
      buttonId: this.hasButtonTarget ? this.buttonTarget.id : null,
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
      this.presentError(error)
    } finally {
      this.hideLoading()
    }
  }

  /**
   * Handle Stripe Checkout flow (hosted payment page)
   * Used for wallet payments (Apple Pay, Google Pay)
   */
  async handleStripeCheckout() {
    await this._ensureStripeJs()
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

    this._debug('Checkout Session created:', data.id, 'URL:', data.url)
    this._debug('Debug info:', data._debug)

    // IFRAME-02e: inline Embedded Checkout instead of redirect.
    const clientSecret = data.client_secret || data.clientSecret
    if (this.renderModeValue === 'iframe' && clientSecret) {
      await this.mountEmbeddedCheckout(stripe, clientSecret)
      return
    }

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
   * Load Stripe.js (js.stripe.com/v3) on demand if it is not already present.
   * Needed for eager embedded mount before any card element initialises it.
   * @returns {Promise<void>}
   */
  _ensureStripeJs() {
    return new Promise((resolve, reject) => {
      if (window.Stripe) { resolve(); return }
      const existing = document.querySelector('script[src="https://js.stripe.com/v3/"]')
      if (existing) {
        existing.addEventListener('load', () => resolve())
        existing.addEventListener('error', () => reject(new Error('Failed to load Stripe.js')))
        return
      }
      const s = document.createElement('script')
      s.src = 'https://js.stripe.com/v3/'
      s.async = true
      s.onload = () => resolve()
      s.onerror = () => reject(new Error('Failed to load Stripe.js'))
      document.head.appendChild(s)
    })
  }

  /**
   * IFRAME-02e: mount Stripe Embedded Checkout inline instead of redirecting.
   * The order page already loads Stripe.js and passes the publishable key.
   * @param {Stripe} stripe - the initialised Stripe instance
   * @param {string} clientSecret - Embedded Checkout client secret
   */
  async mountEmbeddedCheckout(stripe, clientSecret) {
    const mount = this.hasEmbeddedTarget
      ? this.embeddedTarget
      : document.getElementById('stripe-embedded-checkout')
    if (!mount) {
      throw new Error('Embedded checkout mount point not found')
    }

    this.setStatus(window.oStripe?.i18n?.CREATING_SESSION || '')
    // Through the page-wide registry, not directly: the OPC footer widget can
    // host an embedded sheet on this same page, and Stripe permits exactly one.
    this._embeddedCheckout = await initEmbeddedCheckoutOnce(stripe, clientSecret)
    mount.style.display = 'block'
    this._embeddedCheckout.mount(mount)

    // Embedded Checkout renders its own Pay button; hide the order button.
    this.hideButton()
    this._debug('Embedded Checkout mounted (iframe mode)')
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

    this._debug('Stripe controller ready:', {
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
      this._debug('Payment succeeded', confirmPaymentResponse.paymentIntent)
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

    this._debug('Creating payment intent via URL:', this.urlValue)

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
      this._debug('CSRF token (stoken) not found in form')
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
   * Show loading state on button.
   *
   * Sprint 123: Dispatch 'oe:stripe:submit-start' so that agb-validation
   * can lock the AGB checkbox for the duration of the submit, preventing the
   * customer from unticking it while the request is in flight. The lock is
   * automatically lifted when hideLoading() fires (error path, bfcache restore)
   * via the mirror 'oe:stripe:submit-end' event established in Sprint 122.
   */
  showLoading() {
    if (this.hasButtonTarget) {
      this.buttonTarget.disabled = true
      this.originalText = this.buttonTarget.textContent
      this.buttonTarget.textContent = window.oStripe?.i18n?.PROCESSING || 'Processing...'
    }
    document.dispatchEvent(new CustomEvent('oe:stripe:submit-start'))
  }

  /**
   * Hide loading state on button.
   *
   * Sprint 122: After restoring the button's resting DOM state, dispatch
   * 'oe:stripe:submit-end' so that agb-validation (the authority on the
   * resting disabled value) recomputes from the live checkbox. The dispatch
   * is synchronous — agb-validation's recompute runs before hideLoading()
   * returns, ensuring deterministic ordering with no listener-ordering race
   * (see sprint plan §4.2).
   *
   * This fires on three paths: normal error (finally block), bfcache restore
   * (pageshow persisted handler), and any future explicit call — all are safe
   * because hideLoading() and updateButtonStates() are idempotent.
   */
  hideLoading() {
    if (this.hasButtonTarget) {
      this.buttonTarget.disabled = false
      if (this.originalText) {
        this.buttonTarget.textContent = this.originalText
      }
    }
    document.dispatchEvent(new CustomEvent('oe:stripe:submit-end'))
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
    this._debug('Status:', message)
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
  /**
   * Show a failure to the customer.
   *
   * Messages our own server sent (declined payment, missing consent) are for the
   * customer and are shown as they are. A Stripe library error is not: "You
   * cannot have multiple Embedded Checkout objects" is written for developers,
   * and putting that in front of a shopper is worse than saying nothing. Those
   * go to the console and the customer gets the generic wording.
   *
   * @param {Error|{name?: string, message?: string}} error
   */
  presentError(error) {
    const message = error?.message || ''
    const isLibraryError = error?.name === 'IntegrationError' || /Embedded Checkout/i.test(message)

    if (isLibraryError) {
      console.error('[order-submit] Stripe library error', error)
      this.showError(window.oStripe?.i18n?.PAYMENT_FAILED || 'Payment processing failed')
      return
    }

    this.showError(message || window.oStripe?.i18n?.PAYMENT_FAILED || 'Payment processing failed')
  }

  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message
      this.statusTarget.className = 'mt-2 text-center text-danger'
    } else {
      alert('Error: ' + message)
    }
  }
}
