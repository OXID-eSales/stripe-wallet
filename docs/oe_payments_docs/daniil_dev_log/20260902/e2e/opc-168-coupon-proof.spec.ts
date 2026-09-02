/**
 * OPC-168 proof, adapted to run without the datagen REST API.
 *
 * The canonical spec is
 * tests/datagen-rest/opc-168-coupon-survives-interrupted-checkout.spec.ts. It
 * seeds its user, basket and coupon through `cl=OeDataGenApi`, which neither
 * daniil.oxiddev.de nor osc1.oxid.shop exposes. The interesting half is
 * unchanged here — the same eager-checkout POST, the same server-side read of
 * the session basket. Only the setup differs:
 *
 *   user    playwright.user@oxid-esales.dev / useruser  (already in the shop)
 *   basket  filled through the shared OPC helpers, which submit the shop's own
 *           tobasket form with a live stoken
 *   coupon  OPC168PROOF — 10 %, oxallowsameseries = 0, so exactly one
 *           redeemable row stands behind the code and a second apply cannot
 *           quietly pick a fresh one
 *
 * The two tests share the single seeded voucher row, so run them one at a time
 * and reset the row between (that is what a datagen-seeded fresh row buys the
 * canonical spec):
 *
 *   UPDATE oxvouchers SET oxorderid='', oxuserid='', oxdiscount=0,
 *          oxdateused=NULL, oxreserved=0 WHERE oxid='opc168_v1';
 *
 * Run:  SHOP_URL=https://daniil.oxiddev.de npx playwright test tests/local-proof --grep "used again"
 */

import { test, expect, Page } from '@playwright/test';
import {
  setupOpcTest,
  openOpcModal,
  addProductToBasket,
  waitForBasketItems,
  expandVoucherSection,
} from '../../helpers/setupOpcTest';

const SHOP_URL = (process.env.SHOP_URL || 'https://daniil.oxiddev.de').replace(/\/$/, '') + '/';
const CODE = process.env.PROOF_VOUCHER_CODE || 'OPC168PROOF';
const PAYMENT_ID = process.env.PROOF_PAYMENT_ID || 'oe_payments_mollie';


const USER = { email: 'playwright.user@oxid-esales.dev', password: 'useruser' };

/** The shop's own login path — the one the rest of the OPC suite uses. */
async function loginNatively(page: Page): Promise<void> {
  await page.goto(SHOP_URL, { waitUntil: 'domcontentloaded' });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    page.evaluate(
      ({ shopUrl, email, password }) => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = shopUrl + 'index.php?cl=account&fnc=login_noredirect';
        form.style.display = 'none';
        const add = (name: string, value: string) => {
          const i = document.createElement('input');
          i.name = name;
          i.value = value;
          form.appendChild(i);
        };
        add('lgn_usr', email);
        add('lgn_pwd', password);
        add('lgn_cook', '1');
        document.body.appendChild(form);
        form.submit();
      },
      { shopUrl: SHOP_URL, email: USER.email, password: USER.password },
    ),
  ]);
  await page.waitForTimeout(800);
}

async function readStoken(page: Page): Promise<string> {
  return page.evaluate(() => {
    const el = document.querySelector('[data-basket-operations-csrf-token-value]');
    return el?.getAttribute('data-basket-operations-csrf-token-value') ?? '';
  });
}

/** The eager mount, reduced to the one request it makes. No card, no pay button. */
async function triggerEagerCheckout(page: Page): Promise<{ status: number; body: string }> {
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
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    });
    const text = await r.text();
    return { status: r.status, body: text.slice(0, 700) };
  }, { t: stoken, pid: PAYMENT_ID });
}

/** What the SESSION BASKET holds, straight from the server, not from the chip. */
async function serverVouchers(page: Page): Promise<{ codes: string[]; discount: number }> {
  return page.evaluate(async (shopUrl) => {
    const r = await fetch(shopUrl + 'index.php?cl=OeBasketSummary&fnc=getSummary', {
      credentials: 'same-origin',
    });
    const j = await r.json();
    const list = (j?.summary?.vouchers ?? []) as Array<{ code?: string }>;
    return {
      codes: list.map((v) => String(v?.code ?? '')),
      discount: Number(j?.summary?.voucherDiscount ?? 0),
    };
  }, SHOP_URL);
}

async function applyVoucherViaUi(page: Page, code: string): Promise<{ ok: boolean; message: string }> {
  const input = page.locator('input[data-basket-voucher-target="voucherInput"]');
  await input.fill(code);
  const response = page.waitForResponse((r) => r.url().includes('fnc=applyVoucher'), { timeout: 20000 });
  await page.locator('button[data-basket-voucher-target="applyButton"]').click();
  const json = await (await response).json().catch(() => null);
  await page.waitForTimeout(1000);
  return { ok: json?.success === true, message: String(json?.message ?? json?.error ?? '') };
}


/**
 * Take the coupon off the basket the way a shopper does. The trash button goes
 * through a `confirm()`, which Playwright dismisses by default — so the click
 * silently does nothing unless the dialog is accepted.
 *
 * Removal also calls `unMarkAsReserved()`, which zeroes `oxreserved`. That is
 * what makes an immediate re-apply legitimate: `getVoucherByNr(..., true)`
 * skips rows reserved inside `iVoucherTimeout`, so without the removal the
 * re-apply would fail on a perfectly healthy shop too.
 */
async function removeCouponViaUi(page: Page, code: string): Promise<void> {
  page.once('dialog', (d) => void d.accept());
  const removed = page.waitForResponse((r) => r.url().includes('fnc=removeVoucher'), { timeout: 20000 });
  await page
    .locator(`[data-basket-voucher-target="appliedVouchers"] .voucher-remove-btn[data-voucher-code="${code}"]`)
    .click();
  await removed;
  await page.waitForTimeout(1000);
}


/**
 * Come back to the shop as a returning shopper does: a real page load, then the
 * modal. The shared `openOpcModal` only shows the modal on the page already
 * loaded — and the release runs in `FrontendController::init`, so without the
 * navigation it never gets a request to run in. Getting this wrong makes the
 * spec report an OPC defect that is really a missing reload.
 */
async function reopenAfterReturn(page: Page): Promise<void> {
  await page.goto(SHOP_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => undefined);
  await openOpcModal(page);
}

test.beforeEach(async ({ page }) => {
  page.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning') {
      console.log(`[browser:${m.type()}] ${m.text().slice(0, 300)}`);
    }
  });
  page.on('pageerror', (e) => console.log(`[browser:pageerror] ${e.message.slice(0, 300)}`));
  page.on('requestfailed', (r) => console.log(`[browser:requestfailed] ${r.url().slice(0, 160)}`));
});

test.describe('OPC-168 — a one-time coupon must not be consumed by an unpaid checkout', () => {
  test('the coupon survives an interrupted checkout', async ({ page }) => {
    test.setTimeout(180000);

    await loginNatively(page);
    await setupOpcTest(page);
    await openOpcModal(page);
    await addProductToBasket(page);
    const items = await waitForBasketItems(page);
    expect(items, 'precondition: the basket must hold something to discount').toBeGreaterThan(0);

    await expandVoucherSection(page);
    const applied = await applyVoucherViaUi(page, CODE);
    expect(applied.ok, `the fresh coupon must apply: ${applied.message}`).toBe(true);

    const before = await serverVouchers(page);
    console.log(`[OPC-168] applied: ${JSON.stringify(before)}`);
    expect(before.codes, 'precondition: the server holds the coupon').toContain(CODE);
    expect(before.discount, 'precondition: it is discounting').toBeGreaterThan(0);

    // Interrupt: the eager checkout books an unpaid order, and finalizeOrder
    // marks this voucher row used on the way through.
    const checkout = await triggerEagerCheckout(page);
    console.log(`[OPC-168] processCheckout HTTP ${checkout.status}`);
    console.log(`[OPC-168] processCheckout body: ${checkout.body}`);
    expect(checkout.status, 'the eager checkout must actually have run').toBeLessThan(500);
    // A refusal BEFORE the early order books nothing and consumes no coupon, so
    // the assertions below would pass on an untouched basket. Reaching the PSP
    // is what matters, not the PSP answering — the early order is created on the
    // way there. `invalid_payment_method` means we never got that far.
    expect(
      checkout.body,
      'the checkout must reach the payment step — invalid_payment_method means no '
        + 'order was booked, so nothing was ever at risk',
    ).not.toContain('invalid_payment_method');
    console.log(`[OPC-168] straight after checkout: ${JSON.stringify(await serverVouchers(page))}`);

    // Walk away and come back. The release runs in FrontendController::init on
    // this page load — no opcModalId involved.
    await reopenAfterReturn(page);
    await expandVoucherSection(page);

    const after = await serverVouchers(page);
    console.log(`[OPC-168] after reopen: ${JSON.stringify(after)}`);

    expect(
      after.codes,
      'the coupon was consumed by an order that was never paid, so the basket must still hold it',
    ).toContain(CODE);
    expect(after.discount, 'and it must still be discounting').toBeGreaterThan(0);
  });

  /**
   * The assertion that matters, and the one the basket-summary read above cannot
   * make: is the CODE usable again? The summary reports what the session basket
   * holds, which can still name a coupon whose `oxvouchers` row is marked to an
   * unpaid order — attached, but spent.
   */
  test('the same code can be used again after the checkout was abandoned', async ({ page }) => {
    test.setTimeout(180000);

    await loginNatively(page);
    await setupOpcTest(page);
    await openOpcModal(page);
    await addProductToBasket(page);
    expect(await waitForBasketItems(page)).toBeGreaterThan(0);

    await expandVoucherSection(page);
    expect((await applyVoucherViaUi(page, CODE)).ok, 'coupon applies').toBe(true);

    const checkout = await triggerEagerCheckout(page);
    console.log(`[OPC-168] processCheckout body: ${checkout.body}`);
    expect(checkout.body, 'the checkout must reach the payment step').not.toContain('invalid_payment_method');

    await reopenAfterReturn(page);
    await expandVoucherSection(page);
    await removeCouponViaUi(page, CODE);
    expect((await serverVouchers(page)).codes, 'coupon is off the basket').not.toContain(CODE);

    const again = await applyVoucherViaUi(page, CODE);
    expect(
      again.ok,
      'the coupon was consumed by an order nobody paid for, so the code must be '
        + `redeemable again — got: ${again.message}`,
    ).toBe(true);
  });
});
