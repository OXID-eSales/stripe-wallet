import { test, expect, Page } from '@playwright/test';
import { setupOpcTest, openOpcModal, addProductToBasket, waitForBasketItems } from '../../helpers/setupOpcTest';

const SHOP_URL = (process.env.SHOP_URL || 'https://daniil.oxiddev.de').replace(/\/$/, '') + '/';
const USER = { email: 'playwright.user@oxid-esales.dev', password: 'useruser' };

async function login(page: Page): Promise<void> {
  await page.goto(SHOP_URL, { waitUntil: 'domcontentloaded' });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    page.evaluate(({ u, e, p }) => {
      const f = document.createElement('form');
      f.method = 'POST'; f.action = u + 'index.php?cl=account&fnc=login_noredirect'; f.style.display = 'none';
      const add = (n: string, v: string) => { const i = document.createElement('input'); i.name = n; i.value = v; f.appendChild(i); };
      add('lgn_usr', e); add('lgn_pwd', p); add('lgn_cook', '1');
      document.body.appendChild(f); f.submit();
    }, { u: SHOP_URL, e: USER.email, p: USER.password }),
  ]);
  await page.waitForTimeout(800);
}

test('payment list — what does the endpoint answer for a real session?', async ({ page }) => {
  test.setTimeout(180000);
  await login(page);
  await setupOpcTest(page);
  await openOpcModal(page);
  await addProductToBasket(page);
  expect(await waitForBasketItems(page)).toBeGreaterThan(0);

  const res = await page.evaluate(async () => {
    const r = await fetch(location.origin + '/?cl=OeOpcPayment&fnc=getPaymentListJson', {
      credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return { status: r.status, body: (await r.text()).slice(0, 800) };
  });
  console.log(`[paylist] HTTP ${res.status}: ${res.body}`);

  const count = await page.locator('#buyNowCheckoutModal input[type=radio]').count();
  console.log(`[paylist] modal radios: ${count}`);
});
