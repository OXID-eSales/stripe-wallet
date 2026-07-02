/**
 * Shop interaction helpers — mirrors Playwright e2e page objects.
 *
 * k6 browser API notes:
 *   - No .first() on locators — use page.evaluate() for DOM queries
 *   - page.evaluate() clicks don't trigger React — use locator.click() for React UIs
 *   - Avoid page.evaluate() clicks that navigate — causes "context lost" errors
 */

import { BASE_URL } from './config.js';

// ─── Helpers ──────────────────────────────────────────────────────

/** Get the browser's current origin (OXID may redirect HTTP→HTTPS). */
async function getOrigin(page) {
  try {
    return await page.evaluate(() => location.origin);
  } catch {
    // Context may be lost after redirect — wait and retry
    await page.waitForTimeout(1000);
    await page.waitForLoadState('domcontentloaded');
    return page.evaluate(() => location.origin);
  }
}

// ─── Product helpers ──────────────────────────────────────────────

/**
 * Add a product to cart: Axle-parts → first product → variant → Add to cart.
 */
export async function addProductsToCart(page) {
  // Navigate to category — use the browser's current origin if available
  // (avoids HTTP→HTTPS redirect context loss in k6)
  let origin;
  try {
    origin = await page.evaluate(() => location.origin);
  } catch {
    origin = null;
  }
  // If origin is null/about:blank, use BASE_URL for initial nav
  if (!origin || origin === 'null' || origin.includes('about:')) {
    origin = BASE_URL;
  }

  await page.goto(`${origin}/en/Spare-parts/Axle-parts/?lang=1`, {
    waitUntil: 'domcontentloaded',
  });
  // Wait for potential redirect to settle
  await page.waitForTimeout(2000);
  await page.waitForLoadState('domcontentloaded');

  // Get first product href
  const productUrl = await page.evaluate(() => {
    const link = document.querySelector('#productList a[href*=".html"]')
      || document.querySelector('.product-list a[href*=".html"]');
    return link ? link.href : null;
  });

  if (!productUrl) {
    throw new Error('[shop] No product found in Axle-parts category');
  }
  await page.goto(productUrl, { waitUntil: 'domcontentloaded' });

  // Select variant if available
  await page.evaluate(() => {
    const sel = document.querySelector('select');
    if (sel && sel.options.length > 1) {
      sel.selectedIndex = 1;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
  await page.waitForTimeout(1000);

  // Click "Add to cart"
  const addBtn = page.locator('#toBasket');
  await addBtn.waitFor({ state: 'visible', timeout: 8000 });
  await addBtn.click();
  await page.waitForTimeout(2000);
}

// ─── Checkout navigation ──────────────────────────────────────────

/**
 * Navigate: cart → user step → payment step → order page.
 */
export async function navigateToCheckout(page) {
  const origin = await getOrigin(page);

  // Cart page
  await page.goto(`${origin}/index.php?cl=basket&lang=0`, {
    waitUntil: 'domcontentloaded',
  });

  // Get checkout link and navigate
  const checkoutUrl = await page.evaluate(() => {
    const link = document.querySelector('a.nextStep')
      || document.querySelector('a[href*="cl=user"]');
    if (link) return link.href;
    const allLinks = Array.from(document.querySelectorAll('a'));
    const checkout = allLinks.find(el => /zur kasse|checkout/i.test(el.textContent));
    return checkout ? checkout.href : null;
  });

  if (checkoutUrl) {
    await page.goto(checkoutUrl, { waitUntil: 'domcontentloaded' });
  } else {
    await page.goto(`${origin}/index.php?cl=user`, { waitUntil: 'domcontentloaded' });
  }
  await page.waitForTimeout(1000);

  // Loop through checkout steps until order page
  let maxSteps = 5;
  while (maxSteps > 0) {
    await page.waitForTimeout(1000);
    const currentUrl = page.url();

    if (currentUrl.includes('cl=order') || currentUrl.includes('thankyou')) {
      break;
    }

    // Select Stripe payment if visible
    await page.evaluate(() => {
      const label = document.querySelector('label[for="payment_oe_payments_stripe_wallet"]');
      if (label) { label.click(); return; }
      const allLabels = Array.from(document.querySelectorAll('label'));
      const stripe = allLabels.find(l => /digitale börse|stripe/i.test(l.textContent));
      if (stripe) stripe.click();
    });
    await page.waitForTimeout(500);

    // Click next step button
    const clicked = await page.evaluate(() => {
      const btn = document.querySelector('button.nextStep')
        || document.querySelector('#userNextStepBottom')
        || document.querySelector('#paymentNextStepBottom');
      if (btn) { btn.click(); return true; }
      const allBtns = Array.from(document.querySelectorAll('button'));
      const cont = allBtns.find(el => /weiter|continue|next/i.test(el.textContent));
      if (cont) { cont.click(); return true; }
      return false;
    });

    if (!clicked) break;

    await page.waitForTimeout(2000);
    await page.waitForLoadState('domcontentloaded');
    maxSteps--;
  }
}

/**
 * Accept terms, submit order, wait for Stripe redirect.
 */
export async function selectStripeAndOrder(page) {
  if (!page.url().includes('cl=order')) {
    await navigateToCheckout(page);
  }

  // Accept terms
  await page.evaluate(() => {
    const terms = document.querySelector('#checkAgb')
      || document.querySelector('input[name*="ord_agb"]');
    if (terms && !terms.checked) {
      terms.checked = true;
      terms.click();
    }
  });

  // Click Stripe checkout button
  const btnFound = await page.evaluate(() => {
    const btn = document.getElementById('stripe-checkout-btn')
      || document.querySelector('#orderConfirmAgbBottom')
      || document.querySelector('button[type="submit"]');
    if (btn) { btn.click(); return true; }
    return false;
  });

  if (!btnFound) {
    throw new Error('[shop] No order button found');
  }

  // Wait for Stripe redirect
  await page.waitForTimeout(5000);
}

// ─── Coupon helpers ───────────────────────────────────────────────

/**
 * Apply a coupon code on the cart page.
 */
export async function applyCoupon(page, code) {
  const origin = await getOrigin(page);

  await page.goto(`${origin}/index.php?cl=basket&lang=0`, {
    waitUntil: 'domcontentloaded',
  });

  // Expand voucher section if collapsed
  await page.evaluate(() => {
    const toggle = document.querySelector('[data-bs-target="#voucherCollapse"]');
    if (toggle && toggle.getAttribute('aria-expanded') !== 'true') {
      toggle.click();
    }
  });
  await page.waitForTimeout(500);

  // Fill voucher input and submit
  const voucherInput = page.locator('#input_voucherNr');
  await voucherInput.waitFor({ state: 'visible', timeout: 5000 });
  await voucherInput.fill(code);

  await page.evaluate(() => {
    const btn = document.querySelector('.voucher-btn')
      || document.querySelector('button[title*="Coupon"]')
      || document.querySelector('button[title*="coupon"]');
    if (btn) btn.click();
  });
  // Form submit navigates — wait for page to settle
  await page.waitForTimeout(2000);
  await page.waitForLoadState('domcontentloaded');
}
