import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class CheckoutPage extends BasePage {
  readonly paymentMethodStripe: Locator;
  readonly stripePayButton: Locator;
  readonly orderButton: Locator;
  readonly nextStepButton: Locator;
  readonly agbCheckbox: Locator;

  constructor(page: Page) {
    super(page);
    this.paymentMethodStripe = page.locator('input[value*="stripe"], input[id*="stripe"], label:has-text("Stripe"), label:has-text("Credit Card")');
    this.stripePayButton = page.locator('[data-stripe-checkout], .stripe-pay-button, button:has-text("Pay with Stripe"), button:has-text("Mit Stripe bezahlen")');
    this.orderButton = page.locator('#orderConfirmAgbBottom, .submitOrder, button:has-text("Order now"), button:has-text("Kaufen")');
    this.nextStepButton = page.locator('button:has-text("Weiter"), button:has-text("Continue"), button:has-text("Next"), .next-step');
    this.agbCheckbox = page.locator('#checkAgb, input[name="ord_agb"], input[type="checkbox"][name*="agb"]');
  }

  async selectStripePayment(): Promise<void> {
    await this.paymentMethodStripe.first().click();
    await this.page.waitForTimeout(1000);
  }

  async acceptTerms(): Promise<void> {
    if (await this.agbCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      await this.agbCheckbox.check();
    }
  }

  async clickPayWithStripe(): Promise<void> {
    await this.stripePayButton.click();
  }

  async clickNextStep(): Promise<void> {
    await this.nextStepButton.click();
    await this.waitForPageLoad();
  }

  async submitOrder(): Promise<void> {
    await this.acceptTerms();
    await this.orderButton.click();
  }

  async waitForStripeRedirect(): Promise<void> {
    await this.page.waitForURL(/checkout\.stripe\.com/, { timeout: 60000 });
  }
}
