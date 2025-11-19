var __defProp = Object.defineProperty;
var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
var __publicField = (obj, key, value) => {
  __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
  return value;
};
import { Controller } from "@hotwired/stimulus";
class buy_now_controller_default extends Controller {
  connect() {
    console.log("Stripe Buy Now controller connected", {
      productId: this.productIdValue,
      productNid: this.productNidValue
    });
  }
  /**
   * Handle Buy Now button click
   * @param {Event} event
   */
  submit(event) {
    event.preventDefault();
    console.log("Buy Now clicked");
    const button = event.currentTarget;
    this.setLoadingState(button, true);
    const amountInput = document.getElementById("amountToBasket");
    const amount = amountInput ? amountInput.value : 1;
    const productForm = document.querySelector(".js-oxProductForm");
    const formData = productForm ? new FormData(productForm) : new FormData();
    const fields = {
      "cl": "stripe_checkout_onepage",
      "fnc": "addProductAndCheckout",
      "aid": this.productIdValue,
      "anid": this.productNidValue,
      "parentid": this.parentIdValue,
      "am": amount,
      "stoken": this.csrfTokenValue
    };
    for (let [key, value] of formData.entries()) {
      if (!fields[key] && key !== "fnc" && key !== "cl") {
        fields[key] = value;
      }
    }
    console.log("Submitting Buy Now form:", fields);
    this.submitForm(fields);
  }
  /**
   * Create hidden form and submit
   * @param {Object} fields
   */
  submitForm(fields) {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = this.actionUrlValue;
    form.style.display = "none";
    Object.entries(fields).forEach(([name, value]) => {
      const input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      input.value = value;
      form.appendChild(input);
    });
    document.body.appendChild(form);
    setTimeout(() => {
      form.submit();
    }, 100);
  }
  /**
   * Set button loading state
   * @param {HTMLElement} button
   * @param {Boolean} isLoading
   */
  setLoadingState(button, isLoading) {
    if (isLoading) {
      button.dataset.originalHtml = button.innerHTML;
      button.disabled = true;
      button.innerHTML = `
        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
        Processing...
      `;
    } else {
      button.disabled = false;
      if (button.dataset.originalHtml) {
        button.innerHTML = button.dataset.originalHtml;
      }
    }
  }
  /**
   * Handle errors
   * @param {Error} error
   */
  handleError(error) {
    console.error("Buy Now error:", error);
    alert("Sorry, there was an error processing your request. Please try again.");
    if (this.hasButtonTarget) {
      this.setLoadingState(this.buttonTarget, false);
    }
  }
}
__publicField(buy_now_controller_default, "values", {
  productId: String,
  productNid: String,
  parentId: String,
  actionUrl: String,
  csrfToken: String
});
__publicField(buy_now_controller_default, "targets", ["button"]);
export {
  buy_now_controller_default as default
};
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL2J1eV9ub3dfY29udHJvbGxlci5qcyJdLAogICJzb3VyY2VzQ29udGVudCI6IFsiaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuXG4vKipcbiAqIFN0aW11bHVzIENvbnRyb2xsZXIgZm9yIFwiQnV5IE5vd1wiIGJ1dHRvblxuICpcbiAqIEhhbmRsZXMgZGlyZWN0IHByb2R1Y3QtdG8tY2hlY2tvdXQgZmxvd1xuICpcbiAqIFVzYWdlIGluIFR3aWc6XG4gKiA8ZGl2IGRhdGEtY29udHJvbGxlcj1cImJ1eS1ub3dcIlxuICogICAgICBkYXRhLWJ1eS1ub3ctcHJvZHVjdC1pZC12YWx1ZT1cIi4uLlwiXG4gKiAgICAgIGRhdGEtYnV5LW5vdy1wcm9kdWN0LW5pZC12YWx1ZT1cIi4uLlwiXG4gKiAgICAgIGRhdGEtYnV5LW5vdy1wYXJlbnQtaWQtdmFsdWU9XCIuLi5cIlxuICogICAgICBkYXRhLWJ1eS1ub3ctYWN0aW9uLXVybC12YWx1ZT1cIi4uLlwiXG4gKiAgICAgIGRhdGEtYnV5LW5vdy1jc3JmLXRva2VuLXZhbHVlPVwiLi4uXCI+XG4gKiAgIDxidXR0b24gZGF0YS1hY3Rpb249XCJidXktbm93I3N1Ym1pdFwiPkJ1eSBOb3c8L2J1dHRvbj5cbiAqIDwvZGl2PlxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIENvbnRyb2xsZXIge1xuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIHByb2R1Y3RJZDogU3RyaW5nLFxuICAgIHByb2R1Y3ROaWQ6IFN0cmluZyxcbiAgICBwYXJlbnRJZDogU3RyaW5nLFxuICAgIGFjdGlvblVybDogU3RyaW5nLFxuICAgIGNzcmZUb2tlbjogU3RyaW5nXG4gIH1cblxuICBzdGF0aWMgdGFyZ2V0cyA9IFtcImJ1dHRvblwiXVxuXG4gIGNvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ1N0cmlwZSBCdXkgTm93IGNvbnRyb2xsZXIgY29ubmVjdGVkJywge1xuICAgICAgcHJvZHVjdElkOiB0aGlzLnByb2R1Y3RJZFZhbHVlLFxuICAgICAgcHJvZHVjdE5pZDogdGhpcy5wcm9kdWN0TmlkVmFsdWVcbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBCdXkgTm93IGJ1dHRvbiBjbGlja1xuICAgKiBAcGFyYW0ge0V2ZW50fSBldmVudFxuICAgKi9cbiAgc3VibWl0KGV2ZW50KSB7XG4gICAgZXZlbnQucHJldmVudERlZmF1bHQoKVxuXG4gICAgY29uc29sZS5sb2coJ0J1eSBOb3cgY2xpY2tlZCcpXG5cbiAgICBjb25zdCBidXR0b24gPSBldmVudC5jdXJyZW50VGFyZ2V0XG5cbiAgICAvLyBEaXNhYmxlIGJ1dHRvbiBhbmQgc2hvdyBsb2FkaW5nIHN0YXRlXG4gICAgdGhpcy5zZXRMb2FkaW5nU3RhdGUoYnV0dG9uLCB0cnVlKVxuXG4gICAgLy8gR2V0IHF1YW50aXR5IGZyb20gYW1vdW50IGlucHV0XG4gICAgY29uc3QgYW1vdW50SW5wdXQgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnYW1vdW50VG9CYXNrZXQnKVxuICAgIGNvbnN0IGFtb3VudCA9IGFtb3VudElucHV0ID8gYW1vdW50SW5wdXQudmFsdWUgOiAxXG5cbiAgICAvLyBHZXQgcHJvZHVjdCBmb3JtIGRhdGEgKGZvciB2YXJpYW50cywgc2VsZWN0aW9ucywgZXRjLilcbiAgICBjb25zdCBwcm9kdWN0Rm9ybSA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy5qcy1veFByb2R1Y3RGb3JtJylcbiAgICBjb25zdCBmb3JtRGF0YSA9IHByb2R1Y3RGb3JtID8gbmV3IEZvcm1EYXRhKHByb2R1Y3RGb3JtKSA6IG5ldyBGb3JtRGF0YSgpXG5cbiAgICAvLyBQcmVwYXJlIGZvcm0gZmllbGRzXG4gICAgY29uc3QgZmllbGRzID0ge1xuICAgICAgJ2NsJzogJ3N0cmlwZV9jaGVja291dF9vbmVwYWdlJyxcbiAgICAgICdmbmMnOiAnYWRkUHJvZHVjdEFuZENoZWNrb3V0JyxcbiAgICAgICdhaWQnOiB0aGlzLnByb2R1Y3RJZFZhbHVlLFxuICAgICAgJ2FuaWQnOiB0aGlzLnByb2R1Y3ROaWRWYWx1ZSxcbiAgICAgICdwYXJlbnRpZCc6IHRoaXMucGFyZW50SWRWYWx1ZSxcbiAgICAgICdhbSc6IGFtb3VudCxcbiAgICAgICdzdG9rZW4nOiB0aGlzLmNzcmZUb2tlblZhbHVlXG4gICAgfVxuXG4gICAgLy8gQWRkIHZhcmlhbnQgc2VsZWN0aW9ucyBmcm9tIHByb2R1Y3QgZm9ybVxuICAgIGZvciAobGV0IFtrZXksIHZhbHVlXSBvZiBmb3JtRGF0YS5lbnRyaWVzKCkpIHtcbiAgICAgIGlmICghZmllbGRzW2tleV0gJiYga2V5ICE9PSAnZm5jJyAmJiBrZXkgIT09ICdjbCcpIHtcbiAgICAgICAgZmllbGRzW2tleV0gPSB2YWx1ZVxuICAgICAgfVxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdTdWJtaXR0aW5nIEJ1eSBOb3cgZm9ybTonLCBmaWVsZHMpXG5cbiAgICAvLyBDcmVhdGUgYW5kIHN1Ym1pdCBoaWRkZW4gZm9ybVxuICAgIHRoaXMuc3VibWl0Rm9ybShmaWVsZHMpXG4gIH1cblxuICAvKipcbiAgICogQ3JlYXRlIGhpZGRlbiBmb3JtIGFuZCBzdWJtaXRcbiAgICogQHBhcmFtIHtPYmplY3R9IGZpZWxkc1xuICAgKi9cbiAgc3VibWl0Rm9ybShmaWVsZHMpIHtcbiAgICBjb25zdCBmb3JtID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZm9ybScpXG4gICAgZm9ybS5tZXRob2QgPSAnUE9TVCdcbiAgICBmb3JtLmFjdGlvbiA9IHRoaXMuYWN0aW9uVXJsVmFsdWVcbiAgICBmb3JtLnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcblxuICAgIC8vIEFkZCBhbGwgZmllbGRzIGFzIGhpZGRlbiBpbnB1dHNcbiAgICBPYmplY3QuZW50cmllcyhmaWVsZHMpLmZvckVhY2goKFtuYW1lLCB2YWx1ZV0pID0+IHtcbiAgICAgIGNvbnN0IGlucHV0ID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnaW5wdXQnKVxuICAgICAgaW5wdXQudHlwZSA9ICdoaWRkZW4nXG4gICAgICBpbnB1dC5uYW1lID0gbmFtZVxuICAgICAgaW5wdXQudmFsdWUgPSB2YWx1ZVxuICAgICAgZm9ybS5hcHBlbmRDaGlsZChpbnB1dClcbiAgICB9KVxuXG4gICAgLy8gQWRkIHRvIERPTSBhbmQgc3VibWl0XG4gICAgZG9jdW1lbnQuYm9keS5hcHBlbmRDaGlsZChmb3JtKVxuXG4gICAgLy8gU21hbGwgZGVsYXkgdG8gZW5zdXJlIGZvcm0gaXMgaW4gRE9NXG4gICAgc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICBmb3JtLnN1Ym1pdCgpXG4gICAgfSwgMTAwKVxuICB9XG5cbiAgLyoqXG4gICAqIFNldCBidXR0b24gbG9hZGluZyBzdGF0ZVxuICAgKiBAcGFyYW0ge0hUTUxFbGVtZW50fSBidXR0b25cbiAgICogQHBhcmFtIHtCb29sZWFufSBpc0xvYWRpbmdcbiAgICovXG4gIHNldExvYWRpbmdTdGF0ZShidXR0b24sIGlzTG9hZGluZykge1xuICAgIGlmIChpc0xvYWRpbmcpIHtcbiAgICAgIC8vIFN0b3JlIG9yaWdpbmFsIEhUTUxcbiAgICAgIGJ1dHRvbi5kYXRhc2V0Lm9yaWdpbmFsSHRtbCA9IGJ1dHRvbi5pbm5lckhUTUxcblxuICAgICAgLy8gU2V0IGxvYWRpbmcgc3RhdGVcbiAgICAgIGJ1dHRvbi5kaXNhYmxlZCA9IHRydWVcbiAgICAgIGJ1dHRvbi5pbm5lckhUTUwgPSBgXG4gICAgICAgIDxzcGFuIGNsYXNzPVwic3Bpbm5lci1ib3JkZXIgc3Bpbm5lci1ib3JkZXItc20gbWUtMlwiIHJvbGU9XCJzdGF0dXNcIiBhcmlhLWhpZGRlbj1cInRydWVcIj48L3NwYW4+XG4gICAgICAgIFByb2Nlc3NpbmcuLi5cbiAgICAgIGBcbiAgICB9IGVsc2Uge1xuICAgICAgLy8gUmVzdG9yZSBvcmlnaW5hbCBzdGF0ZVxuICAgICAgYnV0dG9uLmRpc2FibGVkID0gZmFsc2VcbiAgICAgIGlmIChidXR0b24uZGF0YXNldC5vcmlnaW5hbEh0bWwpIHtcbiAgICAgICAgYnV0dG9uLmlubmVySFRNTCA9IGJ1dHRvbi5kYXRhc2V0Lm9yaWdpbmFsSHRtbFxuICAgICAgfVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgZXJyb3JzXG4gICAqIEBwYXJhbSB7RXJyb3J9IGVycm9yXG4gICAqL1xuICBoYW5kbGVFcnJvcihlcnJvcikge1xuICAgIGNvbnNvbGUuZXJyb3IoJ0J1eSBOb3cgZXJyb3I6JywgZXJyb3IpXG5cbiAgICAvLyBTaG93IGVycm9yIHRvIHVzZXJcbiAgICBhbGVydCgnU29ycnksIHRoZXJlIHdhcyBhbiBlcnJvciBwcm9jZXNzaW5nIHlvdXIgcmVxdWVzdC4gUGxlYXNlIHRyeSBhZ2Fpbi4nKVxuXG4gICAgLy8gUmVzZXQgYnV0dG9uIHN0YXRlXG4gICAgaWYgKHRoaXMuaGFzQnV0dG9uVGFyZ2V0KSB7XG4gICAgICB0aGlzLnNldExvYWRpbmdTdGF0ZSh0aGlzLmJ1dHRvblRhcmdldCwgZmFsc2UpXG4gICAgfVxuICB9XG59XG4iXSwKICAibWFwcGluZ3MiOiAiOzs7Ozs7QUFBQSxTQUFTLGtCQUFrQjtBQWlCM0IsTUFBTyxtQ0FBc0IsV0FBVztBQUFBLEVBV3RDLFVBQVU7QUFDUixZQUFRLElBQUksdUNBQXVDO0FBQUEsTUFDakQsV0FBVyxLQUFLO0FBQUEsTUFDaEIsWUFBWSxLQUFLO0FBQUEsSUFDbkIsQ0FBQztBQUFBLEVBQ0g7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLEVBTUEsT0FBTyxPQUFPO0FBQ1osVUFBTSxlQUFlO0FBRXJCLFlBQVEsSUFBSSxpQkFBaUI7QUFFN0IsVUFBTSxTQUFTLE1BQU07QUFHckIsU0FBSyxnQkFBZ0IsUUFBUSxJQUFJO0FBR2pDLFVBQU0sY0FBYyxTQUFTLGVBQWUsZ0JBQWdCO0FBQzVELFVBQU0sU0FBUyxjQUFjLFlBQVksUUFBUTtBQUdqRCxVQUFNLGNBQWMsU0FBUyxjQUFjLG1CQUFtQjtBQUM5RCxVQUFNLFdBQVcsY0FBYyxJQUFJLFNBQVMsV0FBVyxJQUFJLElBQUksU0FBUztBQUd4RSxVQUFNLFNBQVM7QUFBQSxNQUNiLE1BQU07QUFBQSxNQUNOLE9BQU87QUFBQSxNQUNQLE9BQU8sS0FBSztBQUFBLE1BQ1osUUFBUSxLQUFLO0FBQUEsTUFDYixZQUFZLEtBQUs7QUFBQSxNQUNqQixNQUFNO0FBQUEsTUFDTixVQUFVLEtBQUs7QUFBQSxJQUNqQjtBQUdBLGFBQVMsQ0FBQyxLQUFLLEtBQUssS0FBSyxTQUFTLFFBQVEsR0FBRztBQUMzQyxVQUFJLENBQUMsT0FBTyxHQUFHLEtBQUssUUFBUSxTQUFTLFFBQVEsTUFBTTtBQUNqRCxlQUFPLEdBQUcsSUFBSTtBQUFBLE1BQ2hCO0FBQUEsSUFDRjtBQUVBLFlBQVEsSUFBSSw0QkFBNEIsTUFBTTtBQUc5QyxTQUFLLFdBQVcsTUFBTTtBQUFBLEVBQ3hCO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxFQU1BLFdBQVcsUUFBUTtBQUNqQixVQUFNLE9BQU8sU0FBUyxjQUFjLE1BQU07QUFDMUMsU0FBSyxTQUFTO0FBQ2QsU0FBSyxTQUFTLEtBQUs7QUFDbkIsU0FBSyxNQUFNLFVBQVU7QUFHckIsV0FBTyxRQUFRLE1BQU0sRUFBRSxRQUFRLENBQUMsQ0FBQyxNQUFNLEtBQUssTUFBTTtBQUNoRCxZQUFNLFFBQVEsU0FBUyxjQUFjLE9BQU87QUFDNUMsWUFBTSxPQUFPO0FBQ2IsWUFBTSxPQUFPO0FBQ2IsWUFBTSxRQUFRO0FBQ2QsV0FBSyxZQUFZLEtBQUs7QUFBQSxJQUN4QixDQUFDO0FBR0QsYUFBUyxLQUFLLFlBQVksSUFBSTtBQUc5QixlQUFXLE1BQU07QUFDZixXQUFLLE9BQU87QUFBQSxJQUNkLEdBQUcsR0FBRztBQUFBLEVBQ1I7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsRUFPQSxnQkFBZ0IsUUFBUSxXQUFXO0FBQ2pDLFFBQUksV0FBVztBQUViLGFBQU8sUUFBUSxlQUFlLE9BQU87QUFHckMsYUFBTyxXQUFXO0FBQ2xCLGFBQU8sWUFBWTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBSXJCLE9BQU87QUFFTCxhQUFPLFdBQVc7QUFDbEIsVUFBSSxPQUFPLFFBQVEsY0FBYztBQUMvQixlQUFPLFlBQVksT0FBTyxRQUFRO0FBQUEsTUFDcEM7QUFBQSxJQUNGO0FBQUEsRUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsRUFNQSxZQUFZLE9BQU87QUFDakIsWUFBUSxNQUFNLGtCQUFrQixLQUFLO0FBR3JDLFVBQU0sc0VBQXNFO0FBRzVFLFFBQUksS0FBSyxpQkFBaUI7QUFDeEIsV0FBSyxnQkFBZ0IsS0FBSyxjQUFjLEtBQUs7QUFBQSxJQUMvQztBQUFBLEVBQ0Y7QUFDRjtBQW5JRSxjQURLLDRCQUNFLFVBQVM7QUFBQSxFQUNkLFdBQVc7QUFBQSxFQUNYLFlBQVk7QUFBQSxFQUNaLFVBQVU7QUFBQSxFQUNWLFdBQVc7QUFBQSxFQUNYLFdBQVc7QUFDYjtBQUVBLGNBVEssNEJBU0UsV0FBVSxDQUFDLFFBQVE7IiwKICAibmFtZXMiOiBbXQp9Cg==
