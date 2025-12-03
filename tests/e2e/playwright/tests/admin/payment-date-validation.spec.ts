import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../../pages/admin/AdminOrdersPage';

interface OrderInfo {
  orderNumber: string;
  orderTime: string;
  paymentDate: string;
  transStatus: string;
  hasValidPaymentDate: boolean;
  hasDashboardLink: boolean;
  transactionId: string;
}

test.describe('Payment Date Validation', () => {

  /**
   * STRICT TEST: Orders with OXTRANSSTATUS=OK must have valid OXPAID
   *
   * Business rules:
   * - If OXTRANSSTATUS = 'OK' → OXPAID MUST NOT be 0000-00-00 00:00:00
   * - If OXTRANSSTATUS = 'NOT_FINISHED' → 0000-00-00 is acceptable
   */
  test('Paid orders (OXTRANSSTATUS=OK) must have valid payment dates', async ({ page }) => {
    // Login to admin
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    await adminLogin.login();
    expect(await adminLogin.isLoggedIn()).toBe(true);
    console.log('✓ Logged into admin');

    // Navigate to orders
    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    console.log('✓ Navigated to Orders');

    // Get the list frame
    const listFrame = ordersPage.getListFrame();
    if (!listFrame) {
      throw new Error('List frame not found');
    }

    await page.waitForTimeout(2000);

    // Parse orders from the table
    const orders: OrderInfo[] = [];
    const tableRows = await listFrame.locator('table.listitem tr, table tr').all();

    for (let i = 0; i < tableRows.length; i++) {
      const row = tableRows[i];
      const cells = await row.locator('td').all();

      if (cells.length >= 5) {
        const orderTime = (await cells[0].textContent() || '').trim();
        const paymentDate = (await cells[1].textContent() || '').trim();
        const orderNumber = (await cells[2].textContent() || '').trim();

        if (orderTime.match(/^\d{4}-\d{2}-\d{2}/)) {
          const hasValidPaymentDate = paymentDate !== '0000-00-00 00:00:00' &&
                                       !paymentDate.startsWith('0000');

          orders.push({
            orderNumber,
            orderTime,
            paymentDate,
            transStatus: '', // Will check in Overview tab
            hasValidPaymentDate,
            hasDashboardLink: false,
            transactionId: ''
          });
        }
      }
    }

    console.log(`\nFound ${orders.length} orders to check\n`);

    const ordersWithIssues: OrderInfo[] = [];
    const ordersOk: OrderInfo[] = [];

    // Check each order's Overview tab for transaction status
    for (let i = 0; i < Math.min(orders.length, 5); i++) {
      const order = orders[i];
      console.log(`\nChecking Order #${order.orderNumber}...`);

      // Click on order
      const orderLink = listFrame.locator(`a:has-text("${order.orderTime.split(' ')[0]}")`).nth(i);
      if (await orderLink.isVisible({ timeout: 2000 }).catch(() => false)) {
        await orderLink.click();
        await page.waitForTimeout(1500);

        // Click Overview tab
        const overviewTab = listFrame.locator('a:has-text("Overview")').first();
        if (await overviewTab.isVisible({ timeout: 2000 }).catch(() => false)) {
          await overviewTab.click();
          await page.waitForTimeout(1500);

          // Check edit frame for transaction status
          const editFrame = ordersPage.getEditFrame();
          if (editFrame) {
            const editContent = await editFrame.locator('body').textContent() || '';

            // Look for OXTRANSSTATUS value
            if (editContent.includes('OK')) {
              order.transStatus = 'OK';
            } else if (editContent.includes('NOT_FINISHED')) {
              order.transStatus = 'NOT_FINISHED';
            } else if (editContent.includes('ERROR')) {
              order.transStatus = 'ERROR';
            }

            // Look for transaction ID (pi_...)
            const piMatch = editContent.match(/pi_[a-zA-Z0-9]+/);
            if (piMatch) {
              order.transactionId = piMatch[0];
            }

            // Check for dashboard link
            const dashboardLink = await editFrame.locator('a[href*="dashboard.stripe.com"]').count();
            order.hasDashboardLink = dashboardLink > 0;
          }
        }
      }

      // Determine if this order has issues
      const isPaidOrder = order.transStatus === 'OK';
      const hasInvalidPaymentDate = isPaidOrder && !order.hasValidPaymentDate;

      console.log(`  Order #: ${order.orderNumber}`);
      console.log(`  Trans Status: ${order.transStatus || 'unknown'}`);
      console.log(`  Payment Date: ${order.paymentDate}`);
      console.log(`  Transaction ID: ${order.transactionId || 'none'}`);
      console.log(`  Dashboard Link: ${order.hasDashboardLink ? 'YES' : 'NO'}`);

      if (hasInvalidPaymentDate) {
        console.log(`  Result: ✗ FAIL - Paid order has invalid payment date`);
        ordersWithIssues.push(order);
      } else {
        console.log(`  Result: ✓ OK`);
        ordersOk.push(order);
      }
    }

    // Summary
    console.log('\n=== PAYMENT DATE VALIDATION SUMMARY ===');
    console.log(`Orders checked: ${Math.min(orders.length, 5)}`);
    console.log(`Orders OK: ${ordersOk.length}`);
    console.log(`Orders with invalid payment date: ${ordersWithIssues.length}`);

    // Take screenshot
    await page.screenshot({ path: 'reports/payment-date-validation.png', fullPage: true });

    // FAIL if paid orders have 0000-00-00 payment date
    if (ordersWithIssues.length > 0) {
      const issuesList = ordersWithIssues.map(o =>
        `Order #${o.orderNumber}: TransStatus=${o.transStatus}, PaymentDate=${o.paymentDate}`
      ).join('\n  ');

      throw new Error(
        `BUG: ${ordersWithIssues.length} paid order(s) have OXPAID = 0000-00-00 00:00:00\n\n` +
        `Affected orders:\n  ${issuesList}\n\n` +
        `When OXTRANSSTATUS = 'OK', the OXPAID field must have a valid timestamp.`
      );
    }

    console.log('\n✓ All paid orders have valid payment dates');
  });

  /**
   * Test that transaction IDs have clickable dashboard links
   */
  test('Transaction ID must have Stripe dashboard link', async ({ page }) => {
    // Login to admin
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.navigate();
    await adminLogin.login();
    expect(await adminLogin.isLoggedIn()).toBe(true);

    // Navigate to orders
    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();

    const listFrame = ordersPage.getListFrame();
    if (!listFrame) {
      throw new Error('List frame not found');
    }

    await page.waitForTimeout(2000);

    // Click on first order
    const firstOrderLink = listFrame.locator('table.listitem tr td a').first();
    await firstOrderLink.click();
    await page.waitForTimeout(1500);

    // Click Stripe tab
    const stripeTab = listFrame.locator('a:has-text("Stripe")').first();
    if (await stripeTab.isVisible({ timeout: 3000 }).catch(() => false)) {
      await stripeTab.click();
      await page.waitForTimeout(2000);

      const editFrame = ordersPage.getEditFrame();
      if (editFrame) {
        // Look for transaction ID
        const content = await editFrame.locator('body').textContent() || '';
        const hasTransactionId = content.match(/pi_[a-zA-Z0-9]+/);

        if (hasTransactionId) {
          const transactionId = hasTransactionId[0];
          console.log(`Found Transaction ID: ${transactionId}`);

          // Check for dashboard link
          const dashboardLink = await editFrame.locator(`a[href*="dashboard.stripe.com"]`).first();
          const linkExists = await dashboardLink.isVisible({ timeout: 2000 }).catch(() => false);

          if (!linkExists) {
            await page.screenshot({ path: 'reports/missing-dashboard-link.png', fullPage: true });
            throw new Error(
              `BUG: Transaction ID ${transactionId} has no dashboard link.\n` +
              `Expected: <a href="https://dashboard.stripe.com/payments/${transactionId}">...</a>`
            );
          }

          const href = await dashboardLink.getAttribute('href');
          console.log(`Dashboard link found: ${href}`);
          expect(href).toContain('dashboard.stripe.com');
          console.log('✓ Transaction ID has valid dashboard link');
        } else {
          console.log('No transaction ID found on this order (may be non-Stripe order)');
        }
      }
    } else {
      console.log('No Stripe tab found - order may use different payment method');
    }
  });

});
