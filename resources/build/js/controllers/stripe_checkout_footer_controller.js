import { Controller } from "@hotwired/stimulus"
import { withEventBus } from "../mixins/event_bus_mixin.js"

/**
 * Stripe Checkout Footer Controller
 *
 * Manages Stripe-specific footer behavior in one-page checkout:
 * - Terms validation and state management
 * - Payment processing coordination with payment controller
 * - EventBus integration for state synchronization
 * - Loading states and error handling
 * - Dynamic total price updates
 *
 * Integration:
 * - Uses EventBus mixin for automatic listener cleanup
 * - Coordinates with payment controller for actual payment processing
 * - Responds to basket and payment state changes
 *
 * Emitted Events:
 * - oe:footer:terms-accepted - When terms checkbox is checked
 * - oe:footer:submit-clicked - When submit button is clicked
 *
 * Listened Events:
 * - oe:basket:updated - Basket contents changed
 * - oe:payment:processing - Payment processing started
 * - oe:payment:complete - Payment completed successfully
 * - oe:payment:error - Payment processing failed
 *
 * @see docs/FOOTER_WIDGET_ARCHITECTURE.md
 * @see docs/EVENT_BUS_GUIDE.md
 */
export default class extends withEventBus(Controller) {
    static targets = [
        "submitButton",     // Main submit button
        "loader",           // Loading overlay
        "error",            // Error message container
        "errorMessage"      // Error message text element
    ]

    static values = {
        basketId: String,           // Current basket ID
        paymentMethod: String,      // Payment method ID (e.g., 'oxidstripe')
        totalPrice: Number,         // Total order amount
        currency: String,           // Currency code (e.g., 'EUR')
        csrfToken: String          // CSRF token for API calls
    }

    /**
     * Controller initialization
     */
    connect() {
        console.log('[StripeCheckoutFooter] Connected', {
            basketId: this.basketIdValue,
            paymentMethod: this.paymentMethodValue,
            totalPrice: this.totalPriceValue,
            currency: this.currencyValue
        })

        // Register EventBus listeners (automatic cleanup on disconnect!)
        this.setupEventListeners()

        // Initialize button state
        this.updateButtonState()
    }

    /**
     * Setup EventBus event listeners
     *
     * Uses EventBus mixin's listen() method for automatic cleanup
     */
    setupEventListeners() {
        // Listen to basket updates
        this.listen('oe:basket:updated', (data) => {
            console.log('[StripeCheckoutFooter] Basket updated', data)
            this.handleBasketUpdate(data)
        })

        // Listen to payment lifecycle events
        this.listen('oe:payment:processing', (data) => {
            console.log('[StripeCheckoutFooter] Payment processing', data)
            this.showLoader()
        })

        this.listen('oe:payment:complete', (data) => {
            console.log('[StripeCheckoutFooter] Payment complete', data)
            this.hideLoader()
            this.showSuccess()
        })

        this.listen('oe:payment:error', (data) => {
            console.log('[StripeCheckoutFooter] Payment error', data)
            this.hideLoader()
            this.showError(data.message || 'Payment processing failed')
        })

        // Listen to payment method selection changes
        this.listen('oe:payment:method-selected', (data) => {
            console.log('[StripeCheckoutFooter] Payment method selected', data)
            this.handlePaymentMethodChange(data)
        })
    }

    /**
     * NOTE: Terms validation removed - handled by checkout-footer-manager
     *
     * Terms checkbox is now in Part 1 (standard consents) of footer architecture.
     * checkout-footer-manager controller handles all terms validation.
     */

    /**
     * Process payment - Redirect to Stripe Checkout
     *
     * Creates a Stripe Checkout Session and redirects user to hosted payment page.
     * This is different from Payment Element - full page redirect instead of embedded form.
     */
    async processPayment(event) {
        event.preventDefault()

        console.log('[StripeCheckoutFooter] Submit button clicked - redirecting to Stripe Checkout')

        // Show loading state
        this.showLoader()

        try {
            // Create Stripe Checkout Session
            const session = await this.createCheckoutSession()

            console.log('[StripeCheckoutFooter] Checkout session created:', session.id)
            console.log('[StripeCheckoutFooter] Redirecting to:', session.url)

            // Redirect to Stripe hosted checkout page
            if (session.url) {
                window.location.href = session.url
            } else {
                throw new Error('Stripe Checkout URL not provided')
            }
        } catch (error) {
            console.error('[StripeCheckoutFooter] Error creating checkout session:', error)
            this.hideLoader()
            this.showError(error.message || 'Failed to start checkout. Please try again.')
        }
    }

    /**
     * Create Stripe Checkout Session via backend API
     *
     * @returns {Promise<{id: string, url: string, contract_id: string}>}
     */
    async createCheckoutSession() {
        const endpoint = this.getCheckoutSessionUrl()

        console.log('[StripeCheckoutFooter] Creating checkout session at:', endpoint)

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfTokenValue
            },
            body: JSON.stringify({
                capture: 'automatic' // Can be made configurable
            }),
            credentials: 'same-origin'
        })

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}))
            throw new Error(errorData.error || 'Failed to create checkout session')
        }

        const data = await response.json()

        if (!data.id || !data.url) {
            throw new Error('Invalid checkout session response')
        }

        return data
    }

    /**
     * Get checkout session creation URL
     *
     * @returns {string}
     */
    getCheckoutSessionUrl() {
        // Get base URL from current page
        const baseUrl = window.location.origin

        // Build URL to StripeOrderController::createCheckoutSession
        return `${baseUrl}/index.php?cl=StripeOrder&fnc=createCheckoutSession`
    }

    /**
     * Handle basket update event
     *
     * Updates total price display and validates state
     */
    handleBasketUpdate(data) {
        // Update total price if provided
        if (data.totalPrice !== undefined) {
            this.totalPriceValue = data.totalPrice
            this.updateTotalDisplay(data.totalPrice, data.currency || this.currencyValue)
        }

        // Update basket ID if changed
        if (data.basketId) {
            this.basketIdValue = data.basketId
        }

        // Re-validate button state
        this.updateButtonState()
    }

    /**
     * Handle payment method change event
     *
     * Show/hide footer based on payment method selection
     */
    handlePaymentMethodChange(data) {
        const isStripe = data.paymentMethodId === this.paymentMethodValue

        if (isStripe) {
            // Show Stripe footer
            this.element.style.display = 'block'
        } else {
            // Hide Stripe footer if different payment method selected
            this.element.style.display = 'none'
        }
    }

    /**
     * Update total price display in submit button
     */
    updateTotalDisplay(totalPrice, currency) {
        const amountElement = this.submitButtonTarget.querySelector('.button-amount')
        if (amountElement) {
            const formattedPrice = this.formatPrice(totalPrice)
            amountElement.textContent = `${formattedPrice} ${currency}`
        }
    }

    /**
     * Format price with proper decimal places
     */
    formatPrice(price) {
        return parseFloat(price).toFixed(2).replace('.', ',')
    }

    /**
     * Update submit button state
     *
     * Button is enabled by default. checkout-footer-manager handles terms validation.
     */
    updateButtonState() {
        // Button state is now controlled by checkout-footer-manager (Part 1)
        // This widget just handles payment-specific UI states
        // Keep button enabled unless explicitly disabled by loading state
    }

    /**
     * Show loading overlay
     */
    showLoader() {
        console.log('[StripeCheckoutFooter] Showing loader')

        // Show spinner in button
        const buttonContent = this.submitButtonTarget.querySelector('.button-content')
        const buttonSpinner = this.submitButtonTarget.querySelector('.button-spinner')

        if (buttonContent) buttonContent.classList.add('d-none')
        if (buttonSpinner) buttonSpinner.classList.remove('d-none')

        // Show full-screen overlay
        this.loaderTarget.style.display = 'flex'

        // Disable button
        this.submitButtonTarget.disabled = true

        // Hide any errors
        this.hideError()
    }

    /**
     * Hide loading overlay
     */
    hideLoader() {
        console.log('[StripeCheckoutFooter] Hiding loader')

        // Hide spinner in button
        const buttonContent = this.submitButtonTarget.querySelector('.button-content')
        const buttonSpinner = this.submitButtonTarget.querySelector('.button-spinner')

        if (buttonContent) buttonContent.classList.remove('d-none')
        if (buttonSpinner) buttonSpinner.classList.add('d-none')

        // Hide full-screen overlay
        this.loaderTarget.style.display = 'none'

        // Re-enable button based on terms
        this.updateButtonState()
    }

    /**
     * Show error message
     */
    showError(message) {
        console.error('[StripeCheckoutFooter] Error:', message)

        if (this.hasErrorMessageTarget) {
            this.errorMessageTarget.textContent = message
        }

        this.errorTarget.style.display = 'block'

        // Scroll error into view
        this.errorTarget.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        })
    }

    /**
     * Hide error message
     */
    hideError() {
        this.errorTarget.style.display = 'none'
    }

    /**
     * Show success state (briefly before redirect)
     */
    showSuccess() {
        console.log('[StripeCheckoutFooter] Payment successful')

        // Update button to show success
        const buttonText = this.submitButtonTarget.querySelector('.button-text')
        if (buttonText) {
            buttonText.innerHTML = '<i class="fas fa-check me-2"></i>Payment Successful'
        }

        this.submitButtonTarget.classList.remove('btn-primary')
        this.submitButtonTarget.classList.add('btn-success')

        // Success message will be shown by payment controller
        // This controller just updates the button state
    }

    /**
     * Controller cleanup
     *
     * EventBus listeners are automatically cleaned up by withEventBus mixin
     */
    disconnect() {
        console.log('[StripeCheckoutFooter] Disconnected')

        // Mixin handles EventBus cleanup automatically
        // No manual removeEventListener() needed!
    }
}