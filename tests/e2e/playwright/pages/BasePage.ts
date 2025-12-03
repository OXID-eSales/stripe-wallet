import { Page } from '@playwright/test';

export abstract class BasePage {
  readonly page: Page;
  readonly baseURL: string;

  constructor(page: Page) {
    this.page = page;
    this.baseURL = process.env.SHOP_URL || 'https://localhost.local';
  }

  async navigate(path: string = ''): Promise<void> {
    await this.page.goto(`${this.baseURL}${path}`);
  }

  async waitForPageLoad(): Promise<void> {
    await this.page.waitForLoadState('networkidle');
  }

  async acceptCookies(): Promise<void> {
    const cookieButton = this.page.locator('[data-testid="cookie-accept"], .cookie-accept, #cookie-accept, button:has-text("Accept")');
    if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cookieButton.click();
    }
  }

  async switchToEnglish(): Promise<void> {
    const languageSelector = this.page.locator('a[hreflang="en"], .lang-en, a:has-text("English")');
    if (await languageSelector.isVisible({ timeout: 3000 }).catch(() => false)) {
      await languageSelector.click();
      await this.waitForPageLoad();
    }
  }
}
