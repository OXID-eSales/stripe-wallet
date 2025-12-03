import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class ThankYouPage extends BasePage {
  readonly orderConfirmation: Locator;
  readonly orderNumber: Locator;
  readonly thankYouMessage: Locator;

  constructor(page: Page) {
    super(page);
    this.orderConfirmation = page.locator('.thankyou, .order-confirmation, h1:has-text("Thank"), h1:has-text("Vielen Dank"), .success-message');
    this.orderNumber = page.locator('.order-number, .ordernr, [data-order-number], strong:has-text("Order")');
    this.thankYouMessage = page.locator('.thankyou-message, .confirmation-message, p:has-text("thank"), p:has-text("Danke")');
  }

  async getOrderNumber(): Promise<string> {
    const text = await this.orderNumber.textContent() || '';
    const match = text.match(/\d+/);
    return match ? match[0] : '';
  }

  async verifyOrderConfirmation(): Promise<void> {
    await expect(this.orderConfirmation).toBeVisible({ timeout: 30000 });
  }

  async isOnThankYouPage(): Promise<boolean> {
    const url = this.page.url();
    return url.includes('thankyou') || url.includes('success') || url.includes('order');
  }
}
