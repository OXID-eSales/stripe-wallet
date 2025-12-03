import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';

export class LoginPage extends BasePage {
  readonly emailInput: Locator;
  readonly passwordInput: Locator;
  readonly loginButton: Locator;

  constructor(page: Page) {
    super(page);
    this.emailInput = page.locator('input[name="lgn_usr"], input[type="email"], #loginEmail');
    this.passwordInput = page.locator('input[name="lgn_pwd"], input[type="password"], #loginPas498rd');
    this.loginButton = page.locator('button[type="submit"]:has-text("Login"), button:has-text("Anmelden"), .login-btn');
  }

  async login(email: string, password: string): Promise<void> {
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    await this.loginButton.click();
    await this.waitForPageLoad();
  }
}
