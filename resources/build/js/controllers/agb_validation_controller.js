import { Controller } from '@hotwired/stimulus'

/**
 * Stimulus controller for AGB (Terms and Conditions) checkbox validation.
 *
 * When blConfirmAGB is enabled, disables submit buttons until the customer
 * ticks the AGB checkbox and re-enables them on change.
 *
 * The AGB checkbox (#checkAgbTop) lives in the apex theme partial and
 * cannot carry a Stimulus target attribute (edit boundary). This controller
 * resolves it by its stable apex DOM ID in connect() instead.
 *
 * Usage in template:
 * <div data-controller="agb-validation" data-agb-validation-enabled-value="true">
 *   <button data-agb-validation-target="submitButton">Order</button>
 * </div>
 */
export default class extends Controller {
  static targets = ['submitButton']
  static values = {
    enabled: Boolean
  }

  /**
   * Initialize the controller when connected to the DOM.
   *
   * Sprint 101: The AGB checkbox (#checkAgbTop) lives in the apex theme
   * partial which the Stripe module may not modify (edit boundary §0 of
   * sprint plan). We resolve it by its stable apex DOM ID — the same ID
   * OXID's own agb.js consumes — and attach a change listener.
   *
   * If the checkbox is absent from the DOM (blConfirmAGB is off and apex
   * did not render it), the null guard leaves all buttons enabled, which
   * is the correct outcome for that configuration path.
   */
  connect() {
    // Resolve the apex AGB checkbox by its stable ID
    this._coreCheckbox = document.getElementById('checkAgbTop')
    if (this._coreCheckbox) {
      this._coreCheckbox.addEventListener('change', () => this.checkboxChanged())
    }

    if (this.enabledValue) {
      this.updateButtonStates()
    }
  }

  /**
   * Handle checkbox state changes
   */
  checkboxChanged() {
    if (this.enabledValue) {
      this.updateButtonStates()
    }
  }

  /**
   * Update the disabled state of all submit buttons based on checkbox state.
   *
   * Reads from this._coreCheckbox (the apex #checkAgbTop element resolved
   * in connect()). If the checkbox is not present, leaves buttons enabled.
   */
  updateButtonStates() {
    if (!this.hasSubmitButtonTarget) {
      return
    }

    // If the AGB checkbox is not rendered (blConfirmAGB off), leave buttons enabled
    if (!this._coreCheckbox) {
      return
    }

    const isChecked = this._coreCheckbox.checked

    // Update all submit buttons
    this.submitButtonTargets.forEach(button => {
      button.disabled = !isChecked

      // Add visual feedback
      if (isChecked) {
        button.classList.remove('disabled')
        button.removeAttribute('title')
      } else {
        button.classList.add('disabled')
        button.setAttribute('title', window.oStripe?.i18n?.AGB_REQUIRED || 'Please accept the terms and conditions')
      }
    })
  }

  /**
   * Handle form submission attempts
   * @param {Event} event - The submit event
   */
  handleSubmit(event) {
    if (!this.enabledValue) {
      return true
    }

    if (!this._coreCheckbox || !this._coreCheckbox.checked) {
      event.preventDefault()
      event.stopPropagation()

      // Show visual feedback on the checkbox wrapper
      if (this._coreCheckbox) {
        const checkboxWrapper = this._coreCheckbox.closest('.form-check')
        if (checkboxWrapper) {
          checkboxWrapper.classList.add('border', 'border-danger', 'p-2', 'rounded')

          // Remove the highlight after 3 seconds
          setTimeout(() => {
            checkboxWrapper.classList.remove('border', 'border-danger', 'p-2', 'rounded')
          }, 3000)
        }
      }

      return false
    }

    return true
  }
}
