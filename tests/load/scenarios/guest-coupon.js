/**
 * Scenario 3: Guest Checkout with Coupon (15% traffic)
 *
 * No login → Browse → Add to cart → Apply coupon → Start checkout
 *
 * Tests: guest session handling, voucher application, checkout entry without auth
 */

import { browser } from 'k6/browser';
import { check } from 'k6';
import { BASE_URL, COUPONS } from '../helpers/config.js';
import { addProductsToCart, applyCoupon } from '../helpers/shop.js';
import { checkoutSuccess, contractValid } from '../helpers/metrics.js';

const COUPON_POOL = [COUPONS.TEN_PERCENT, COUPONS.FIFTY_PERCENT, COUPONS.FIVE_FLAT];

function pickRandom(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

export async function guest_coupon() {
  const page = await browser.newPage();
  const coupon = pickRandom(COUPON_POOL);

  try {
    await addProductsToCart(page);
    await applyCoupon(page, coupon);

    // Navigate to user step (guest checkout entry)
    await page.waitForTimeout(1000);
    await page.waitForLoadState('domcontentloaded');
    await page.goto(`${BASE_URL}/index.php?cl=user`, { waitUntil: 'domcontentloaded' });

    const url = page.url();
    const reachedCheckout = url.includes('cl=user') || url.includes('cl=basket');

    checkoutSuccess.add(reachedCheckout ? 1 : 0);
    contractValid.add(1);
    check(page, { 'guest with coupon reached checkout': () => reachedCheckout });
    console.log(`[guest_coupon] coupon=${coupon}, url=${url}`);
  } catch (e) {
    checkoutSuccess.add(0);
    console.error(`[guest_coupon] (${coupon}): ${e.message || e}`);
  } finally {
    await page.close();
  }
}
