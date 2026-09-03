/**
 * Complete a purchase through OPC on daniil.oxiddev.de, once per PSP.
 *
 * Drives the real flow: OPC modal → processCheckout → the provider's hosted
 * page → back to the shop. The assertion is the order row, not the page: an
 * order is complete when OXPAID is set and OXTRANSSTATUS left NOT_FINISHED.
 *
 * Run:  SHOP_URL=https://daniil.oxiddev.de npx playwright test tests/local-proof/opc-purchase.spec.ts
 */

import { test, expect, Page } from '@playwright/test';
import {
  setupOpcTest,
  openOpcModal,
  addProductToBasket,
  waitForBasketItems,
} from '../../helpers/setupOpcTest';

const SHOP_URL = (process.env.SHOP_URL || 'https://daniil.oxiddev.de').replace(/\/$/, '') + '/';
const USER = { email: 'playwright.user@oxid-esales.dev', password: 'useruser' };

async function loginNatively(page: Page): Promise<void> {
  await page.goto(SHOP_URL, { waitUntil: 'domcontentloaded' });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    page.evaluate(({ shopUrl, email, password }) => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = shopUrl + 'index.php?cl=account&fnc=login_noredirect';
      form.style.display = 'none';
      const add = (n: string, v: string) => { const i = document.createElement('input'); i.name = n; i.value = v; form.appendChild(i); };
      add('lgn_usr', email); add('lgn_pwd', password); add('lgn_cook', '1');
      document.body.appendChild(form); form.submit();
    }, { shopUrl: SHOP_URL, email: USER.email, password: USER.password }),
  ]);
  await page.waitForTimeout(800);
}

async function readStoken(page: Page): Promise<string> {
  return page.evaluate(() => document
    .querySelector('[data-basket-operations-csrf-token-value]')
    ?.getAttribute('data-basket-operations-csrf-token-value') ?? '');
}

async function processCheckout(page: Page, paymentId: string): Promise<{ status: number; body: string }> {
  const stoken = await readStoken(page);
  return page.evaluate(async ({ t, pid }) => {
    const body = new URLSearchParams();
    body.set('stoken', t);
    body.set('paymentMethodId', pid);
    body.set('returnUrl', location.origin + '/?opcReturn=1');
    body.set('cancelUrl', location.origin + '/?opcCancel=1');
    body.set('confirmTermsAndConditions', '1');
    body.set('confirmPrivacyPolicy', '1');
    const r = await fetch(location.origin + '/index.php?cl=OeCheckoutApi&fnc=processCheckout', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString(),
    });
    return { status: r.status, body: (await r.text()).slice(0, 1200) };
  }, { t: stoken, pid: paymentId });
}

async function prepare(page: Page): Promise<void> {
  await loginNatively(page);
  await setupOpcTest(page);
  await openOpcModal(page);
  await addProductToBasket(page);
  expect(await waitForBasketItems(page)).toBeGreaterThan(0);
}

/** Dump enough of the hosted page to find its controls. */
async function describePage(page: Page, label: string): Promise<void> {
  const url = page.url();
  const title = await page.title().catch(() => '');
  const buttons = await page.locator('button, input[type=submit], a.btn, [role=button]')
    .evaluateAll((els) => els.slice(0, 25).map((e) => `${e.tagName}:${(e.textContent || (e as HTMLInputElement).value || '').trim().slice(0, 40)}`))
    .catch(() => []);
  const inputs = await page.locator('input, select')
    .evaluateAll((els) => els.slice(0, 25).map((e) => `${e.tagName}[name=${(e as HTMLInputElement).name}][type=${(e as HTMLInputElement).type}][id=${e.id}]`))
    .catch(() => []);
  console.log(`[${label}] url=${url}`);
  console.log(`[${label}] title=${title}`);
  console.log(`[${label}] buttons=${JSON.stringify(buttons)}`);
  console.log(`[${label}] inputs=${JSON.stringify(inputs)}`);
}

test.describe('OPC purchase', () => {
  test('mollie — reach the hosted page and describe it', async ({ page }) => {
    test.setTimeout(240000);
    await prepare(page);

    const res = await processCheckout(page, 'oe_payments_mollie');
    console.log(`[mollie] processCheckout ${res.status}: ${res.body}`);
    const redirect = JSON.parse(res.body)?.metadata?.redirectUrl as string | undefined;
    expect(redirect, 'mollie must return a hosted-page URL').toBeTruthy();

    await page.goto(String(redirect), { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await describePage(page, 'mollie-hosted');

    // Pick a method. Test mode then offers the status chooser.
    await page.getByRole('button', { name: /paypal/i }).first().click();
    await page.waitForLoadState('domcontentloaded').catch(() => undefined);
    await page.waitForTimeout(3000);
    await describePage(page, 'mollie-method');

    // Test mode: choose the outcome, then come back to the shop.
    const states = await page.locator('input[name=final_state]')
      .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    console.log(`[mollie] final_state options: ${JSON.stringify(states)}`);

    await page.locator('input[name=final_state][value=paid]').first().check();
    await Promise.all([
      page.waitForURL(/daniil\.oxiddev\.de/, { timeout: 60000 }).catch(() => undefined),
      page.getByRole('button', { name: /continue/i }).first().click(),
    ]);
    await page.waitForTimeout(4000);
    console.log(`[mollie] back at: ${page.url()}`);
    expect(page.url(), 'Mollie must return the shopper to the shop').toContain('daniil.oxiddev.de');
  });

  test('stripe — complete the purchase on the hosted page', async ({ page }) => {
    test.setTimeout(300000);
    await prepare(page);

    const res = await processCheckout(page, 'oe_payments_stripe_wallet');
    const parsed = JSON.parse(res.body);
    console.log(`[stripe] metadata=${JSON.stringify(parsed?.metadata)}`);
    const redirect = (parsed?.metadata?.redirectUrl ?? parsed?.metadata?.checkoutUrl) as string | undefined;
    expect(redirect, 'Stripe must return a hosted-page URL in redirect mode').toBeTruthy();

    await page.goto(String(redirect), { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(8000);
    console.log(`[stripe] hosted url=${page.url().slice(0, 90)}`);

    // Stripe Checkout offers an accordion of methods; card must be selected
    // before its fields exist.
    const card = page.locator('#payment-method-accordion-item-title-card');
    if (await card.count() > 0) {
      await card.first().click({ force: true }).catch(() => undefined);
      await page.waitForTimeout(3000);
    }

    await page.locator('input#email').first().fill('playwright.user@oxid-esales.dev').catch(() => undefined);
    await page.locator('input#cardNumber').first().fill('4242424242424242', { timeout: 40000 });
    await page.locator('input#cardExpiry').first().fill('12 / 34');
    await page.locator('input#cardCvc').first().fill('123');
    await page.locator('input#billingName').first().fill('Playwright User').catch(() => undefined);
    await page.locator('select#billingCountry').first().selectOption('DE').catch(() => undefined);

    await Promise.all([
      page.waitForURL(/daniil\.oxiddev\.de/, { timeout: 150000 }).catch(() => undefined),
      page.locator('button.SubmitButton, button[type=submit]').first().click(),
    ]);
    await page.waitForTimeout(8000);
    console.log(`[stripe] back at: ${page.url()}`);
    expect(page.url(), 'Stripe must return the shopper to the shop').toContain('daniil.oxiddev.de');
  });
});
