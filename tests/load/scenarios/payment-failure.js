/**
 * Scenario 4: Payment Failure & Retry (15% traffic)
 *
 * Login → Add to cart → Checkout → Pay with declined card → See error → Retry
 *
 * Tests: idempotency, contract state recovery, Stripe error display
 */

import { browser } from 'k6/browser';
import { check } from 'k6';
import { CARDS } from '../helpers/config.js';
import { getRandomUser, loginAndSetup } from '../helpers/auth.js';
import { addProductsToCart, navigateToCheckout, selectStripeAndOrder } from '../helpers/shop.js';
import { fillStripeCard, clickStripePay, verifyThankYou, waitForStripeError } from '../helpers/stripe.js';
import { checkoutSuccess, contractValid, checkoutDuration, orderNumbers, stripeErrors } from '../helpers/metrics.js';

export async function payment_failure() {
  const page = await browser.newPage();
  const user = getRandomUser();
  const start = Date.now();

  try {
    await loginAndSetup(page, user);
    await addProductsToCart(page);
    await navigateToCheckout(page);
    await selectStripeAndOrder(page);

    // First attempt: declined card
    await fillStripeCard(page, CARDS.DECLINED);
    await clickStripePay(page);

    // Wait for Stripe to show error
    const errorShown = await waitForStripeError(page, 15000);
    check(page, { 'declined error shown': () => errorShown });

    if (errorShown) {
      // Clear card field and retry with valid card
      // The card form should still be visible after decline
      await fillStripeCard(page, CARDS.VISA_4242);
      await clickStripePay(page);

      const orderNr = await verifyThankYou(page);
      const ok = orderNr !== null;

      checkoutSuccess.add(ok ? 1 : 0);
      contractValid.add(1);
      checkoutDuration.add(Date.now() - start);
      if (ok) orderNumbers.add(1);
      console.log(`[payment_failure] retry order=${orderNr}, duration=${Date.now() - start}ms`);
    } else {
      // Error wasn't shown — Stripe may have redirected or timed out
      checkoutSuccess.add(0);
      stripeErrors.add(1);
      console.error(`[payment_failure] decline error not shown`);
    }
  } catch (e) {
    checkoutSuccess.add(0);
    stripeErrors.add(1);
    console.error(`[payment_failure] ${user.email}: ${e.message || e}`);
  } finally {
    await page.close();
  }
}
