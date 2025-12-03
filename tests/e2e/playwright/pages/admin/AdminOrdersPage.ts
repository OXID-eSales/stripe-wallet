import { Page, Frame, expect } from '@playwright/test';
import { AdminBasePage } from './AdminBasePage';

export class AdminOrdersPage extends AdminBasePage {
  private readonly selectors = {
    administerOrdersLink: 'a:has-text("Administer Orders")',
    ordersLink: 'a:has-text("Orders")',
    stripeTab: 'a:has-text("Stripe")',
    orderRowWithName: (name: string) => `table tr:has-text("${name}")`,
  };

  async navigateToOrders(): Promise<void> {
    const menuFrame = this.getMenuFrame();
    if (!menuFrame) {
      throw new Error('Menu frame not found');
    }

    // Click Administer Orders in sidebar to expand
    const adminOrdersLink = menuFrame.locator(this.selectors.administerOrdersLink);
    await adminOrdersLink.click();
    await this.page.waitForTimeout(1000);
    await adminOrdersLink.click().catch(() => {});
    await this.page.waitForTimeout(1000);

    // Click Orders link in basefrm content area (more reliable)
    const baseFrame = this.getBaseFrame();
    if (baseFrame) {
      const ordersInContent = baseFrame.locator(this.selectors.ordersLink).first();
      if (await ordersInContent.isVisible({ timeout: 3000 }).catch(() => false)) {
        await ordersInContent.click();
        await this.page.waitForTimeout(3000);
      }
    }

    // Wait for list frame to load
    await this.page.waitForTimeout(2000);
  }

  async selectOrderByCustomerName(customerName: string = 'Marc'): Promise<void> {
    const listFrame = this.getListFrame();
    if (!listFrame) {
      throw new Error('List frame not found');
    }

    const orderRow = listFrame.locator(this.selectors.orderRowWithName(customerName)).first();
    if (await orderRow.isVisible({ timeout: 3000 }).catch(() => false)) {
      const orderLink = orderRow.locator('a').first();
      await orderLink.click();
      await this.page.waitForTimeout(2000);
    } else {
      // Fallback: click first order link
      const firstOrderLink = listFrame.locator('a:has-text("2025-")').first();
      if (await firstOrderLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        await firstOrderLink.click();
        await this.page.waitForTimeout(2000);
      }
    }
  }

  async openStripeTab(): Promise<void> {
    const listFrame = this.getListFrame();
    if (!listFrame) {
      throw new Error('List frame not found');
    }

    const stripeTab = listFrame.locator(this.selectors.stripeTab).first();
    if (await stripeTab.isVisible({ timeout: 3000 }).catch(() => false)) {
      await stripeTab.click();
      await this.page.waitForTimeout(3000);
    } else {
      throw new Error('Stripe tab not found in list frame');
    }
  }

  async getAvailableTabs(): Promise<string[]> {
    const listFrame = this.getListFrame();
    if (!listFrame) return [];

    const tabs = await listFrame.locator('a').allTextContents();
    return tabs.filter(t => ['Overview', 'Main', 'Addresses', 'Products', 'History', 'Downloads', 'Stripe'].includes(t.trim()));
  }
}
