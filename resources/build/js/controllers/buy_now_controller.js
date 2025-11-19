import { Controller } from "@hotwired/stimulus"

/**
 * Stimulus Controller for "Buy Now" button
 *
 * Handles direct product-to-checkout flow
 *
 * Usage in Twig:
 * <div data-controller="buy-now"
 *      data-buy-now-product-id-value="..."
 *      data-buy-now-product-nid-value="..."
 *      data-buy-now-parent-id-value="..."
 *      data-buy-now-action-url-value="..."
 *      data-buy-now-csrf-token-value="...">
 *   <button data-action="buy-now#submit">Buy Now</button>
 * </div>
 */
export default class extends Controller {
  static values = {
    productId: String,
    productNid: String,
    parentId: String,
    actionUrl: String,
    csrfToken: String
  }

  static targets = ["button"]

  connect() {
    console.log('Stripe Buy Now controller connected', {
      productId: this.productIdValue,
      productNid: this.productNidValue
    })
  }

  /**
   * Handle Buy Now button click
   * @param {Event} event
   */
  submit(event) {
    event.preventDefault()

    console.log('Buy Now clicked')

    const button = event.currentTarget

    // Disable button and show loading state
    this.setLoadingState(button, true)

    // Get quantity from amount input
    const amountInput = document.getElementById('amountToBasket')
    const amount = amountInput ? amountInput.value : 1

    // Get product form data (for variants, selections, etc.)
    const productForm = document.querySelector('.js-oxProductForm')
    const formData = productForm ? new FormData(productForm) : new FormData()

    // Prepare form fields
    const fields = {
      'cl': 'stripe_checkout_onepage',
      'fnc': 'addProductAndCheckout',
      'aid': this.productIdValue,
      'anid': this.productNidValue,
      'parentid': this.parentIdValue,
      'am': amount,
      'stoken': this.csrfTokenValue
    }

    // Add variant selections from product form
    for (let [key, value] of formData.entries()) {
      if (!fields[key] && key !== 'fnc' && key !== 'cl') {
        fields[key] = value
      }
    }

    console.log('Submitting Buy Now form:', fields)

    // Create and submit hidden form
    this.submitForm(fields)
  }

  /**
   * Create hidden form and submit
   * @param {Object} fields
   */
  submitForm(fields) {
    const form = document.createElement('form')
    form.method = 'POST'
    form.action = this.actionUrlValue
    form.style.display = 'none'

    // Add all fields as hidden inputs
    Object.entries(fields).forEach(([name, value]) => {
      const input = document.createElement('input')
      input.type = 'hidden'
      input.name = name
      input.value = value
      form.appendChild(input)
    })

    // Add to DOM and submit
    document.body.appendChild(form)

    // Small delay to ensure form is in DOM
    setTimeout(() => {
      form.submit()
    }, 100)
  }

  /**
   * Set button loading state
   * @param {HTMLElement} button
   * @param {Boolean} isLoading
   */
  setLoadingState(button, isLoading) {
    if (isLoading) {
      // Store original HTML
      button.dataset.originalHtml = button.innerHTML

      // Set loading state
      button.disabled = true
      button.innerHTML = `
        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
        Processing...
      `
    } else {
      // Restore original state
      button.disabled = false
      if (button.dataset.originalHtml) {
        button.innerHTML = button.dataset.originalHtml
      }
    }
  }

  /**
   * Handle errors
   * @param {Error} error
   */
  handleError(error) {
    console.error('Buy Now error:', error)

    // Show error to user
    alert('Sorry, there was an error processing your request. Please try again.')

    // Reset button state
    if (this.hasButtonTarget) {
      this.setLoadingState(this.buttonTarget, false)
    }
  }
}
