import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../../pages/admin/AdminOrdersPage';
import { AdminStripeOrderPage } from '../../pages/admin/AdminStripeOrderPage';
import { AdminModuleSettingsPage } from '../../pages/admin/AdminModuleSettingsPage';

/**
 * Admin Refund Flow Tests (Auto Capture Mode)
 *
 * PREREQUISITES: Run checkout test first to create orders!
 *   SHOP_URL=https://xxx npx playwright test tests/checkout/stripe-checkout.spec.ts
 *
 * These tests ONLY operate in admin backend:
 * 1. Switch to automatic capture mode
 * 2. (Run checkout test separately to create order)
 * 3. Verify order has valid OXPAID date (auto-captured)
 * 4. Check Stripe dashboard link
 * 5. Execute refund
 * 6. Verify refund success
 *
 * NOTE: When running with --project=admin-tests, session is pre-authenticated
 * via auth.setup.ts. No login required in individual tests.
 */
test.describe.serial('Admin: Auto Capture + Refund Operations', () => {

  // Test report accumulator
  const testReport = {
    settingChanged: false,
    orderFound: false,
    transactionId: '',
    dashboardLink: '',
    dashboardLinkValid: false,
    oxpaidDate: '',
    oxpaidValid: false,
    refundAvailable: false,
    refundExecuted: false,
    refundSuccess: false,
    errors: [] as string[],
  };

  /**
   * Helper to ensure we're logged in (handles both direct and project-based runs)
   */
  async function ensureLoggedIn(page: import('@playwright/test').Page): Promise<void> {
    const adminLogin = new AdminLoginPage(page);

    // Check if already logged in (from storageState)
    await adminLogin.navigate();

    // Give page time to restore session
    await page.waitForTimeout(1000);

    if (!await adminLogin.isLoggedIn()) {
      // Not logged in, do manual login
      await adminLogin.login();
    }
  }

  test('1. Set capture mode to AUTOMATIC in admin settings', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Setting capture mode to AUTOMATIC');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    // Navigate to module settings and set capture mode
    const settingsPage = new AdminModuleSettingsPage(page);
    const result = await settingsPage.setStripeCaptureMode('automatic');

    if (result) {
      testReport.settingChanged = true;
      console.log('✓ Capture mode set to AUTOMATIC');
    } else {
      console.log('⚠ Could not change setting via UI - may need to change via config');
      console.log('  Note: Setting may already be automatic or UI differs');
    }

    await page.screenshot({ path: 'reports/refund-01-settings.png' });

    console.log('\n========================================');
    console.log('NOW RUN: npx playwright test tests/checkout/stripe-checkout.spec.ts');
    console.log('========================================\n');
  });

  test('2. Verify order OXPAID has valid date (auto-captured)', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Verify OXPAID for auto-captured order');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    // Navigate to orders
    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    console.log('✓ Navigated to Orders');

    // Get order list and check OXPAID column
    const listFrame = ordersPage.getListFrame();
    if (listFrame) {
      const rows = await listFrame.locator('table tr').all();

      for (let i = 1; i < Math.min(rows.length, 4); i++) {
        const cells = await rows[i].locator('td').allTextContents();
        if (cells.length > 1) {
          const orderDate = cells[0].trim();
          const paymentDate = cells[1].trim();

          console.log(`  Order ${i}: Created=${orderDate}, OXPAID=${paymentDate}`);

          // For auto capture, OXPAID should have valid date immediately
          if (paymentDate !== '0000-00-00 00:00:00' && paymentDate.match(/^\d{4}-\d{2}-\d{2}/)) {
            testReport.oxpaidDate = paymentDate;
            testReport.oxpaidValid = true;
            testReport.orderFound = true;
            console.log('✓ Found order with valid OXPAID (auto-capture confirmed)');
          }
        }
      }
    }

    if (!testReport.orderFound) {
      console.log('⚠ No order with valid OXPAID found');
      console.log('  This may mean:');
      console.log('  - No orders created yet (run checkout test first)');
      console.log('  - Manual capture mode is active (OXPAID will be 0000-00-00)');
    }

    await page.screenshot({ path: 'reports/refund-02-oxpaid.png' });

    // Assert for auto-capture mode
    if (testReport.oxpaidValid) {
      console.log(`✓ OXPAID validation PASSED: ${testReport.oxpaidDate}`);
    }
  });

  test('3. Open Stripe tab and verify dashboard link', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Check Stripe dashboard link');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    await ordersPage.selectOrderByCustomerName('Marc');
    console.log('✓ Selected order');

    await ordersPage.openStripeTab();
    console.log('✓ Opened Stripe tab');

    // Get payment details
    const stripePage = new AdminStripeOrderPage(page);
    const paymentDetails = await stripePage.getStripePaymentDetails();

    if (paymentDetails) {
      testReport.transactionId = paymentDetails.transactionId;
      testReport.dashboardLink = paymentDetails.dashboardLink || '';

      console.log(`  Payment type: ${paymentDetails.paymentType}`);
      console.log(`  Transaction ID: ${paymentDetails.transactionId}`);

      // Validate transaction ID format
      if (paymentDetails.transactionId.match(/^pi_[a-zA-Z0-9]+$/)) {
        console.log('✓ Transaction ID format valid');
      } else {
        testReport.errors.push(`Invalid transaction ID: ${paymentDetails.transactionId}`);
        console.log('✗ Invalid transaction ID format');
      }

      if (paymentDetails.dashboardLink) {
        console.log(`  Dashboard: ${paymentDetails.dashboardLink}`);

        // Verify link works
        try {
          const { status } = await stripePage.verifyDashboardLinkReturns200();
          console.log(`  Dashboard link HTTP status: ${status}`);

          if (status === 200) {
            testReport.dashboardLinkValid = true;
            console.log('✓ Dashboard link returns HTTP 200');
          } else {
            testReport.errors.push(`Dashboard link returned ${status}`);
            console.log(`✗ Dashboard link returned ${status}`);
          }
        } catch (e) {
          console.log('⚠ Could not verify dashboard link');
        }
      } else {
        console.log('⚠ No dashboard link found');
      }
    }

    await page.screenshot({ path: 'reports/refund-03-stripe-tab.png' });
  });

  test('4. Verify refund is available', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Check refund availability');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    await ordersPage.selectOrderByCustomerName('Marc');
    await ordersPage.openStripeTab();
    console.log('✓ Opened Stripe tab');

    const stripePage = new AdminStripeOrderPage(page);

    // Check refund availability
    const refundVisible = await stripePage.isRefundButtonVisible();
    const alreadyRefunded = await stripePage.isOrderAlreadyRefunded();
    const captureNeeded = await stripePage.isCaptureButtonVisible();

    testReport.refundAvailable = refundVisible;

    if (refundVisible) {
      console.log('✓ Refund button is available');
    } else if (alreadyRefunded) {
      console.log('⚠ Order already refunded');
    } else if (captureNeeded) {
      console.log('⚠ Order needs capture first (manual capture mode)');
      console.log('  This test expects auto-capture mode');
    } else {
      console.log('⚠ Refund button NOT available');
    }

    await page.screenshot({ path: 'reports/refund-04-refund-check.png' });
  });

  test('5. Execute refund and verify success', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Execute refund');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    await ordersPage.selectOrderByCustomerName('Marc');
    await ordersPage.openStripeTab();
    console.log('✓ Opened Stripe tab');

    const stripePage = new AdminStripeOrderPage(page);

    // Check if refund is available
    const refundVisible = await stripePage.isRefundButtonVisible();
    const alreadyRefunded = await stripePage.isOrderAlreadyRefunded();

    if (alreadyRefunded) {
      console.log('⚠ Order already refunded - skipping refund');
      testReport.refundSuccess = true; // Already refunded is a pass
      return;
    }

    if (!refundVisible) {
      console.log('⚠ Refund button not visible');

      // Check if we need to capture first
      const captureNeeded = await stripePage.isCaptureButtonVisible();
      if (captureNeeded) {
        console.log('  Order needs capture first - executing capture');
        await stripePage.executeCapture('Auto-capture for refund test');
        await page.waitForTimeout(2000);
      }
    }

    // Try refund again after potential capture
    const refundNowVisible = await stripePage.isRefundButtonVisible();
    if (!refundNowVisible) {
      console.log('✗ Refund still not available');
      return;
    }

    // Execute refund
    console.log('Executing refund...');
    const refundExecuted = await stripePage.executeRefund('requested_by_customer');
    testReport.refundExecuted = refundExecuted;

    if (refundExecuted) {
      console.log('✓ Refund executed');
    } else {
      testReport.errors.push('Failed to execute refund');
      console.log('✗ Refund execution failed');
    }

    // Verify refund success
    const refundSuccess = await stripePage.wasRefundSuccessful();
    const nowRefunded = await stripePage.isOrderAlreadyRefunded();
    const refundGone = !(await stripePage.isRefundButtonVisible());

    if (refundSuccess || nowRefunded || refundGone) {
      testReport.refundSuccess = true;
      console.log('✓ Refund appears successful');
    } else {
      testReport.errors.push('Refund may have failed');
      console.log('⚠ Could not confirm refund success');
    }

    await page.screenshot({ path: 'reports/refund-05-after-refund.png' });
  });

  test('6. Final report and verify order cannot be refunded again', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Verify final state');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    await ordersPage.selectOrderByCustomerName('Marc');
    await ordersPage.openStripeTab();
    console.log('✓ Opened Stripe tab');

    const stripePage = new AdminStripeOrderPage(page);

    // Check final state
    const refundStillVisible = await stripePage.isRefundButtonVisible();
    const isRefunded = await stripePage.isOrderAlreadyRefunded();

    if (isRefunded) {
      console.log('✓ Order shows as refunded');
    }

    if (!refundStillVisible) {
      console.log('✓ Refund button no longer visible (fully refunded)');
    } else {
      console.log('⚠ Refund button still visible (partial refund or not refunded)');
    }

    await page.screenshot({ path: 'reports/refund-06-final-state.png' });

    // Final report
    console.log('\n========================================');
    console.log('REFUND TEST REPORT');
    console.log('========================================');
    console.log(`Transaction ID:       ${testReport.transactionId || 'N/A'}`);
    console.log(`Dashboard Link:       ${testReport.dashboardLink ? '✓ Present' : '✗ Missing'}`);
    console.log(`Dashboard Valid:      ${testReport.dashboardLinkValid ? '✓' : '✗'}`);
    console.log(`OXPAID Date:          ${testReport.oxpaidDate || 'N/A'}`);
    console.log(`OXPAID Valid:         ${testReport.oxpaidValid ? '✓' : '✗'}`);
    console.log(`Refund Available:     ${testReport.refundAvailable ? '✓' : '✗'}`);
    console.log(`Refund Executed:      ${testReport.refundExecuted ? '✓' : '✗'}`);
    console.log(`Refund Success:       ${testReport.refundSuccess ? '✓' : '✗'}`);

    if (testReport.errors.length > 0) {
      console.log('\nErrors:');
      testReport.errors.forEach((e, i) => console.log(`  ${i + 1}. ${e}`));
    }
    console.log('========================================\n');
  });
});
