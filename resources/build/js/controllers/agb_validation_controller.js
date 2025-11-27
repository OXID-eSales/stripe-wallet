import { Controller } from '@hotwired/stimulus'

/**
 * Stimulus controller for AGB (Terms and Conditions) checkbox validation
 *
 * This controller handles the validation of the AGB checkbox on the order page.
 * When blConfirmAGB is enabled, it prevents order submission until the checkbox is checked.
 *
 * Usage in template:
 * <div data-controller="agb-validation" data-agb-validation-enabled-value="true">
 *   <input type="checkbox" data-agb-validation-target="checkbox" />
 *   <button data-agb-validation-target="submitButton">Order</button>
 * </div>
 */
export default class extends Controller {
  static targets = ['checkbox', 'submitButton']
  static values = {
    enabled: Boolean
  }

  /**
   * Initialize the controller when connected to the DOM
   */
  connect() {
    console.log('AGB Validation controller connected', {
      enabled: this.enabledValue,
      hasCheckbox: this.hasCheckboxTarget,
      hasSubmitButtons: this.hasSubmitButtonTarget
    })

    // Only apply validation if blConfirmAGB is enabled
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
   * Update the disabled state of all submit buttons based on checkbox state
   */
  updateButtonStates() {
    if (!this.hasCheckboxTarget || !this.hasSubmitButtonTarget) {
      return
    }

    const isChecked = this.checkboxTarget.checked

    // Update all submit buttons
    this.submitButtonTargets.forEach(button => {
      button.disabled = !isChecked

      // Add visual feedback
      if (isChecked) {
        button.classList.remove('disabled')
        button.removeAttribute('title')
      } else {
        button.classList.add('disabled')
        button.setAttribute('title', 'Please accept the terms and conditions')
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

    if (!this.hasCheckboxTarget || !this.checkboxTarget.checked) {
      event.preventDefault()
      event.stopPropagation()

      // Show visual feedback
      if (this.hasCheckboxTarget) {
        const checkboxWrapper = this.checkboxTarget.closest('.form-check')
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
