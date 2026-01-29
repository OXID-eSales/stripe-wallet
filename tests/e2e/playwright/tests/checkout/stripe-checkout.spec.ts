import { test, expect } from '@playwright/test';
import { LoginPage, TEST_USER } from '../../pages/frontend/LoginPage';
import { ProductPage } from '../../pages/frontend/ProductPage';
import { CheckoutPage } from '../../pages/frontend/CheckoutPage';
import { ThankYouPage } from '../../pages/frontend/ThankYouPage';
import { StripeCheckoutPage } from '../../pages/frontend/StripeCheckoutPage';
import { STRIPE_TEST_CARDS } from '../../fixtures/stripe-test-cards';

test.describe('Stripe Wallet Checkout', () => {

  test('Complete checkout flow with Stripe Wallet', async ({ page }) => {
    const shopUrl = process.env.SHOP_URL || 'https://localhost.local';

    // Step 1: Load shop and accept cookies
    console.log('STEP 1: Loading shop...');
    await page.goto(shopUrl);
    await page.waitForLoadState('networkidle');

    // Accept cookies if present
    const cookieButton = page.locator('button:has-text("Accept"), button:has-text("Akzeptieren"), .cookie-accept');
    if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cookieButton.click();
      await page.waitForTimeout(1000);
    }
    console.log('✓ Shop loaded');

    // Step 2: Login
    console.log('STEP 2: Logging in...');
    await page.goto(`${shopUrl}/index.php?cl=account`);
    await page.waitForLoadState('networkidle');

    await page.locator('#loginUser').waitFor({ state: 'visible', timeout: 10000 });
    await page.locator('#loginUser').fill(TEST_USER.email);
    await page.locator('#loginPwd').fill(TEST_USER.password);
    await page.locator('#loginButton').click();
    await page.waitForLoadState('networkidle');
    console.log('✓ Logged in');

    // Step 3: Add product to cart
    console.log('STEP 3: Adding product to cart...');
    await page.goto(shopUrl);
    await page.waitForLoadState('networkidle');

    // Navigate to Merchandise
    await page.locator('a:has-text("Merchandise")').first().click();
    await page.waitForLoadState('networkidle');

    // Navigate to T-Shirts
    const tshirtsLink = page.locator('a:has-text("T-Shirts")').first();
    if (await tshirtsLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await tshirtsLink.click();
      await page.waitForLoadState('networkidle');
    }

    // Click on product details
    await page.locator('a:has-text("Details")').first().click();
    await page.waitForLoadState('networkidle');

    // Select variant if needed
    const variantSelect = page.locator('select').first();
    if (await variantSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
      await variantSelect.selectOption({ index: 1 });
      await page.waitForTimeout(1000);
    }

    // Add to cart
    await page.locator('#toBasket').click();
    await page.waitForTimeout(2000);
    console.log('✓ Added to cart');

    // Step 4: Go to cart and start checkout
    console.log('STEP 4: Going to cart...');
    await page.goto(`${shopUrl}/index.php?cl=basket&lang=0`);
    await page.waitForLoadState('networkidle');

    // Click checkout button (handle both German and English)
    const checkoutBtn = page.locator('button:has-text("Zur Kasse"), button:has-text("Checkout"), a:has-text("Zur Kasse"), a:has-text("Checkout")').first();
    await checkoutBtn.waitFor({ state: 'visible', timeout: 10000 });
    await checkoutBtn.click();
    await page.waitForLoadState('networkidle');
    console.log('✓ Started checkout');

    // Step 5: Navigate checkout and select payment
    console.log('STEP 5: Navigating checkout...');
    let maxSteps = 5;
    while (maxSteps > 0) {
      await page.waitForTimeout(1000);
      const currentUrl = page.url();

      if (currentUrl.includes('cl=order') || currentUrl.includes('thankyou')) {
        break;
      }

      // Select Stripe payment if visible
      const paymentLabel = page.locator('label:has-text("Digitale Börse"), label:has-text("Stripe")').first();
      if (await paymentLabel.isVisible({ timeout: 2000 }).catch(() => false)) {
        await paymentLabel.click();
        await page.waitForTimeout(500);
      }

      // Click continue
      const continueBtn = page.locator('button:has-text("Weiter"), button:has-text("Continue"), button.nextStep').first();
      if (await continueBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await continueBtn.click();
        await page.waitForLoadState('networkidle');
      } else {
        break;
      }
      maxSteps--;
    }
    console.log('✓ Completed checkout steps');

    // Step 6: Submit order
    console.log('STEP 6: Submitting order...');

    // Accept terms
    const termsCheckbox = page.locator('#checkAgb, input[name*="ord_agb"]').first();
    if (await termsCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      if (!await termsCheckbox.isChecked()) {
        await termsCheckbox.check();
      }
    }

    // Click Stripe checkout button
    await page.click('#stripe-checkout-btn');
    console.log('✓ Clicked submit order');

    // Step 7: Complete Stripe payment
    console.log('STEP 7: Completing Stripe payment...');
    const isStripeRedirect = await page.waitForURL(/checkout\.stripe\.com/, { timeout: 30000 })
      .then(() => true)
      .catch(() => false);

    if (isStripeRedirect) {
      console.log('  Redirected to Stripe Checkout');
      const stripePage = new StripeCheckoutPage(page);
      await stripePage.completePayment(TEST_USER.email, STRIPE_TEST_CARDS.VISA_SUCCESS);
      await stripePage.waitForRedirectBack(shopUrl);
      console.log('✓ Payment completed on Stripe');
    } else {
      console.log('  No Stripe redirect detected');
      console.log('  Current URL:', page.url());
      await page.screenshot({ path: 'reports/no-stripe-redirect.png' });
    }

    // Step 8: Verify order confirmation
    console.log('STEP 8: Verifying order confirmation...');
    await page.waitForLoadState('networkidle');
    console.log('Final URL:', page.url());

    expect(page.url()).toContain('thankyou');

    console.log('============================================');
    console.log('✓ CHECKOUT FLOW COMPLETED SUCCESSFULLY');
    console.log('============================================');
  });

});
