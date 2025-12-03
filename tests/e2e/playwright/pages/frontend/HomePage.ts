import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class HomePage extends BasePage {
  readonly loginLink: Locator;
  readonly cartLink: Locator;
  readonly searchInput: Locator;
  readonly firstProduct: Locator;

  constructor(page: Page) {
    super(page);
    this.loginLink = page.locator('a:has-text("Login"), a:has-text("Anmelden"), .login-link');
    this.cartLink = page.locator('a[href*="warenkorb"], a[href*="basket"], .cart-link');
    this.searchInput = page.locator('input[name="searchparam"], #searchParam');
    this.firstProduct = page.locator('.product-item, .productData, article.product').first();
  }

  async goToHomePage(): Promise<void> {
    await this.navigate('/');
    await this.waitForPageLoad();
  }

  async clickLogin(): Promise<void> {
    await this.loginLink.click();
    await this.waitForPageLoad();
  }

  async goToCart(): Promise<void> {
    await this.cartLink.click();
    await this.waitForPageLoad();
  }
}
