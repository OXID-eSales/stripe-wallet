import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class ProductPage extends BasePage {
  readonly addToCartButton: Locator;
  readonly productTitle: Locator;
  readonly productPrice: Locator;
  readonly toCartButton: Locator;

  constructor(page: Page) {
    super(page);
    this.addToCartButton = page.locator('#toBasket, button:has-text("In den Warenkorb"), button:has-text("Add to cart"), .add-to-cart');
    this.productTitle = page.locator('h1.product-title, h1');
    this.productPrice = page.locator('.product-price, .price');
    this.toCartButton = page.locator('a:has-text("Warenkorb"), a:has-text("Cart"), a[href*="basket"]');
  }

  async addToCart(): Promise<void> {
    await this.addToCartButton.click();
    await this.page.waitForTimeout(2000);
  }

  async goToCart(): Promise<void> {
    await this.toCartButton.click();
    await this.waitForPageLoad();
  }
}
