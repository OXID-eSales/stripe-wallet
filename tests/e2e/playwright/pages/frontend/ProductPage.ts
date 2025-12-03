import { Page, Locator } from '@playwright/test';
import { BasePage } from './BasePage';

export class ProductPage extends BasePage {
  private readonly selectors = {
    addToCartButton: '#toBasket',
    variantSelect: 'select',
    detailsButton: 'a:has-text("Details")',
    merchandiseCategory: 'a:has-text("Merchandise")',
    tshirtsCategory: 'a:has-text("T-Shirts")',
    productTitle: 'h1',
    productPrice: '.product-price, .price',
  };

  async navigateToMerchandise(): Promise<void> {
    await this.page.locator(this.selectors.merchandiseCategory).first().click();
    await this.waitForPageLoad();
  }

  async navigateToTShirts(): Promise<void> {
    const tshirtsLink = this.page.locator(this.selectors.tshirtsCategory).first();
    if (await tshirtsLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await tshirtsLink.click();
      await this.waitForPageLoad();
    }
  }

  async openFirstProductDetails(): Promise<void> {
    const detailsBtn = this.page.locator(this.selectors.detailsButton).first();
    await detailsBtn.click();
    await this.waitForPageLoad();
  }

  async selectVariantIfAvailable(): Promise<void> {
    const variantSelect = this.page.locator(this.selectors.variantSelect).first();
    if (await variantSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
      await variantSelect.selectOption({ index: 1 });
      await this.page.waitForTimeout(1000);
    }
  }

  async addToCart(): Promise<void> {
    const addBtn = this.page.locator(this.selectors.addToCartButton);
    await addBtn.waitFor({ state: 'visible', timeout: 10000 });
    await addBtn.click();
    await this.page.waitForTimeout(2000);
  }

  async addProductToCartFromCategory(): Promise<void> {
    await this.navigateToMerchandise();
    await this.navigateToTShirts();
    await this.openFirstProductDetails();
    await this.selectVariantIfAvailable();
    await this.addToCart();
  }
}
