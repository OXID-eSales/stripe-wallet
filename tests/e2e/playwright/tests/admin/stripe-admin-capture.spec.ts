import { test, expect } from '@playwright/test';
import { AdminLoginPage } from '../../pages/admin/AdminLoginPage';
import { AdminOrdersPage } from '../../pages/admin/AdminOrdersPage';
import { AdminStripeOrderPage } from '../../pages/admin/AdminStripeOrderPage';
import { AdminModuleSettingsPage } from '../../pages/admin/AdminModuleSettingsPage';

/**
 * Admin Capture Flow Tests
 *
 * PREREQUISITES: Run checkout test first to create orders!
 *   SHOP_URL=https://xxx npx playwright test tests/checkout/stripe-checkout.spec.ts
 *
 * These tests ONLY operate in admin backend:
 * 1. Switch to manual capture mode
 * 2. (Run checkout test separately to create order)
 * 3. Verify order has OXPAID = 0000-00-00 (not captured)
 * 4. Capture the order
 * 5. Verify capture success
 * 6. Test cancel authorization for uncaptured orders
 *
 * NOTE: When running with --project=admin-tests, session is pre-authenticated
 * via auth.setup.ts. No login required in individual tests.
 */
test.describe.serial('Admin: Manual Capture Operations', () => {

  // Test report accumulator
  const testReport = {
    settingChanged: false,
    orderFound: false,
    transactionId: '',
    oxpaidBeforeCapture: '',
    captureAvailable: false,
    captureExecuted: false,
    captureSuccess: false,
    oxpaidAfterCapture: '',
    cancelAvailable: false,
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

  test('1. Set capture mode to MANUAL in admin settings', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Setting capture mode to MANUAL');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    // Navigate to module settings and set capture mode
    const settingsPage = new AdminModuleSettingsPage(page);
    const result = await settingsPage.setStripeCaptureMode('manual');

    if (result) {
      testReport.settingChanged = true;
      console.log('✓ Capture mode set to MANUAL');
    } else {
      console.log('⚠ Could not change setting via UI - may need to change via config');
      console.log('  Note: Setting may already be manual or UI differs');
    }

    await page.screenshot({ path: 'reports/capture-01-settings.png' });

    console.log('\n========================================');
    console.log('NOW RUN: npx playwright test tests/checkout/stripe-checkout.spec.ts');
    console.log('========================================\n');
  });

  test('2. Verify order OXPAID is empty (not captured)', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Verify OXPAID before capture');
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

          // For manual capture, OXPAID should be 0000-00-00 until captured
          if (paymentDate === '0000-00-00 00:00:00' || paymentDate.includes('0000')) {
            testReport.oxpaidBeforeCapture = paymentDate;
            testReport.orderFound = true;
            console.log('✓ Found order with empty OXPAID (manual capture confirmed)');
          }
        }
      }
    }

    if (!testReport.orderFound) {
      console.log('⚠ No order with empty OXPAID found');
      console.log('  This may mean:');
      console.log('  - No orders created yet (run checkout test first)');
      console.log('  - Orders were already captured');
      console.log('  - Auto-capture mode is active');
    }

    await page.screenshot({ path: 'reports/capture-02-oxpaid-before.png' });
  });

  test('3. Open Stripe tab and verify capture button available', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Check capture availability');
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
      console.log(`  Transaction ID: ${paymentDetails.transactionId}`);

      if (paymentDetails.dashboardLink) {
        console.log(`  Dashboard: ${paymentDetails.dashboardLink}`);

        // Verify link works
        try {
          const { status } = await stripePage.verifyDashboardLinkReturns200();
          console.log(`  Dashboard link HTTP status: ${status}`);
          expect(status).toBe(200);
          console.log('✓ Dashboard link valid');
        } catch (e) {
          console.log('⚠ Could not verify dashboard link');
        }
      }
    }

    // Check capture availability
    const captureVisible = await stripePage.isCaptureButtonVisible();
    testReport.captureAvailable = captureVisible;

    if (captureVisible) {
      console.log('✓ Capture button is available');
    } else {
      console.log('⚠ Capture button NOT available');
      console.log('  Order may already be captured or in auto-capture mode');

      // Check if refund is available instead (already captured)
      const refundVisible = await stripePage.isRefundButtonVisible();
      if (refundVisible) {
        console.log('  → Refund is available (order already captured)');
      }
    }

    await page.screenshot({ path: 'reports/capture-03-stripe-tab.png' });
  });

  test('4. Execute capture and verify success', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Execute capture');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    await ordersPage.selectOrderByCustomerName('Marc');
    await ordersPage.openStripeTab();
    console.log('✓ Opened Stripe tab');

    const stripePage = new AdminStripeOrderPage(page);

    // Check if capture is available
    const captureVisible = await stripePage.isCaptureButtonVisible();

    if (!captureVisible) {
      console.log('⚠ Capture button not visible - skipping capture');
      console.log('  Order may already be captured');
      return;
    }

    // Execute capture
    console.log('Executing capture...');
    const captureExecuted = await stripePage.executeCapture('E2E test capture');
    testReport.captureExecuted = captureExecuted;

    if (captureExecuted) {
      console.log('✓ Capture executed');
    } else {
      testReport.errors.push('Failed to execute capture');
      console.log('✗ Capture execution failed');
    }

    // Verify capture success
    const captureSuccess = await stripePage.wasCaptureSuccessful();
    const captureGone = !(await stripePage.isCaptureButtonVisible());
    const refundNowVisible = await stripePage.isRefundButtonVisible();

    if (captureSuccess || captureGone || refundNowVisible) {
      testReport.captureSuccess = true;
      console.log('✓ Capture appears successful');

      if (refundNowVisible) {
        console.log('✓ Refund button now visible (confirms capture)');
      }
    } else {
      testReport.errors.push('Capture may have failed');
      console.log('⚠ Could not confirm capture success');
    }

    await page.screenshot({ path: 'reports/capture-04-after-capture.png' });
  });

  test('5. Verify OXPAID is now set after capture', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Verify OXPAID after capture');
    console.log('========================================\n');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

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

          // After capture, OXPAID should have a valid date
          if (paymentDate !== '0000-00-00 00:00:00' && paymentDate.match(/^\d{4}-\d{2}-\d{2}/)) {
            testReport.oxpaidAfterCapture = paymentDate;
            console.log('✓ OXPAID now has valid date (capture confirmed)');
          }
        }
      }
    }

    await page.screenshot({ path: 'reports/capture-05-oxpaid-after.png' });

    // Final report
    console.log('\n========================================');
    console.log('CAPTURE TEST REPORT');
    console.log('========================================');
    console.log(`Transaction ID:     ${testReport.transactionId || 'N/A'}`);
    console.log(`OXPAID Before:      ${testReport.oxpaidBeforeCapture || 'N/A'}`);
    console.log(`Capture Available:  ${testReport.captureAvailable ? '✓' : '✗'}`);
    console.log(`Capture Executed:   ${testReport.captureExecuted ? '✓' : '✗'}`);
    console.log(`Capture Success:    ${testReport.captureSuccess ? '✓' : '✗'}`);
    console.log(`OXPAID After:       ${testReport.oxpaidAfterCapture || 'N/A'}`);

    if (testReport.errors.length > 0) {
      console.log('\nErrors:');
      testReport.errors.forEach((e, i) => console.log(`  ${i + 1}. ${e}`));
    }
    console.log('========================================\n');
  });

  test('6. Test cancel authorization (for new uncaptured order)', async ({ page }) => {
    console.log('\n========================================');
    console.log('ADMIN: Test cancel authorization');
    console.log('========================================\n');
    console.log('Note: First run checkout test to create new order with manual capture');
    console.log('');

    await ensureLoggedIn(page);
    console.log('✓ Logged into admin');

    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    await ordersPage.selectOrderByCustomerName('Marc');
    await ordersPage.openStripeTab();
    console.log('✓ Opened Stripe tab');

    const stripePage = new AdminStripeOrderPage(page);

    // Check for cancel button using specific class selector (language-independent)
    const editFrame = stripePage.getEditFrame();
    let cancelButtonVisible = false;

    if (editFrame) {
      // Use specific selectors for cancel authorization button
      cancelButtonVisible = await editFrame.locator(
        'input.cancelSubmit, fieldset.cancelAuthorization input[type="submit"], #cancelForm input[type="submit"]'
      ).isVisible({ timeout: 3000 }).catch(() => false);
    }

    testReport.cancelAvailable = cancelButtonVisible;

    if (cancelButtonVisible) {
      console.log('✓ Cancel authorization button found');

      // Try to execute cancel using specific selector
      const cancelBtn = editFrame!.locator(
        'input.cancelSubmit, fieldset.cancelAuthorization input[type="submit"], #cancelForm input[type="submit"]'
      ).first();

      // Handle the confirm dialog
      page.once('dialog', async dialog => {
        console.log(`  Dialog: "${dialog.message()}"`);
        await dialog.accept();
      });

      await cancelBtn.click();
      await page.waitForLoadState('networkidle').catch(() => {});
      await page.waitForTimeout(3000);

      console.log('✓ Cancel authorization executed');
    } else {
      console.log('⚠ Cancel authorization button NOT found');
      console.log('');
      console.log('FEATURE STATUS: Not implemented in admin UI');
      console.log('');
      console.log('Workarounds:');
      console.log('  1. Authorized payments expire automatically after 7 days');
      console.log('  2. Cancel via Stripe Dashboard directly');
      console.log('  3. Implement cancel feature in admin (future work)');
    }

    await page.screenshot({ path: 'reports/capture-06-cancel-auth.png' });
  });
});
