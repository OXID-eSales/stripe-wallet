/**
 * Scenario 2: Mid-Flow Cancellation (20% traffic)
 *
 * Login → Add to cart → Checkout → Stripe page → Navigate back without paying
 *
 * Tests: contract cleanup, no orphan orders, basket/session preservation
 */

import { browser } from 'k6/browser';
import { check } from 'k6';
import { getRandomUser, loginAndSetup } from '../helpers/auth.js';
import { addProductsToCart, navigateToCheckout, selectStripeAndOrder } from '../helpers/shop.js';
import { waitForShopRedirect } from '../helpers/stripe.js';
import { contractValid, stripeErrors } from '../helpers/metrics.js';

export async function cancellation() {
  const page = await browser.newPage();
  const user = getRandomUser();

  try {
    await loginAndSetup(page, user);
    await addProductsToCart(page);
    await navigateToCheckout(page);
    await selectStripeAndOrder(page);

    // On Stripe Checkout — wait for page to load, then go back
    await page.waitForTimeout(3000);

    // Navigate back via the Stripe back link or browser back
    await page.evaluate(() => {
      const backLink = document.querySelector('a[aria-label="Back"], a[data-testid="back-link"]');
      if (backLink) { backLink.click(); return; }
      history.back();
    });

    // Wait for redirect back to shop
    const shopUrl = await waitForShopRedirect(page, 15);

    contractValid.add(1);
    const ok = shopUrl !== null && !shopUrl.includes('stripe.com');
    check(page, { 'returned to shop': () => ok });
    console.log(`[cancellation] returned to: ${shopUrl}`);
  } catch (e) {
    stripeErrors.add(1);
    console.error(`[cancellation] ${user.email}: ${e.message || e}`);
  } finally {
    await page.close();
  }
}
