import { Page, Frame, expect } from '@playwright/test';
import { AdminBasePage } from './AdminBasePage';

export interface StripePaymentDetails {
  paymentType: string;
  transactionId: string;
  dashboardLink: string | null;
}

export interface OrderDetails {
  orderNumber: string;
  paymentDate: string;
  customerName: string;
  productGrossPrice: string;
  discount: string;
  productNetPrice: string;
  vat: string;
  shippingCosts: string;
  sumTotal: string;
}

export class AdminStripeOrderPage extends AdminBasePage {
  private readonly selectors = {
    paymentDetails: 'text=Payment details',
    transactionIdLabel: 'text=Stripe Transaction ID',
    dashboardLink: 'a[href*="dashboard.stripe.com"]',
    executeRefundButton: 'input[value*="Execute refund"]',
    refundReasonSelect: 'select',
    refundSuccessMessage: 'text=Refund was successful',
    orderAlreadyRefunded: 'text=refunded completely',
    // Order details selectors
    orderNumber: 'text=Order No.:',
    paymentDate: 'text=PAYMENT DATE',
    productGrossPrice: 'text=Product Gross Price',
    discount: 'text=Discount',
    productNetPrice: 'text=Product Net Price',
    vat: 'text=VAT',
    shippingCosts: 'text=Shipping Costs',
    sumTotal: 'text=Sum total',
  };

  async getStripePaymentDetails(): Promise<StripePaymentDetails | null> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return null;

    const paymentType = await editFrame.locator('text=osc_stripe_wallet').textContent().catch(() => '');
    const transactionIdEl = await editFrame.locator('text=/pi_[a-zA-Z0-9]+/').first();
    const transactionId = await transactionIdEl.textContent().catch(() => '');

    const dashboardLink = await editFrame.locator(this.selectors.dashboardLink).first()
      .getAttribute('href').catch(() => null);

    return {
      paymentType: paymentType?.trim() || 'osc_stripe_wallet',
      transactionId: transactionId?.trim() || '',
      dashboardLink,
    };
  }

  async verifyDashboardLinkReturns200(): Promise<{ status: number; url: string }> {
    const editFrame = this.getEditFrame();
    if (!editFrame) {
      throw new Error('Edit frame not found');
    }

    const dashboardLink = editFrame.locator(this.selectors.dashboardLink).first();
    const href = await dashboardLink.getAttribute('href');

    if (!href) {
      throw new Error('Dashboard link href not found');
    }

    const response = await this.page.request.get(href);
    return { status: response.status(), url: href };
  }

  async isRefundButtonVisible(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    return editFrame.locator(this.selectors.executeRefundButton).isVisible({ timeout: 3000 }).catch(() => false);
  }

  async isOrderAlreadyRefunded(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    return editFrame.locator(this.selectors.orderAlreadyRefunded).isVisible({ timeout: 3000 }).catch(() => false);
  }

  async executeRefund(reason: string = 'requested_by_customer'): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Select refund reason
    const reasonSelect = editFrame.locator(this.selectors.refundReasonSelect).first();
    if (await reasonSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
      await reasonSelect.selectOption({ value: reason }).catch(async () => {
        await reasonSelect.selectOption({ index: 1 }).catch(() => {});
      });
    }

    // Click execute refund button
    const refundBtn = editFrame.locator(this.selectors.executeRefundButton);
    if (await refundBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await refundBtn.click();
      await this.page.waitForTimeout(3000);
      return true;
    }

    return false;
  }

  async wasRefundSuccessful(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    return editFrame.locator(this.selectors.refundSuccessMessage).isVisible({ timeout: 5000 }).catch(() => false);
  }

  async getOrderDetailsFromOverview(): Promise<OrderDetails | null> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return null;

    const getText = async (selector: string): Promise<string> => {
      const el = editFrame.locator(selector).first();
      const text = await el.textContent().catch(() => '');
      return text?.trim() || '';
    };

    const getValueAfterLabel = async (label: string): Promise<string> => {
      // Try to find the value next to the label
      const row = editFrame.locator(`tr:has-text("${label}")`).first();
      const cells = await row.locator('td').allTextContents().catch(() => []);
      return cells.length > 1 ? cells[cells.length - 1].trim() : '';
    };

    return {
      orderNumber: await getText('text=Order No.:').then(t => t.replace('Order No.:', '').trim()),
      paymentDate: '', // Will be retrieved from list frame
      customerName: '',
      productGrossPrice: await getValueAfterLabel('Product Gross Price'),
      discount: await getValueAfterLabel('Discount'),
      productNetPrice: await getValueAfterLabel('Product Net Price'),
      vat: await getValueAfterLabel('VAT'),
      shippingCosts: await getValueAfterLabel('Shipping Costs'),
      sumTotal: await getValueAfterLabel('Sum total'),
    };
  }

  async getPaymentDateFromOrderList(): Promise<string> {
    const listFrame = this.getListFrame();
    if (!listFrame) return '';

    // Get the payment date from the selected (highlighted) row
    const selectedRow = listFrame.locator('tr.listitem1, tr[style*="background"]').first();
    const cells = await selectedRow.locator('td').allTextContents().catch(() => []);

    // Payment date is typically in the second column
    return cells.length > 1 ? cells[1].trim() : '';
  }

  async isPaymentDateValid(paymentDate: string): Promise<boolean> {
    // Payment date should not be 0000-00-00 00:00:00 for paid orders
    const invalidDate = '0000-00-00 00:00:00';
    return paymentDate !== invalidDate && paymentDate.length > 0;
  }
}
