/**
 * Stripe Checkout page interaction helpers.
 *
 * Mirrors: StripeCheckoutPage.ts from Playwright e2e tests.
 *
 * k6 browser API notes:
 *   - No page.waitForURL() — use polling
 *   - No locator.isVisible() — use waitFor with try/catch
 *   - Stripe Checkout uses React — must use locator.click(), not evaluate clicks
 *   - Card form fields appear after clicking the Card accordion radio
 */

import { CARD_DETAILS } from './config.js';

/**
 * Fill card details on Stripe Checkout hosted page.
 *
 * Flow: select Card radio → fill card number → Tab → expiry → Tab → CVC → Tab → name
 */
export async function fillStripeCard(page, cardNumber, expiry, cvc, name) {
  cardNumber = cardNumber || '4242424242424242';
  expiry     = expiry     || CARD_DETAILS.EXPIRY;
  cvc        = cvc        || CARD_DETAILS.CVC;
  name       = name       || CARD_DETAILS.NAME;

  // Stripe Checkout is a React SPA — wait for render
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(5000);

  // Select Card payment method (Stripe React requires real browser click)
  const cardRadio = page.locator('#payment-method-accordion-item-title-card');
  await cardRadio.waitFor({ state: 'visible', timeout: 10000 });
  await cardRadio.click({ force: true });
  await page.waitForTimeout(3000);

  // Fill email if visible (shown for guest or when no Stripe Customer)
  try {
    const emailInput = page.locator('#email');
    await emailInput.waitFor({ state: 'visible', timeout: 3000 });
    await emailInput.fill('playwright.user@oxid-esales.dev');
  } catch { /* prefilled */ }

  // Fill card number — click the placeholder input, then type
  const cardInput = page.locator('input[placeholder*="1234"], input[name="cardNumber"], #cardNumber');
  await cardInput.waitFor({ state: 'visible', timeout: 10000 });
  await cardInput.click();
  await page.keyboard.type(cardNumber, { delay: 50 });
  await page.waitForTimeout(500);

  // Tab → expiry
  await page.keyboard.press('Tab');
  await page.waitForTimeout(300);
  await page.keyboard.type(expiry, { delay: 50 });
  await page.waitForTimeout(500);

  // Tab → CVC
  await page.keyboard.press('Tab');
  await page.waitForTimeout(300);
  await page.keyboard.type(cvc, { delay: 50 });
  await page.waitForTimeout(500);

  // Tab → cardholder name
  await page.keyboard.press('Tab');
  await page.waitForTimeout(300);
  await page.keyboard.type(name, { delay: 30 });
  await page.waitForTimeout(500);
}

/**
 * Click Pay button on Stripe Checkout.
 */
export async function clickStripePay(page) {
  try {
    const payBtn = page.locator('[data-testid="hosted-payment-submit-button"]');
    await payBtn.waitFor({ state: 'visible', timeout: 10000 });
    await payBtn.click();
  } catch {
    const altBtn = page.locator('.SubmitButton');
    await altBtn.waitFor({ state: 'visible', timeout: 5000 });
    await altBtn.click();
  }
}

/**
 * Handle 3DS challenge iframe.
 * Click Complete or Fail button inside the 3DS test iframe.
 */
export async function handle3DS(page, action) {
  action = action || 'Complete';
  const btnSelector = action === 'Complete'
    ? '#test-source-authorize-3ds'
    : '#test-source-fail-3ds';

  await page.waitForTimeout(5000);

  const allFrames = page.frames();
  for (const frame of allFrames) {
    try {
      const btn = frame.locator(btnSelector);
      await btn.waitFor({ state: 'visible', timeout: 5000 });
      await btn.click();
      return;
    } catch { /* not this frame */ }
  }
}

/**
 * Wait for thank you page redirect and extract order number.
 */
export async function verifyThankYou(page) {
  for (let i = 0; i < 30; i++) {
    await page.waitForTimeout(1000);
    try {
      const url = page.url();
      if (url.includes('thankyou') || url.includes('cl=thankyou')) {
        break;
      }
    } catch { /* context changing during redirect */ }
  }

  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2000);

  try {
    const url = page.url();
    if (url.includes('thankyou') || url.includes('cl=thankyou')) {
      const orderNr = await page.evaluate(() => {
        const body = document.body.innerText || '';
        const m = body.match(/(?:Bestellnummer|order\s*(?:with\s+)?number)[:\s]*(\d+)/i)
          || body.match(/\b(\d{4,})\b/);
        return m ? m[1] : 'unknown';
      }).catch(() => 'unknown');
      return orderNr;
    }
    return null;
  } catch {
    return null;
  }
}

/**
 * Wait for Stripe error message (declined, insufficient funds, etc.).
 */
export async function waitForStripeError(page, timeout) {
  timeout = timeout || 15000;
  try {
    const errorMsg = page.locator('[role="alert"], .FieldError, .SubmitButton--error');
    await errorMsg.waitFor({ state: 'visible', timeout });
    return true;
  } catch {
    return false;
  }
}

/**
 * Wait for redirect back to shop from Stripe Checkout.
 */
export async function waitForShopRedirect(page, timeout) {
  timeout = timeout || 30;
  for (let i = 0; i < timeout; i++) {
    await page.waitForTimeout(1000);
    try {
      const url = page.url();
      if (!url.includes('stripe.com') && !url.includes('checkout.stripe')) {
        return url;
      }
    } catch { /* context changing */ }
  }
  return null;
}
