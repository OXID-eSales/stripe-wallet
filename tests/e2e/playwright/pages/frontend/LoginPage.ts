import { Page } from '@playwright/test';
import { BasePage } from './BasePage';

export interface UserCredentials {
  email: string;
  password: string;
}

export const TEST_USER: UserCredentials = {
  email: process.env.TEST_USER_EMAIL || 'playwright.user@oxid-esales.dev',
  password: process.env.TEST_USER_PASSWORD || 'useruser',
};

export class LoginPage extends BasePage {
  private readonly selectors = {
    emailInput: '#loginUser',
    passwordInput: '#loginPwd',
    loginButton: '#loginButton',
  };

  async navigateToLogin(): Promise<void> {
    await this.navigate('/index.php?cl=account');
    await this.waitForPageLoad();
  }

  async login(credentials: UserCredentials = TEST_USER): Promise<void> {
    // Check if already logged in
    const emailInput = this.page.locator(this.selectors.emailInput);
    if (!await emailInput.isVisible({ timeout: 5000 }).catch(() => false)) {
      console.log('  Already logged in or login form not visible');
      return;
    }

    await emailInput.fill(credentials.email);
    await this.page.locator(this.selectors.passwordInput).fill(credentials.password);
    await this.page.locator(this.selectors.loginButton).click();
    await this.waitForPageLoad();
    await this.page.waitForTimeout(1000);
  }

  async isLoggedIn(): Promise<boolean> {
    // Check for account dashboard or logout link
    const logoutLink = this.page.locator('a:has-text("Logout"), a:has-text("Abmelden")');
    return logoutLink.isVisible({ timeout: 3000 }).catch(() => false);
  }
}
