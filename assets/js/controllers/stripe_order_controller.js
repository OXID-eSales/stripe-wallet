var __defProp = Object.defineProperty;
var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
var __publicField = (obj, key, value) => {
  __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
  return value;
};
import { Controller } from "@hotwired/stimulus";
class stripe_order_controller_default extends Controller {
  connect() {
    console.log("Stripe Order controller connected", {
      hasPublishableKey: !!this.publishableKeyValue,
      hasClientSecret: !!this.clientSecretValue,
      publishableKey: this.publishableKeyValue ? this.publishableKeyValue.substring(0, 10) + "..." : "missing",
      clientSecretLength: this.clientSecretValue ? this.clientSecretValue.length : 0
    });
    debugger;
    const debugInfo = this.element.getAttribute("data-debug-info");
    if (debugInfo) {
      console.log("Debug info:", debugInfo);
    }
    if (!this.publishableKeyValue) {
      console.error("Stripe publishable key not configured");
      this.showError("Stripe configuration error. Please contact support.");
      return;
    }
    if (!this.clientSecretValue) {
      console.warn("\u26A0\uFE0F Stripe client secret not available", {
        message: "The backend did not generate a PaymentIntent client secret.",
        possibleReasons: [
          "1. Payment method not detected as Stripe (check payment ID = osc_stripe_card)",
          "2. User not logged in or session issue",
          "3. Backend error creating PaymentIntent (check PHP logs)",
          "4. StripePaymentService not properly configured"
        ],
        nextSteps: "Check browser Network tab and PHP error logs"
      });
      this.showError("Payment initialization failed. Please refresh the page or contact support.");
      return;
    }
    this.initializeStripe();
  }
  disconnect() {
    if (this.paymentElement) {
      this.paymentElement.unmount();
    }
  }
  /**
   * Initialize Stripe and mount Payment Element
   */
  async initializeStripe() {
    if (typeof Stripe === "undefined") {
      console.log("Waiting for Stripe.js to load...");
      await this.waitForStripe();
    }
    try {
      this.stripe = Stripe(this.publishableKeyValue);
      const appearance = {
        theme: "stripe",
        variables: {
          colorPrimary: "#0570de",
          colorBackground: "#ffffff",
          colorText: "#30313d",
          fontFamily: "system-ui, sans-serif",
          borderRadius: "4px"
        }
      };
      this.elements = this.stripe.elements({
        clientSecret: this.clientSecretValue,
        appearance
      });
      this.paymentElement = this.elements.create("payment");
      this.paymentElement.mount("#payment-element");
      this.paymentElement.on("ready", () => {
        console.log("Payment Element ready");
        this.hideLoading();
      });
      this.paymentElement.on("change", (event) => {
        if (event.error) {
          this.showError(event.error.message);
        } else {
          this.hideError();
        }
      });
      console.log("Stripe Payment Element initialized successfully");
    } catch (error) {
      console.error("Failed to initialize Stripe:", error);
      this.showError("Failed to initialize payment form. Please refresh the page.");
    }
  }
  /**
   * Wait for Stripe.js library to load
   * @returns {Promise}
   */
  waitForStripe() {
    return new Promise((resolve) => {
      const checkStripe = () => {
        if (typeof Stripe !== "undefined") {
          resolve();
        } else {
          setTimeout(checkStripe, 100);
        }
      };
      checkStripe();
    });
  }
  /**
   * Show loading indicator
   */
  showLoading() {
    if (this.hasLoadingTarget) {
      this.loadingTarget.style.display = "block";
    }
  }
  /**
   * Show error message
   * @param {String} message
   */
  showError(message) {
    const errorDiv = document.getElementById("payment-errors");
    if (errorDiv && this.hasErrorMessageTarget) {
      errorDiv.style.display = "block";
      this.errorMessageTarget.textContent = message;
    }
  }
  /**
   * Hide error message
   */
  hideError() {
    const errorDiv = document.getElementById("payment-errors");
    if (errorDiv) {
      errorDiv.style.display = "none";
      if (this.hasErrorMessageTarget) {
        this.errorMessageTarget.textContent = "";
      }
    }
  }
  /**
   * Hide loading indicator
   */
  hideLoading() {
    if (this.hasLoadingTarget) {
      this.loadingTarget.style.display = "none";
    }
  }
  /**
   * Get Stripe instance (for form submission handler)
   * @returns {Object} Stripe instance
   */
  getStripe() {
    return this.stripe;
  }
  /**
   * Get Elements instance (for form submission handler)
   * @returns {Object} Elements instance
   */
  getElements() {
    return this.elements;
  }
  /**
   * Handle order form submission
   * This method should be called when the order confirmation button is clicked
   * @param {Event} event - Form submission event
   */
  async handlePayment(event) {
    event.preventDefault();
    if (!this.stripe || !this.elements) {
      this.showError("Payment form not initialized. Please refresh the page.");
      return;
    }
    this.showLoading();
    this.hideError();
    try {
      const shopUrl = window.location.origin + window.location.pathname.split("/index.php")[0];
      const returnUrl = shopUrl + "/index.php?cl=order&fnc=stripeReturn";
      console.log("Confirming payment with return URL:", returnUrl);
      const { error } = await this.stripe.confirmPayment({
        elements: this.elements,
        confirmParams: {
          return_url: returnUrl
        }
      });
      if (error) {
        console.error("Payment confirmation error:", error);
        if (error.type === "card_error" || error.type === "validation_error") {
          this.showError(error.message);
        } else {
          this.showError("An unexpected error occurred. Please try again.");
        }
      }
    } catch (error) {
      console.error("Payment processing error:", error);
      this.showError("Payment processing failed. Please try again.");
    } finally {
      this.hideLoading();
    }
  }
}
__publicField(stripe_order_controller_default, "values", {
  publishableKey: String,
  clientSecret: String
});
__publicField(stripe_order_controller_default, "targets", ["errorMessage", "loading"]);
export {
  stripe_order_controller_default as default
};
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL3N0cmlwZV9vcmRlcl9jb250cm9sbGVyLmpzIl0sCiAgInNvdXJjZXNDb250ZW50IjogWyJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8qKlxuICogU3RpbXVsdXMgQ29udHJvbGxlciBmb3IgU3RyaXBlIFBheW1lbnQgRWxlbWVudCBvbiBPcmRlciBQYWdlXG4gKlxuICogSGFuZGxlcyBTdHJpcGUgcGF5bWVudCBmb3JtIGluaXRpYWxpemF0aW9uIGFuZCBzdWJtaXNzaW9uIG9uIHRoZSBvcmRlciBjb25maXJtYXRpb24gcGFnZVxuICpcbiAqIFVzYWdlIGluIFR3aWc6XG4gKiA8ZGl2IGRhdGEtY29udHJvbGxlcj1cInN0cmlwZS1vcmRlclwiXG4gKiAgICAgIGRhdGEtc3RyaXBlLW9yZGVyLXB1Ymxpc2hhYmxlLWtleS12YWx1ZT1cInBrXy4uLlwiXG4gKiAgICAgIGRhdGEtc3RyaXBlLW9yZGVyLWNsaWVudC1zZWNyZXQtdmFsdWU9XCJwaV8uLi5fc2VjcmV0Xy4uLlwiPlxuICogICA8ZGl2IGlkPVwicGF5bWVudC1lbGVtZW50XCI+PC9kaXY+XG4gKiAgIDxkaXYgaWQ9XCJwYXltZW50LWVycm9yc1wiIHN0eWxlPVwiZGlzcGxheTpub25lXCI+XG4gKiAgICAgPHNwYW4gZGF0YS1zdHJpcGUtb3JkZXItdGFyZ2V0PVwiZXJyb3JNZXNzYWdlXCI+PC9zcGFuPlxuICogICA8L2Rpdj5cbiAqIDwvZGl2PlxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIENvbnRyb2xsZXIge1xuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIHB1Ymxpc2hhYmxlS2V5OiBTdHJpbmcsXG4gICAgY2xpZW50U2VjcmV0OiBTdHJpbmdcbiAgfVxuXG4gIHN0YXRpYyB0YXJnZXRzID0gW1wiZXJyb3JNZXNzYWdlXCIsIFwibG9hZGluZ1wiXVxuXG4gIGNvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ1N0cmlwZSBPcmRlciBjb250cm9sbGVyIGNvbm5lY3RlZCcsIHtcbiAgICAgIGhhc1B1Ymxpc2hhYmxlS2V5OiAhIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSxcbiAgICAgIGhhc0NsaWVudFNlY3JldDogISF0aGlzLmNsaWVudFNlY3JldFZhbHVlLFxuICAgICAgcHVibGlzaGFibGVLZXk6IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSA/IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZS5zdWJzdHJpbmcoMCwgMTApICsgJy4uLicgOiAnbWlzc2luZycsXG4gICAgICBjbGllbnRTZWNyZXRMZW5ndGg6IHRoaXMuY2xpZW50U2VjcmV0VmFsdWUgPyB0aGlzLmNsaWVudFNlY3JldFZhbHVlLmxlbmd0aCA6IDBcbiAgICB9KVxuZGVidWdnZXJcbiAgICAvLyBHZXQgZGVidWcgaW5mbyBmcm9tIGVsZW1lbnRcbiAgICBjb25zdCBkZWJ1Z0luZm8gPSB0aGlzLmVsZW1lbnQuZ2V0QXR0cmlidXRlKCdkYXRhLWRlYnVnLWluZm8nKVxuICAgIGlmIChkZWJ1Z0luZm8pIHtcbiAgICAgIGNvbnNvbGUubG9nKCdEZWJ1ZyBpbmZvOicsIGRlYnVnSW5mbylcbiAgICB9XG5cbiAgICAvLyBWYWxpZGF0ZSByZXF1aXJlZCBjb25maWd1cmF0aW9uXG4gICAgaWYgKCF0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1N0cmlwZSBwdWJsaXNoYWJsZSBrZXkgbm90IGNvbmZpZ3VyZWQnKVxuICAgICAgdGhpcy5zaG93RXJyb3IoJ1N0cmlwZSBjb25maWd1cmF0aW9uIGVycm9yLiBQbGVhc2UgY29udGFjdCBzdXBwb3J0LicpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICBpZiAoIXRoaXMuY2xpZW50U2VjcmV0VmFsdWUpIHtcbiAgICAgIGNvbnNvbGUud2FybignXHUyNkEwXHVGRTBGIFN0cmlwZSBjbGllbnQgc2VjcmV0IG5vdCBhdmFpbGFibGUnLCB7XG4gICAgICAgIG1lc3NhZ2U6ICdUaGUgYmFja2VuZCBkaWQgbm90IGdlbmVyYXRlIGEgUGF5bWVudEludGVudCBjbGllbnQgc2VjcmV0LicsXG4gICAgICAgIHBvc3NpYmxlUmVhc29uczogW1xuICAgICAgICAgICcxLiBQYXltZW50IG1ldGhvZCBub3QgZGV0ZWN0ZWQgYXMgU3RyaXBlIChjaGVjayBwYXltZW50IElEID0gb3NjX3N0cmlwZV9jYXJkKScsXG4gICAgICAgICAgJzIuIFVzZXIgbm90IGxvZ2dlZCBpbiBvciBzZXNzaW9uIGlzc3VlJyxcbiAgICAgICAgICAnMy4gQmFja2VuZCBlcnJvciBjcmVhdGluZyBQYXltZW50SW50ZW50IChjaGVjayBQSFAgbG9ncyknLFxuICAgICAgICAgICc0LiBTdHJpcGVQYXltZW50U2VydmljZSBub3QgcHJvcGVybHkgY29uZmlndXJlZCdcbiAgICAgICAgXSxcbiAgICAgICAgbmV4dFN0ZXBzOiAnQ2hlY2sgYnJvd3NlciBOZXR3b3JrIHRhYiBhbmQgUEhQIGVycm9yIGxvZ3MnXG4gICAgICB9KVxuXG4gICAgICAvLyBTaG93IHVzZXItZnJpZW5kbHkgbWVzc2FnZVxuICAgICAgdGhpcy5zaG93RXJyb3IoJ1BheW1lbnQgaW5pdGlhbGl6YXRpb24gZmFpbGVkLiBQbGVhc2UgcmVmcmVzaCB0aGUgcGFnZSBvciBjb250YWN0IHN1cHBvcnQuJylcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIC8vIFdhaXQgZm9yIFN0cmlwZS5qcyB0byBsb2FkXG4gICAgdGhpcy5pbml0aWFsaXplU3RyaXBlKClcbiAgfVxuXG4gIGRpc2Nvbm5lY3QoKSB7XG4gICAgLy8gQ2xlYW51cCBpZiBuZWVkZWRcbiAgICBpZiAodGhpcy5wYXltZW50RWxlbWVudCkge1xuICAgICAgdGhpcy5wYXltZW50RWxlbWVudC51bm1vdW50KClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSW5pdGlhbGl6ZSBTdHJpcGUgYW5kIG1vdW50IFBheW1lbnQgRWxlbWVudFxuICAgKi9cbiAgYXN5bmMgaW5pdGlhbGl6ZVN0cmlwZSgpIHtcbiAgICAvLyBXYWl0IGZvciBTdHJpcGUuanMgdG8gYmUgYXZhaWxhYmxlXG4gICAgaWYgKHR5cGVvZiBTdHJpcGUgPT09ICd1bmRlZmluZWQnKSB7XG4gICAgICBjb25zb2xlLmxvZygnV2FpdGluZyBmb3IgU3RyaXBlLmpzIHRvIGxvYWQuLi4nKVxuICAgICAgYXdhaXQgdGhpcy53YWl0Rm9yU3RyaXBlKClcbiAgICB9XG5cbiAgICB0cnkge1xuICAgICAgLy8gSW5pdGlhbGl6ZSBTdHJpcGVcbiAgICAgIHRoaXMuc3RyaXBlID0gU3RyaXBlKHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSlcblxuICAgICAgLy8gQ3JlYXRlIEVsZW1lbnRzIHdpdGggc3R5bGluZ1xuICAgICAgY29uc3QgYXBwZWFyYW5jZSA9IHtcbiAgICAgICAgdGhlbWU6ICdzdHJpcGUnLFxuICAgICAgICB2YXJpYWJsZXM6IHtcbiAgICAgICAgICBjb2xvclByaW1hcnk6ICcjMDU3MGRlJyxcbiAgICAgICAgICBjb2xvckJhY2tncm91bmQ6ICcjZmZmZmZmJyxcbiAgICAgICAgICBjb2xvclRleHQ6ICcjMzAzMTNkJyxcbiAgICAgICAgICBmb250RmFtaWx5OiAnc3lzdGVtLXVpLCBzYW5zLXNlcmlmJyxcbiAgICAgICAgICBib3JkZXJSYWRpdXM6ICc0cHgnXG4gICAgICAgIH1cbiAgICAgIH1cblxuICAgICAgdGhpcy5lbGVtZW50cyA9IHRoaXMuc3RyaXBlLmVsZW1lbnRzKHtcbiAgICAgICAgY2xpZW50U2VjcmV0OiB0aGlzLmNsaWVudFNlY3JldFZhbHVlLFxuICAgICAgICBhcHBlYXJhbmNlOiBhcHBlYXJhbmNlXG4gICAgICB9KVxuXG4gICAgICAvLyBDcmVhdGUgYW5kIG1vdW50IFBheW1lbnQgRWxlbWVudFxuICAgICAgdGhpcy5wYXltZW50RWxlbWVudCA9IHRoaXMuZWxlbWVudHMuY3JlYXRlKCdwYXltZW50JylcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQubW91bnQoJyNwYXltZW50LWVsZW1lbnQnKVxuXG4gICAgICAvLyBIYW5kbGUgcmVhZHkgZXZlbnRcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQub24oJ3JlYWR5JywgKCkgPT4ge1xuICAgICAgICBjb25zb2xlLmxvZygnUGF5bWVudCBFbGVtZW50IHJlYWR5JylcbiAgICAgICAgdGhpcy5oaWRlTG9hZGluZygpXG4gICAgICB9KVxuXG4gICAgICAvLyBIYW5kbGUgdmFsaWRhdGlvbiBlcnJvcnNcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQub24oJ2NoYW5nZScsIChldmVudCkgPT4ge1xuICAgICAgICBpZiAoZXZlbnQuZXJyb3IpIHtcbiAgICAgICAgICB0aGlzLnNob3dFcnJvcihldmVudC5lcnJvci5tZXNzYWdlKVxuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgIHRoaXMuaGlkZUVycm9yKClcbiAgICAgICAgfVxuICAgICAgfSlcblxuICAgICAgY29uc29sZS5sb2coJ1N0cmlwZSBQYXltZW50IEVsZW1lbnQgaW5pdGlhbGl6ZWQgc3VjY2Vzc2Z1bGx5JylcblxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBTdHJpcGU6JywgZXJyb3IpXG4gICAgICB0aGlzLnNob3dFcnJvcignRmFpbGVkIHRvIGluaXRpYWxpemUgcGF5bWVudCBmb3JtLiBQbGVhc2UgcmVmcmVzaCB0aGUgcGFnZS4nKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBXYWl0IGZvciBTdHJpcGUuanMgbGlicmFyeSB0byBsb2FkXG4gICAqIEByZXR1cm5zIHtQcm9taXNlfVxuICAgKi9cbiAgd2FpdEZvclN0cmlwZSgpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgIGNvbnN0IGNoZWNrU3RyaXBlID0gKCkgPT4ge1xuICAgICAgICBpZiAodHlwZW9mIFN0cmlwZSAhPT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgICAgICByZXNvbHZlKClcbiAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICBzZXRUaW1lb3V0KGNoZWNrU3RyaXBlLCAxMDApXG4gICAgICAgIH1cbiAgICAgIH1cbiAgICAgIGNoZWNrU3RyaXBlKClcbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgbG9hZGluZyBpbmRpY2F0b3JcbiAgICovXG4gIHNob3dMb2FkaW5nKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRpbmdUYXJnZXQpIHtcbiAgICAgIHRoaXMubG9hZGluZ1RhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBTaG93IGVycm9yIG1lc3NhZ2VcbiAgICogQHBhcmFtIHtTdHJpbmd9IG1lc3NhZ2VcbiAgICovXG4gIHNob3dFcnJvcihtZXNzYWdlKSB7XG4gICAgY29uc3QgZXJyb3JEaXYgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncGF5bWVudC1lcnJvcnMnKVxuICAgIGlmIChlcnJvckRpdiAmJiB0aGlzLmhhc0Vycm9yTWVzc2FnZVRhcmdldCkge1xuICAgICAgZXJyb3JEaXYuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICAgIHRoaXMuZXJyb3JNZXNzYWdlVGFyZ2V0LnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIaWRlIGVycm9yIG1lc3NhZ2VcbiAgICovXG4gIGhpZGVFcnJvcigpIHtcbiAgICBjb25zdCBlcnJvckRpdiA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwYXltZW50LWVycm9ycycpXG4gICAgaWYgKGVycm9yRGl2KSB7XG4gICAgICBlcnJvckRpdi5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgICBpZiAodGhpcy5oYXNFcnJvck1lc3NhZ2VUYXJnZXQpIHtcbiAgICAgICAgdGhpcy5lcnJvck1lc3NhZ2VUYXJnZXQudGV4dENvbnRlbnQgPSAnJ1xuICAgICAgfVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIaWRlIGxvYWRpbmcgaW5kaWNhdG9yXG4gICAqL1xuICBoaWRlTG9hZGluZygpIHtcbiAgICBpZiAodGhpcy5oYXNMb2FkaW5nVGFyZ2V0KSB7XG4gICAgICB0aGlzLmxvYWRpbmdUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBHZXQgU3RyaXBlIGluc3RhbmNlIChmb3IgZm9ybSBzdWJtaXNzaW9uIGhhbmRsZXIpXG4gICAqIEByZXR1cm5zIHtPYmplY3R9IFN0cmlwZSBpbnN0YW5jZVxuICAgKi9cbiAgZ2V0U3RyaXBlKCkge1xuICAgIHJldHVybiB0aGlzLnN0cmlwZVxuICB9XG5cbiAgLyoqXG4gICAqIEdldCBFbGVtZW50cyBpbnN0YW5jZSAoZm9yIGZvcm0gc3VibWlzc2lvbiBoYW5kbGVyKVxuICAgKiBAcmV0dXJucyB7T2JqZWN0fSBFbGVtZW50cyBpbnN0YW5jZVxuICAgKi9cbiAgZ2V0RWxlbWVudHMoKSB7XG4gICAgcmV0dXJuIHRoaXMuZWxlbWVudHNcbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgb3JkZXIgZm9ybSBzdWJtaXNzaW9uXG4gICAqIFRoaXMgbWV0aG9kIHNob3VsZCBiZSBjYWxsZWQgd2hlbiB0aGUgb3JkZXIgY29uZmlybWF0aW9uIGJ1dHRvbiBpcyBjbGlja2VkXG4gICAqIEBwYXJhbSB7RXZlbnR9IGV2ZW50IC0gRm9ybSBzdWJtaXNzaW9uIGV2ZW50XG4gICAqL1xuICBhc3luYyBoYW5kbGVQYXltZW50KGV2ZW50KSB7XG4gICAgZXZlbnQucHJldmVudERlZmF1bHQoKVxuXG4gICAgaWYgKCF0aGlzLnN0cmlwZSB8fCAhdGhpcy5lbGVtZW50cykge1xuICAgICAgdGhpcy5zaG93RXJyb3IoJ1BheW1lbnQgZm9ybSBub3QgaW5pdGlhbGl6ZWQuIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICB0aGlzLnNob3dMb2FkaW5nKClcbiAgICB0aGlzLmhpZGVFcnJvcigpXG5cbiAgICB0cnkge1xuICAgICAgLy8gR2V0IHRoZSByZXR1cm4gVVJMIGZyb20gY3VycmVudCBsb2NhdGlvblxuICAgICAgY29uc3Qgc2hvcFVybCA9IHdpbmRvdy5sb2NhdGlvbi5vcmlnaW4gKyB3aW5kb3cubG9jYXRpb24ucGF0aG5hbWUuc3BsaXQoJy9pbmRleC5waHAnKVswXVxuICAgICAgY29uc3QgcmV0dXJuVXJsID0gc2hvcFVybCArICcvaW5kZXgucGhwP2NsPW9yZGVyJmZuYz1zdHJpcGVSZXR1cm4nXG5cbiAgICAgIGNvbnNvbGUubG9nKCdDb25maXJtaW5nIHBheW1lbnQgd2l0aCByZXR1cm4gVVJMOicsIHJldHVyblVybClcblxuICAgICAgLy8gQ29uZmlybSBwYXltZW50IHdpdGggU3RyaXBlXG4gICAgICBjb25zdCB7IGVycm9yIH0gPSBhd2FpdCB0aGlzLnN0cmlwZS5jb25maXJtUGF5bWVudCh7XG4gICAgICAgIGVsZW1lbnRzOiB0aGlzLmVsZW1lbnRzLFxuICAgICAgICBjb25maXJtUGFyYW1zOiB7XG4gICAgICAgICAgcmV0dXJuX3VybDogcmV0dXJuVXJsLFxuICAgICAgICB9LFxuICAgICAgfSlcblxuICAgICAgLy8gVGhpcyBjb2RlIHdpbGwgb25seSBleGVjdXRlIGlmIHRoZXJlJ3MgYW4gaW1tZWRpYXRlIGVycm9yXG4gICAgICAvLyBJZiBwYXltZW50IHN1Y2NlZWRzIG9yIHJlcXVpcmVzIHJlZGlyZWN0LCB1c2VyIHdpbGwgYmUgcmVkaXJlY3RlZFxuICAgICAgaWYgKGVycm9yKSB7XG4gICAgICAgIGNvbnNvbGUuZXJyb3IoJ1BheW1lbnQgY29uZmlybWF0aW9uIGVycm9yOicsIGVycm9yKVxuXG4gICAgICAgIC8vIFNob3cgZXJyb3IgdG8gY3VzdG9tZXJcbiAgICAgICAgaWYgKGVycm9yLnR5cGUgPT09ICdjYXJkX2Vycm9yJyB8fCBlcnJvci50eXBlID09PSAndmFsaWRhdGlvbl9lcnJvcicpIHtcbiAgICAgICAgICB0aGlzLnNob3dFcnJvcihlcnJvci5tZXNzYWdlKVxuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgIHRoaXMuc2hvd0Vycm9yKCdBbiB1bmV4cGVjdGVkIGVycm9yIG9jY3VycmVkLiBQbGVhc2UgdHJ5IGFnYWluLicpXG4gICAgICAgIH1cbiAgICAgIH1cblxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdQYXltZW50IHByb2Nlc3NpbmcgZXJyb3I6JywgZXJyb3IpXG4gICAgICB0aGlzLnNob3dFcnJvcignUGF5bWVudCBwcm9jZXNzaW5nIGZhaWxlZC4gUGxlYXNlIHRyeSBhZ2Fpbi4nKVxuICAgIH0gZmluYWxseSB7XG4gICAgICB0aGlzLmhpZGVMb2FkaW5nKClcbiAgICB9XG4gIH1cbn1cbiJdLAogICJtYXBwaW5ncyI6ICI7Ozs7OztBQUFBLFNBQVMsa0JBQWtCO0FBaUIzQixNQUFPLHdDQUFzQixXQUFXO0FBQUEsRUFRdEMsVUFBVTtBQUNSLFlBQVEsSUFBSSxxQ0FBcUM7QUFBQSxNQUMvQyxtQkFBbUIsQ0FBQyxDQUFDLEtBQUs7QUFBQSxNQUMxQixpQkFBaUIsQ0FBQyxDQUFDLEtBQUs7QUFBQSxNQUN4QixnQkFBZ0IsS0FBSyxzQkFBc0IsS0FBSyxvQkFBb0IsVUFBVSxHQUFHLEVBQUUsSUFBSSxRQUFRO0FBQUEsTUFDL0Ysb0JBQW9CLEtBQUssb0JBQW9CLEtBQUssa0JBQWtCLFNBQVM7QUFBQSxJQUMvRSxDQUFDO0FBQ0w7QUFFSSxVQUFNLFlBQVksS0FBSyxRQUFRLGFBQWEsaUJBQWlCO0FBQzdELFFBQUksV0FBVztBQUNiLGNBQVEsSUFBSSxlQUFlLFNBQVM7QUFBQSxJQUN0QztBQUdBLFFBQUksQ0FBQyxLQUFLLHFCQUFxQjtBQUM3QixjQUFRLE1BQU0sdUNBQXVDO0FBQ3JELFdBQUssVUFBVSxxREFBcUQ7QUFDcEU7QUFBQSxJQUNGO0FBRUEsUUFBSSxDQUFDLEtBQUssbUJBQW1CO0FBQzNCLGNB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
