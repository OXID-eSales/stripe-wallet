import { Page, Frame } from '@playwright/test';
import { AdminBasePage } from './AdminBasePage';

/**
 * Admin Module Settings Page Object
 *
 * Handles navigation to and modification of Stripe module settings.
 */
export class AdminModuleSettingsPage extends AdminBasePage {
  private readonly selectors = {
    extensionsLink: 'a:has-text("Extensions")',
    modulesLink: 'a:has-text("Modules")',
    stripeModule: 'text=oe_payments_stripe_wallet',
    settingsTab: 'a:has-text("Settings")',
    captureModeSelect: 'select[name="confselects[sStripeCaptureMode]"]',
    saveButton: 'input[type="submit"], button[type="submit"]',
  };

  /**
   * Navigate to module settings
   */
  async navigateToModuleSettings(): Promise<void> {
    const menuFrame = this.getMenuFrame();
    if (!menuFrame) {
      throw new Error('Menu frame not found');
    }

    // Click Extensions in sidebar
    const extensionsLink = menuFrame.locator(this.selectors.extensionsLink);
    await extensionsLink.click();
    await this.page.waitForTimeout(1000);

    // Click Modules
    const modulesLink = menuFrame.locator(this.selectors.modulesLink);
    if (await modulesLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await modulesLink.click();
      await this.page.waitForTimeout(2000);
    }

    // Wait for list frame
    await this.page.waitForTimeout(2000);
  }

  /**
   * Select the Stripe module from the list
   */
  async selectStripeModule(): Promise<void> {
    const listFrame = this.getListFrame();
    if (!listFrame) {
      throw new Error('List frame not found');
    }

    // Find and click on Stripe module
    const stripeModule = listFrame.locator(this.selectors.stripeModule).first();
    if (await stripeModule.isVisible({ timeout: 5000 }).catch(() => false)) {
      await stripeModule.click();
      await this.page.waitForTimeout(2000);
    } else {
      // Try clicking on module ID directly
      const moduleLink = listFrame.locator('a:has-text("osc_stripe")').first();
      if (await moduleLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        await moduleLink.click();
        await this.page.waitForTimeout(2000);
      }
    }
  }

  /**
   * Open the Settings tab for the module
   */
  async openSettingsTab(): Promise<void> {
    const listFrame = this.getListFrame();
    if (!listFrame) {
      throw new Error('List frame not found');
    }

    const settingsTab = listFrame.locator(this.selectors.settingsTab).first();
    if (await settingsTab.isVisible({ timeout: 3000 }).catch(() => false)) {
      await settingsTab.click();
      await this.page.waitForTimeout(2000);
    }
  }

  /**
   * Set the capture mode setting
   * @param mode 'automatic' or 'manual'
   */
  async setCaptureMode(mode: 'automatic' | 'manual'): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) {
      console.log('Edit frame not found');
      return false;
    }

    // Find capture mode select - try multiple selectors
    const selectLocators = [
      editFrame.locator('select[name*="sStripeCaptureMode"]'),
      editFrame.locator('select[name*="CaptureMode"]'),
      editFrame.locator('select').filter({ hasText: /automatic|manual/i }).first(),
    ];

    for (const selectLocator of selectLocators) {
      if (await selectLocator.isVisible({ timeout: 3000 }).catch(() => false)) {
        await selectLocator.selectOption(mode);
        console.log(`  Selected capture mode: ${mode}`);
        return true;
      }
    }

    console.log('  Capture mode select not found');
    return false;
  }

  /**
   * Get the current capture mode setting
   */
  async getCaptureMode(): Promise<string | null> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return null;

    const selectLocators = [
      editFrame.locator('select[name*="sStripeCaptureMode"]'),
      editFrame.locator('select[name*="CaptureMode"]'),
    ];

    for (const selectLocator of selectLocators) {
      if (await selectLocator.isVisible({ timeout: 3000 }).catch(() => false)) {
        return await selectLocator.inputValue();
      }
    }

    return null;
  }

  /**
   * Save the settings
   */
  async saveSettings(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    const saveBtn = editFrame.locator(this.selectors.saveButton).first();
    if (await saveBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await saveBtn.click();
      await this.page.waitForLoadState('networkidle').catch(() => {});
      await this.page.waitForTimeout(2000);
      return true;
    }

    return false;
  }

  /**
   * Full flow: Navigate to settings and set capture mode
   */
  async setStripeCaptureMode(mode: 'automatic' | 'manual'): Promise<boolean> {
    try {
      await this.navigateToModuleSettings();
      await this.selectStripeModule();
      await this.openSettingsTab();

      const setResult = await this.setCaptureMode(mode);
      if (!setResult) return false;

      return await this.saveSettings();
    } catch (e) {
      console.log(`Error setting capture mode: ${e}`);
      return false;
    }
  }
}
