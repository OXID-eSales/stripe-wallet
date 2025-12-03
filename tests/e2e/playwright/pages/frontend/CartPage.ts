import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class CartPage extends BasePage {
  readonly checkoutButton: Locator;
  readonly cartTotal: Locator;
  readonly cartItems: Locator;
  readonly continueButton: Locator;

  constructor(page: Page) {
    super(page);
    this.checkoutButton = page.locator('button:has-text("Zur Kasse"), button:has-text("Checkout"), a:has-text("Zur Kasse"), .checkout-btn');
    this.cartTotal = page.locator('.cart-total, .basketTotal');
    this.cartItems = page.locator('.cart-item, .basketItem');
    this.continueButton = page.locator('button:has-text("Weiter"), button:has-text("Continue"), .continue-btn');
  }

  async goToCheckout(): Promise<void> {
    await this.checkoutButton.click();
    await this.waitForPageLoad();
  }

  async proceedToPayment(): Promise<void> {
    await this.continueButton.click();
    await this.waitForPageLoad();
  }
}
