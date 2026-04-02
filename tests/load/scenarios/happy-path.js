/**
 * Scenario 1: Happy Path Checkout (40% traffic)
 *
 * Login → Browse → Add to cart → Checkout → Stripe → Pay → Thank You
 */

import { browser } from 'k6/browser';
import { check } from 'k6';
import { CARDS } from '../helpers/config.js';
import { getRandomUser, loginAndSetup } from '../helpers/auth.js';
import { addProductsToCart, navigateToCheckout, selectStripeAndOrder } from '../helpers/shop.js';
import { fillStripeCard, clickStripePay, verifyThankYou } from '../helpers/stripe.js';
import { checkoutSuccess, contractValid, checkoutDuration, orderNumbers, stripeErrors } from '../helpers/metrics.js';

export async function happy_path() {
  const page = await browser.newPage();
  const user = getRandomUser();
  const start = Date.now();

  try {
    await loginAndSetup(page, user);
    await addProductsToCart(page);
    await navigateToCheckout(page);
    await selectStripeAndOrder(page);
    await fillStripeCard(page, CARDS.VISA_4242);
    await clickStripePay(page);

    const orderNr = await verifyThankYou(page);
    const ok = orderNr !== null;

    checkoutSuccess.add(ok ? 1 : 0);
    contractValid.add(1);
    checkoutDuration.add(Date.now() - start);
    if (ok) orderNumbers.add(1);

    console.log(`[happy_path] order=${orderNr}, duration=${Date.now() - start}ms`);
    check(page, { 'order created': () => ok });
  } catch (e) {
    checkoutSuccess.add(0);
    stripeErrors.add(1);
    console.error(`[happy_path] ${user.email}: ${e.message || e}`);
  } finally {
    await page.close();
  }
}
