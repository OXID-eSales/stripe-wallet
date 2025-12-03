import { Page, Locator, FrameLocator } from '@playwright/test';

export class StripeCheckoutPage {
  readonly page: Page;
  readonly emailInput: Locator;
  readonly cardNumberInput: Locator;
  readonly expiryInput: Locator;
  readonly cvcInput: Locator;
  readonly cardholderNameInput: Locator;
  readonly payButton: Locator;
  readonly cardFrame: FrameLocator;

  constructor(page: Page) {
    this.page = page;
    this.emailInput = page.locator('#email');
    this.cardNumberInput = page.locator('#cardNumber');
    this.expiryInput = page.locator('#cardExpiry');
    this.cvcInput = page.locator('#cardCvc');
    this.cardholderNameInput = page.locator('#billingName');
    this.payButton = page.locator('.SubmitButton, button[type="submit"]');
    this.cardFrame = page.frameLocator('iframe[name*="__privateStripeFrame"]');
  }

  async fillTestCard(cardNumber: string = '4111111111111111'): Promise<void> {
    await this.cardNumberInput.fill(cardNumber);
    await this.expiryInput.fill('12/30');
    await this.cvcInput.fill('111');
  }

  async fillCardholderName(name: string = 'Marc Muster'): Promise<void> {
    if (await this.cardholderNameInput.isVisible({ timeout: 5000 }).catch(() => false)) {
      await this.cardholderNameInput.fill(name);
    }
  }

  async fillEmail(email: string): Promise<void> {
    if (await this.emailInput.isVisible({ timeout: 5000 }).catch(() => false)) {
      await this.emailInput.fill(email);
    }
  }

  async submitPayment(): Promise<void> {
    await this.payButton.click();
  }

  async completePayment(email: string, cardNumber: string = '4111111111111111'): Promise<void> {
    await this.page.waitForLoadState('networkidle');
    await this.fillEmail(email);
    await this.fillTestCard(cardNumber);
    await this.fillCardholderName('Marc Muster');
    await this.submitPayment();
  }

  async waitForRedirectBack(shopUrl: string): Promise<void> {
    const escapedUrl = shopUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    await this.page.waitForURL(new RegExp(escapedUrl), { timeout: 90000 });
  }
}
