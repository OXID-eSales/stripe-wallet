/**
 * Scenario 5: 3D Secure Authentication (10% traffic)
 *
 * Login → Add to cart → Checkout → Pay with 3DS card → Complete 3DS → Thank You
 *
 * Tests: 3DS iframe rendering, webhook delay, payment_authorized condition
 */

import { browser } from 'k6/browser';
import { check } from 'k6';
import { CARDS } from '../helpers/config.js';
import { getRandomUser, loginAndSetup } from '../helpers/auth.js';
import { addProductsToCart, navigateToCheckout, selectStripeAndOrder } from '../helpers/shop.js';
import { fillStripeCard, clickStripePay, handle3DS, verifyThankYou } from '../helpers/stripe.js';
import { checkoutSuccess, contractValid, checkoutDuration, orderNumbers, stripeErrors } from '../helpers/metrics.js';

export async function threeds() {
  const page = await browser.newPage();
  const user = getRandomUser();
  const start = Date.now();

  try {
    await loginAndSetup(page, user);
    await addProductsToCart(page);
    await navigateToCheckout(page);
    await selectStripeAndOrder(page);

    // Pay with 3DS-required card
    await fillStripeCard(page, CARDS.REQUIRES_3DS);
    await clickStripePay(page);

    // Complete 3DS challenge
    await handle3DS(page, 'Complete');

    const orderNr = await verifyThankYou(page);
    const ok = orderNr !== null;

    checkoutSuccess.add(ok ? 1 : 0);
    contractValid.add(1);
    checkoutDuration.add(Date.now() - start);
    if (ok) orderNumbers.add(1);

    console.log(`[threeds] order=${orderNr}, duration=${Date.now() - start}ms`);
    check(page, { '3DS order created': () => ok });
  } catch (e) {
    checkoutSuccess.add(0);
    stripeErrors.add(1);
    console.error(`[threeds] ${user.email}: ${e.message || e}`);
  } finally {
    await page.close();
  }
}
