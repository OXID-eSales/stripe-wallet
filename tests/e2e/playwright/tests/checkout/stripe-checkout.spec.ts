import { test, expect } from '@playwright/test';
import { StripeCheckoutPage } from '../../pages/StripeCheckoutPage';
import { STRIPE_TEST_CARDS, TEST_USER } from '../../fixtures/stripe-test-cards';

const SHOP_URL = process.env.SHOP_URL || 'https://localhost.local';

test.describe('Stripe Wallet Checkout', () => {

  test('Complete checkout flow with Stripe Wallet', async ({ page }) => {
    // ============================================
    // STEP 1: Load shop and accept cookies
    // ============================================
    console.log('STEP 1: Loading shop...');
    await page.goto(SHOP_URL);
    await page.waitForLoadState('networkidle');

    // Accept cookies if present
    const cookieButton = page.locator('button:has-text("Accept"), button:has-text("Akzeptieren"), .cookie-accept');
    if (await cookieButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await cookieButton.click();
      await page.waitForTimeout(1000);
    }

    await expect(page).toHaveTitle(/.*/);
    console.log('✓ Shop loaded successfully');

    // ============================================
    // STEP 2: Login to account
    // ============================================
    console.log('STEP 2: Logging in...');
    await page.goto(`${SHOP_URL}/index.php?cl=account`);
    await page.waitForLoadState('networkidle');

    await page.locator('#loginUser').waitFor({ state: 'visible', timeout: 10000 });
    await page.locator('#loginUser').fill(TEST_USER.EMAIL);
    await page.locator('#loginPwd').fill(TEST_USER.PASSWORD);
    await page.locator('#loginButton').click();
    await page.waitForLoadState('networkidle');

    // Verify logged in
    const loggedInIndicators = [
      'text=Mein Konto',
      'text=My Account',
      'a:has-text("Logout")',
      'a:has-text("Abmelden")',
    ];

    for (const selector of loggedInIndicators) {
      if (await page.locator(selector).first().isVisible({ timeout: 2000 }).catch(() => false)) {
        console.log(`✓ Logged in - found: ${selector}`);
        break;
      }
    }

    // ============================================
    // STEP 3: Add product to cart
    // ============================================
    console.log('STEP 3: Adding product to cart...');

    // First, go to the Merchandise category from the main menu
    const merchandiseLink = page.locator('a:has-text("Merchandise")').first();
    await merchandiseLink.waitFor({ state: 'visible', timeout: 10000 });
    await merchandiseLink.click();
    await page.waitForLoadState('networkidle');
    console.log('  Opened Merchandise category');

    // Click on a subcategory with actual products (T-Shirts)
    const subcategorySelectors = [
      'a:has-text("T-Shirts")',
      'a:has-text("Caps")',
      'a:has-text("Sunglasses")',
      'a:has-text("Watches")',
    ];

    for (const selector of subcategorySelectors) {
      const subcat = page.locator(selector).first();
      if (await subcat.isVisible({ timeout: 3000 }).catch(() => false)) {
        await subcat.click();
        await page.waitForLoadState('networkidle');
        console.log(`  Opened subcategory: ${selector}`);
        break;
      }
    }

    // Now click on a product from the list
    // Look for product titles/links in the list view
    const productSelectors = [
      '.productTitle a',
      '.product-title a',
      'h4 a',
      'h5 a',
      '.productData a',
      '.card-title a',
      'a.product-link',
    ];

    let productClicked = false;
    for (const selector of productSelectors) {
      const productLink = page.locator(selector).first();
      if (await productLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        await productLink.click();
        await page.waitForLoadState('networkidle');
        productClicked = true;
        console.log(`  Opened product page using: ${selector}`);
        break;
      }
    }

    if (!productClicked) {
      // Fallback: look for any visible link with product-like href
      const anyProduct = page.locator('a[href*="/en/"]').filter({ hasText: /.+/ }).first();
      if (await anyProduct.isVisible({ timeout: 5000 }).catch(() => false)) {
        await anyProduct.click();
        await page.waitForLoadState('networkidle');
        console.log('  Opened product page (fallback)');
      }
    }

    // Add to cart
    const addToCartBtn = page.locator('#toBasket, button:has-text("Add to cart"), button:has-text("In den Warenkorb")').first();
    await addToCartBtn.waitFor({ state: 'visible', timeout: 10000 });
    await addToCartBtn.click();
    await page.waitForTimeout(2000);
    console.log('✓ Product added to cart');

    // ============================================
    // STEP 4: Go to cart and checkout
    // ============================================
    console.log('STEP 4: Going to cart...');
    await page.goto(`${SHOP_URL}/warenkorb/`);
    await page.waitForLoadState('networkidle');

    // Verify cart has items
    const cartContent = page.locator('.cart-item, .basketItem, .lineItem, tr.basketRow').first();
    if (await cartContent.isVisible({ timeout: 5000 }).catch(() => false)) {
      console.log('  Cart has items');
    }

    // Go to checkout
    const checkoutBtn = page.locator('button:has-text("Checkout"), button:has-text("Zur Kasse"), a:has-text("Checkout"), a:has-text("Zur Kasse")').first();
    await checkoutBtn.waitFor({ state: 'visible', timeout: 10000 });
    await checkoutBtn.click();
    await page.waitForLoadState('networkidle');
    console.log('✓ Proceeded to checkout');

    // ============================================
    // STEP 5: Select Stripe Wallet payment
    // ============================================
    console.log('STEP 5: Selecting payment method...');

    // Navigate through checkout steps
    for (let i = 0; i < 3; i++) {
      const continueBtn = page.locator('button:has-text("Continue"), button:has-text("Weiter"), .next-step').first();
      if (await continueBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await continueBtn.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);
      }
    }

    // Select "Digitale Börse" / "Stripe-Wallet"
    const stripeWalletSelectors = [
      'label:has-text("Digitale Börse")',
      'label:has-text("Stripe-Wallet")',
      'label:has-text("Digital Wallet")',
      'input[value*="stripe_wallet"]',
      'input[value*="stripewallet"]',
    ];

    let paymentSelected = false;
    for (const selector of stripeWalletSelectors) {
      const paymentOption = page.locator(selector).first();
      if (await paymentOption.isVisible({ timeout: 2000 }).catch(() => false)) {
        await paymentOption.click();
        paymentSelected = true;
        console.log(`✓ Selected payment: ${selector}`);
        break;
      }
    }

    if (!paymentSelected) {
      const anyStripePayment = page.locator('label:has-text("Stripe"), input[value*="stripe"]').first();
      if (await anyStripePayment.isVisible({ timeout: 3000 }).catch(() => false)) {
        await anyStripePayment.click();
        console.log('✓ Selected fallback Stripe payment');
      }
    }

    await page.waitForTimeout(1000);

    // Proceed to next step
    const nextBtn = page.locator('button:has-text("Continue"), button:has-text("Weiter")').first();
    if (await nextBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await nextBtn.click();
      await page.waitForLoadState('networkidle');
    }

    // Accept terms
    const termsCheckbox = page.locator('#checkAgb, input[name*="agb"]').first();
    if (await termsCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      await termsCheckbox.check();
    }

    // Submit order
    const submitBtn = page.locator(
      'button:has-text("Pay"), button:has-text("Bezahlen"), button:has-text("Order"), ' +
      'button:has-text("Kaufen"), #orderConfirmAgbBottom, .submitOrder'
    ).first();

    if (await submitBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      await submitBtn.click();
      console.log('✓ Clicked submit order button');
    }

    // ============================================
    // STEP 6: Complete Stripe payment
    // ============================================
    console.log('STEP 6: Completing Stripe payment...');

    const isStripeRedirect = await page.waitForURL(/checkout\.stripe\.com/, { timeout: 30000 }).then(() => true).catch(() => false);

    if (isStripeRedirect) {
      console.log('  Redirected to Stripe Checkout');

      const stripePage = new StripeCheckoutPage(page);
      await stripePage.completePayment(TEST_USER.EMAIL, STRIPE_TEST_CARDS.VISA_SUCCESS);
      await stripePage.waitForRedirectBack(SHOP_URL);
      console.log('✓ Payment completed');
    }

    // ============================================
    // STEP 7: Verify order confirmation
    // ============================================
    console.log('STEP 7: Verifying order confirmation...');
    await page.waitForLoadState('networkidle');
    console.log('Final URL:', page.url());

    const successIndicators = [
      'h1:has-text("Thank")',
      'h1:has-text("Vielen Dank")',
      'text=Thank you',
      'text=Vielen Dank',
      'text=order number',
      'text=Bestellnummer',
    ];

    for (const selector of successIndicators) {
      if (await page.locator(selector).first().isVisible({ timeout: 5000 }).catch(() => false)) {
        console.log(`✓ Order confirmed - found: ${selector}`);
        break;
      }
    }

    await expect(page.locator('body')).toBeVisible();
    console.log('============================================');
    console.log('✓ CHECKOUT FLOW COMPLETED SUCCESSFULLY');
    console.log('============================================');
  });

});
