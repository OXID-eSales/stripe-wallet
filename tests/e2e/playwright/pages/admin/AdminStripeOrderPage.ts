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
      // Wait for form submission and page reload
      await this.page.waitForLoadState('networkidle').catch(() => {});
      await this.page.waitForTimeout(2000);
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

  // =========================================================================
  // Capture Methods (for Manual Capture Mode)
  // =========================================================================

  /**
   * Check if capture button is visible (indicates requires_capture status)
   * Uses class selector for language-independence (works in EN and DE)
   */
  async isCaptureButtonVisible(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Use class selector which is language-independent
    // Also check for fieldset.capturePayment which contains the capture form
    const captureButton = editFrame.locator('input.captureSubmit, fieldset.capturePayment input[type="submit"]');
    return captureButton.isVisible({ timeout: 3000 }).catch(() => false);
  }

  /**
   * Check if order requires capture (manual capture mode)
   */
  async isOrderAwaitingCapture(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Look for the capture notice fieldset
    const captureNotice = editFrame.locator('fieldset.captureNotice, text=requires capture, text=Capture required');
    return captureNotice.isVisible({ timeout: 3000 }).catch(() => false);
  }

  /**
   * Get the captureable amount displayed in admin
   */
  async getCaptureableAmount(): Promise<string> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return '';

    // Look for the amount text near capture section
    const amountText = await editFrame.locator('fieldset.capturePayment span, text=/\\d+[.,]\\d{2}/')
      .first().textContent().catch(() => '');
    return amountText?.trim() || '';
  }

  /**
   * Execute capture on the order
   * Uses class selector for language-independence
   */
  async executeCapture(reason: string = ''): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Fill capture reason if provided
    if (reason) {
      const reasonInput = editFrame.locator('#capture_reason, input[name="capture_reason"]');
      if (await reasonInput.isVisible({ timeout: 2000 }).catch(() => false)) {
        await reasonInput.fill(reason);
      }
    }

    // Click capture submit button using class selector (language-independent)
    const captureBtn = editFrame.locator('input.captureSubmit, fieldset.capturePayment input[type="submit"]').first();
    if (await captureBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await captureBtn.click();
      // Wait for form submission and page reload
      await this.page.waitForLoadState('networkidle').catch(() => {});
      await this.page.waitForTimeout(2000);
      return true;
    }

    return false;
  }

  /**
   * Check if capture was successful
   */
  async wasCaptureSuccessful(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    return editFrame.locator('fieldset.captureSuccess, text=Capture was successful, text=successfully captured')
      .isVisible({ timeout: 5000 }).catch(() => false);
  }

  /**
   * Check if order has been captured (no longer shows capture option)
   */
  async isOrderCaptured(): Promise<boolean> {
    // If capture button is not visible and refund button is visible, order is captured
    const captureVisible = await this.isCaptureButtonVisible();
    const refundVisible = await this.isRefundButtonVisible();
    return !captureVisible && refundVisible;
  }

  // =========================================================================
  // Cancel Authorization Methods (for Manual Capture Mode)
  // =========================================================================

  /**
   * Check if cancel authorization button is visible
   * Uses class selector for language-independence (works in EN and DE)
   */
  async isCancelAuthorizationButtonVisible(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Use specific class selector which is language-independent
    const cancelButton = editFrame.locator('input.cancelSubmit, fieldset.cancelAuthorization input[type="submit"], #cancelForm input[type="submit"]');
    return cancelButton.isVisible({ timeout: 3000 }).catch(() => false);
  }

  /**
   * Execute cancel authorization on the order
   * Uses class selector for language-independence
   */
  async executeCancelAuthorization(reason: string = ''): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Select cancellation reason if provided
    if (reason) {
      const reasonSelect = editFrame.locator('#cancellation_reason, select[name="cancellation_reason"]');
      if (await reasonSelect.isVisible({ timeout: 2000 }).catch(() => false)) {
        await reasonSelect.selectOption({ value: reason }).catch(async () => {
          await reasonSelect.selectOption({ index: 1 }).catch(() => {});
        });
      }
    }

    // Click cancel submit button using class selector (language-independent)
    const cancelBtn = editFrame.locator('input.cancelSubmit, fieldset.cancelAuthorization input[type="submit"], #cancelForm input[type="submit"]').first();
    if (await cancelBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      // The button has a confirm dialog, so we need to handle it
      this.page.once('dialog', async dialog => {
        await dialog.accept();
      });
      await cancelBtn.click();
      // Wait for form submission and page reload
      await this.page.waitForLoadState('networkidle').catch(() => {});
      await this.page.waitForTimeout(2000);
      return true;
    }

    return false;
  }

  /**
   * Check if cancel authorization was successful
   */
  async wasCancelSuccessful(): Promise<boolean> {
    const editFrame = this.getEditFrame();
    if (!editFrame) return false;

    // Look for success message
    return editFrame.locator('fieldset.captureSuccess:has-text("cancel"), text=cancelled successfully, text=erfolgreich storniert')
      .isVisible({ timeout: 5000 }).catch(() => false);
  }
}
