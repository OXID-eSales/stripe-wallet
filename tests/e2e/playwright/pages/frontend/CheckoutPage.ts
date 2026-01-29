import { Page } from '@playwright/test';
import { BasePage } from './BasePage';

export class CheckoutPage extends BasePage {
  private readonly selectors = {
    stripeWalletPayment: 'label:has-text("Digitale Börse"), label:has-text("Stripe")',
    continueButton: 'button:has-text("Weiter"), button:has-text("Continue"), button.nextStep',
    termsCheckbox: '#checkAgb, input[name*="ord_agb"]',
    submitOrderButton: '#stripe-checkout-btn',
    submitOrderButtonAlt: 'button:has-text("Zahlungspflichtig bestellen"), #orderConfirmAgbBottom',
  };

  async navigateToCart(): Promise<void> {
    await this.navigate('/warenkorb/');
    await this.waitForPageLoad();
  }

  async startCheckout(): Promise<void> {
    const checkoutBtn = this.page.locator('a:has-text("Zur Kasse"), a:has-text("Checkout"), button:has-text("Zur Kasse")').first();
    await checkoutBtn.waitFor({ state: 'visible', timeout: 10000 });
    await checkoutBtn.click();
    await this.waitForPageLoad();
  }

  async selectStripeWalletPayment(): Promise<void> {
    const paymentLabel = this.page.locator(this.selectors.stripeWalletPayment).first();
    if (await paymentLabel.isVisible({ timeout: 2000 }).catch(() => false)) {
      await paymentLabel.click();
      await this.page.waitForTimeout(500);
    }
  }

  async clickContinue(): Promise<void> {
    const continueBtn = this.page.locator(this.selectors.continueButton).first();
    if (await continueBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
      await continueBtn.click();
      await this.waitForPageLoad();
    }
  }

  async navigateThroughCheckoutSteps(): Promise<void> {
    let maxSteps = 5;
    while (maxSteps > 0) {
      await this.page.waitForTimeout(1000);
      const currentUrl = this.page.url();

      // Check if we're on the order confirmation page
      if (currentUrl.includes('cl=order') || currentUrl.includes('thankyou')) {
        break;
      }

      // Select Stripe payment if visible
      await this.selectStripeWalletPayment();

      // Click continue
      await this.clickContinue();
      maxSteps--;
    }
  }

  async acceptTerms(): Promise<void> {
    const termsCheckbox = this.page.locator(this.selectors.termsCheckbox).first();
    if (await termsCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      if (!await termsCheckbox.isChecked()) {
        await termsCheckbox.check();
      }
    }
  }

  async submitOrder(): Promise<void> {
    await this.acceptTerms();

    // Try Stripe submit button first (with Stimulus controller)
    let submitBtn = this.page.locator(this.selectors.submitOrderButton).first();
    if (await submitBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
      // Wait for Stimulus controller to be connected
      await this.page.waitForFunction(() => {
        const btn = document.getElementById('stripe-checkout-btn');
        return btn && btn.hasAttribute('data-controller');
      }, { timeout: 10000 });
      await this.page.waitForTimeout(500);
      await submitBtn.click();
      return;
    }

    // Fallback to alternative submit button
    submitBtn = this.page.locator(this.selectors.submitOrderButtonAlt).first();
    if (await submitBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await submitBtn.click();
    }
  }

  async waitForStripeRedirect(): Promise<boolean> {
    return this.page.waitForURL(/checkout\.stripe\.com/, { timeout: 30000 })
      .then(() => true)
      .catch(() => false);
  }

  isOnOrderPage(): boolean {
    return this.page.url().includes('cl=order');
  }
}
