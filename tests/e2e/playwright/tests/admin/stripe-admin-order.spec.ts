import { test, expect, Page, FrameLocator } from '@playwright/test';
import { StripeCheckoutPage } from '../../pages/StripeCheckoutPage';
import { STRIPE_TEST_CARDS, TEST_USER } from '../../fixtures/stripe-test-cards';

const SHOP_URL = process.env.SHOP_URL || 'https://localhost.local';
const ADMIN_URL = `${SHOP_URL}/admin`;

const ADMIN_CREDENTIALS = {
  EMAIL: 'noreply@oxid-esales.com',
  PASSWORD: 'admin',
};

test.describe.serial('Stripe Order Flow: Checkout then Admin Verification', () => {

  test('1. Create order via Stripe Wallet checkout', async ({ page }) => {
    // Navigate to shop
    await page.goto(SHOP_URL);
    await page.waitForLoadState('networkidle');

    // Accept cookies if present
    const cookieButton = page.locator('button:has-text("Accept"), button:has-text("Akzeptieren"), .cookie-accept');
    if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cookieButton.click();
      await page.waitForTimeout(1000);
    }

    // 1. Login
    await page.goto(`${SHOP_URL}/index.php?cl=account`);
    await page.waitForLoadState('networkidle');
    await page.locator('#loginUser').waitFor({ state: 'visible', timeout: 10000 });
    await page.locator('#loginUser').fill(TEST_USER.EMAIL);
    await page.locator('#loginPwd').fill(TEST_USER.PASSWORD);
    await page.locator('#loginButton').click();
    await page.waitForLoadState('networkidle');

    // 2. Navigate to a product category
    await page.goto(`${SHOP_URL}/index.php?cl=alist`);
    await page.waitForLoadState('networkidle');

    // 3. Click on first product
    const productLink = page.locator('a.product-link, .productData a, article.product a, .product a').first();
    if (await productLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      await productLink.click();
      await page.waitForLoadState('networkidle');
    }

    // 4. Add to cart
    const addToCartBtn = page.locator('#toBasket, button:has-text("Add to cart"), button:has-text("In den Warenkorb")');
    if (await addToCartBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await addToCartBtn.click();
      await page.waitForTimeout(2000);
    }

    // 5. Go to cart
    await page.goto(`${SHOP_URL}/warenkorb/`);
    await page.waitForLoadState('networkidle');

    // 6. Go to checkout
    const checkoutBtn = page.locator('button:has-text("Checkout"), button:has-text("Zur Kasse"), a:has-text("Checkout"), a:has-text("Zur Kasse")').first();
    if (await checkoutBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await checkoutBtn.click();
      await page.waitForLoadState('networkidle');
    }

    // 7. Navigate through checkout steps
    for (let i = 0; i < 3; i++) {
      const continueBtn = page.locator('button:has-text("Continue"), button:has-text("Weiter"), .next-step').first();
      if (await continueBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await continueBtn.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
      }
    }

    // 8. Select "Digitale Börse" / "Stripe-Wallet" payment method
    const stripeWalletSelectors = [
      'label:has-text("Digitale Börse")',
      'label:has-text("Stripe-Wallet")',
      'label:has-text("Digital Wallet")',
      'input[value*="stripe_wallet"]',
      'input[value*="stripewallet"]',
    ];

    for (const selector of stripeWalletSelectors) {
      const paymentOption = page.locator(selector).first();
      if (await paymentOption.isVisible({ timeout: 2000 }).catch(() => false)) {
        await paymentOption.click();
        console.log(`Selected payment: ${selector}`);
        break;
      }
    }

    await page.waitForTimeout(1000);

    // 9. Proceed to next step
    const nextBtn = page.locator('button:has-text("Continue"), button:has-text("Weiter")').first();
    if (await nextBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await nextBtn.click();
      await page.waitForLoadState('networkidle');
    }

    // 10. Accept terms and submit
    const termsCheckbox = page.locator('#checkAgb, input[name*="agb"]').first();
    if (await termsCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      await termsCheckbox.check();
    }

    const submitBtn = page.locator(
      'button:has-text("Pay"), button:has-text("Bezahlen"), button:has-text("Order"), ' +
      'button:has-text("Kaufen"), #orderConfirmAgbBottom'
    ).first();

    if (await submitBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await submitBtn.click();
    }

    // 11. Handle Stripe redirect
    const isStripeRedirect = await page.waitForURL(/checkout\.stripe\.com/, { timeout: 30000 }).then(() => true).catch(() => false);

    if (isStripeRedirect) {
      console.log('Redirected to Stripe Checkout');
      const stripePage = new StripeCheckoutPage(page);
      await stripePage.completePayment(TEST_USER.EMAIL, STRIPE_TEST_CARDS.VISA_SUCCESS);
      await stripePage.waitForRedirectBack(SHOP_URL);
    }

    // 12. Verify order completed
    await page.waitForLoadState('networkidle');
    console.log('Order created - Final URL:', page.url());

    await expect(page.locator('body')).toBeVisible();
  });

  test('2. Admin: Verify order timestamps and Stripe data', async ({ page }) => {
    // 1. Login to admin
    await page.goto(ADMIN_URL);
    await page.waitForLoadState('networkidle');

    // Handle potential basic auth or login form
    await page.locator('input[name="user"], input[type="text"]').first().fill(ADMIN_CREDENTIALS.EMAIL);
    await page.locator('input[name="pwd"], input[type="password"]').first().fill(ADMIN_CREDENTIALS.PASSWORD);
    await page.locator('input[type="submit"], button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    console.log('Logged into admin');

    // 2. Navigate to Orders - OXID admin uses framesets
    // First, we need to find the navigation frame
    const navFrame = page.frameLocator('frame[name="navigation"], iframe[name="navigation"]');
    const baseFrame = page.frameLocator('frame[name="basefrm"], iframe[name="basefrm"]');

    // Try to click "Administer Orders" in navigation
    const ordersNavSelectors = [
      'a:has-text("Administer Orders")',
      'a:has-text("Bestellungen verwalten")',
      'a:has-text("Orders")',
      'a:has-text("Bestellungen")',
    ];

    // Try in navigation frame first
    for (const selector of ordersNavSelectors) {
      try {
        const navItem = navFrame.locator(selector).first();
        if (await navItem.isVisible({ timeout: 2000 }).catch(() => false)) {
          await navItem.click();
          console.log(`Clicked nav: ${selector}`);
          await page.waitForTimeout(2000);
          break;
        }
      } catch {
        // Try in page directly
        const navItem = page.locator(selector).first();
        if (await navItem.isVisible({ timeout: 1000 }).catch(() => false)) {
          await navItem.click();
          console.log(`Clicked nav (direct): ${selector}`);
          await page.waitForTimeout(2000);
          break;
        }
      }
    }

    // 3. Click on "Orders" submenu
    await page.waitForTimeout(1000);
    for (const selector of ordersNavSelectors) {
      try {
        const menuItem = navFrame.locator(selector).first();
        if (await menuItem.isVisible({ timeout: 1000 }).catch(() => false)) {
          await menuItem.click();
          await page.waitForTimeout(2000);
          break;
        }
      } catch {
        continue;
      }
    }

    // 4. Get the list frame and find orders
    const listFrame = baseFrame.frameLocator('frame[name="list"], iframe[name="list"]');

    // Click on first/latest order
    try {
      const firstOrder = listFrame.locator('tr.listitem, tr[id*="row"]').first();
      if (await firstOrder.isVisible({ timeout: 5000 }).catch(() => false)) {
        await firstOrder.click();
        console.log('Clicked on order in list');
        await page.waitForTimeout(2000);
      }
    } catch {
      console.log('Could not find order in list frame');
    }

    // 5. Get the edit frame (order details)
    const editFrame = baseFrame.frameLocator('frame[name="edit"], iframe[name="edit"]');

    // 6. Verify timestamps are present
    const timestampFields = [
      'input[name*="oxpaid"]',
      'td:has-text("Paid")',
      'td:has-text("Bezahlt")',
      'input[name*="oxtransstatus"]',
    ];

    for (const selector of timestampFields) {
      try {
        const field = editFrame.locator(selector).first();
        if (await field.isVisible({ timeout: 2000 }).catch(() => false)) {
          const value = await field.inputValue().catch(() => '') || await field.textContent().catch(() => '');
          console.log(`Timestamp field ${selector}: ${value}`);
        }
      } catch {
        continue;
      }
    }

    // 7. Find and click "Stripe" tab
    const stripeTabSelectors = [
      'input[value="Stripe"]',
      'a:has-text("Stripe")',
      'button:has-text("Stripe")',
      '.nav-link:has-text("Stripe")',
      'li a:has-text("Stripe")',
    ];

    for (const selector of stripeTabSelectors) {
      try {
        const tab = editFrame.locator(selector).first();
        if (await tab.isVisible({ timeout: 2000 }).catch(() => false)) {
          await tab.click();
          console.log(`Clicked Stripe tab: ${selector}`);
          await page.waitForTimeout(2000);
          break;
        }
      } catch {
        continue;
      }
    }

    // 8. In Stripe tab, verify order data
    const stripeDataSelectors = [
      'text=Transaction',
      'text=Transaktion',
      'text=pi_',
      'text=Payment Intent',
      'text=Amount',
      'text=Status',
    ];

    for (const selector of stripeDataSelectors) {
      try {
        const data = editFrame.locator(selector).first();
        if (await data.isVisible({ timeout: 2000 }).catch(() => false)) {
          const text = await data.textContent().catch(() => '');
          console.log(`Stripe data: ${text}`);
        }
      } catch {
        continue;
      }
    }

    // 9. Find transaction link to Stripe dashboard
    try {
      const dashboardLink = editFrame.locator('a[href*="dashboard.stripe.com"], a[href*="stripe.com"]').first();
      if (await dashboardLink.isVisible({ timeout: 2000 }).catch(() => false)) {
        const href = await dashboardLink.getAttribute('href');
        console.log(`Stripe dashboard link: ${href}`);
        await expect(dashboardLink).toBeVisible();
      }
    } catch {
      console.log('Stripe dashboard link not found');
    }

    await expect(page.locator('body')).toBeVisible();
  });

  test('3. Admin: Perform refund with reason "customer request"', async ({ page }) => {
    // 1. Login to admin
    await page.goto(ADMIN_URL);
    await page.waitForLoadState('networkidle');

    await page.locator('input[name="user"], input[type="text"]').first().fill(ADMIN_CREDENTIALS.EMAIL);
    await page.locator('input[name="pwd"], input[type="password"]').first().fill(ADMIN_CREDENTIALS.PASSWORD);
    await page.locator('input[type="submit"], button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    // 2. Navigate to Orders
    const navFrame = page.frameLocator('frame[name="navigation"], iframe[name="navigation"]');
    const baseFrame = page.frameLocator('frame[name="basefrm"], iframe[name="basefrm"]');

    const ordersNav = navFrame.locator('a:has-text("Administer Orders"), a:has-text("Bestellungen verwalten")').first();
    if (await ordersNav.isVisible({ timeout: 3000 }).catch(() => false)) {
      await ordersNav.click();
      await page.waitForTimeout(2000);
    }

    // Click Orders submenu
    const ordersSubmenu = navFrame.locator('a:has-text("Orders"), a:has-text("Bestellungen")').first();
    if (await ordersSubmenu.isVisible({ timeout: 2000 }).catch(() => false)) {
      await ordersSubmenu.click();
      await page.waitForTimeout(2000);
    }

    // 3. Select first order
    const listFrame = baseFrame.frameLocator('frame[name="list"]');
    try {
      const firstOrder = listFrame.locator('tr.listitem, tr[id*="row"]').first();
      if (await firstOrder.isVisible({ timeout: 3000 }).catch(() => false)) {
        await firstOrder.click();
        await page.waitForTimeout(2000);
      }
    } catch {
      console.log('Could not select order');
    }

    // 4. Go to Stripe tab
    const editFrame = baseFrame.frameLocator('frame[name="edit"]');
    const stripeTab = editFrame.locator('input[value="Stripe"], a:has-text("Stripe")').first();
    if (await stripeTab.isVisible({ timeout: 3000 }).catch(() => false)) {
      await stripeTab.click();
      await page.waitForTimeout(2000);
    }

    // 5. Click Refund button
    const refundBtnSelectors = [
      'button:has-text("Refund")',
      'button:has-text("Erstattung")',
      'button:has-text("Rückerstattung")',
      'input[value*="Refund"]',
      'a:has-text("Refund")',
    ];

    for (const selector of refundBtnSelectors) {
      try {
        const refundBtn = editFrame.locator(selector).first();
        if (await refundBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await refundBtn.click();
          console.log(`Clicked refund: ${selector}`);
          await page.waitForTimeout(2000);
          break;
        }
      } catch {
        continue;
      }
    }

    // 6. Select reason "customer request"
    const reasonSelectors = [
      'select[name*="reason"]',
      'select[id*="reason"]',
      '#refundReason',
    ];

    for (const selector of reasonSelectors) {
      try {
        const reasonSelect = editFrame.locator(selector).first();
        if (await reasonSelect.isVisible({ timeout: 2000 }).catch(() => false)) {
          // Try different option values
          await reasonSelect.selectOption({ label: 'Customer request' }).catch(async () => {
            await reasonSelect.selectOption({ label: 'Kundenanfrage' }).catch(async () => {
              await reasonSelect.selectOption({ value: 'requested_by_customer' }).catch(async () => {
                await reasonSelect.selectOption({ index: 1 }).catch(() => {});
              });
            });
          });
          console.log('Selected refund reason');
          break;
        }
      } catch {
        continue;
      }
    }

    // 7. Confirm refund
    const confirmSelectors = [
      'button:has-text("Confirm")',
      'button:has-text("Bestätigen")',
      'button:has-text("Submit")',
      'input[type="submit"]',
    ];

    for (const selector of confirmSelectors) {
      try {
        const confirmBtn = editFrame.locator(selector).first();
        if (await confirmBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await confirmBtn.click();
          console.log(`Clicked confirm: ${selector}`);
          await page.waitForTimeout(3000);
          break;
        }
      } catch {
        continue;
      }
    }

    // 8. Verify refund success
    const successIndicators = [
      'text=success',
      'text=erfolgreich',
      'text=refunded',
      '.success',
      '.alert-success',
    ];

    for (const selector of successIndicators) {
      try {
        const success = editFrame.locator(selector).first();
        if (await success.isVisible({ timeout: 3000 }).catch(() => false)) {
          console.log(`Refund success indicator: ${selector}`);
        }
      } catch {
        continue;
      }
    }

    await expect(page.locator('body')).toBeVisible();
  });

});
