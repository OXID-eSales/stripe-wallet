/**
 * Authentication helpers.
 * Uses same selectors as Playwright LoginPage.ts:
 *   #loginUser, #loginPwd, #loginButton
 */

import { BASE_URL, TEST_PASSWORD, USER_COUNT } from './config.js';

export function getRandomUser() {
  const testUserEmail = __ENV.K6_TEST_USER_EMAIL || '';
  if (testUserEmail) {
    return { email: testUserEmail, password: TEST_PASSWORD };
  }
  const idx = Math.floor(Math.random() * USER_COUNT) + 1;
  const num = String(idx).padStart(3, '0');
  return {
    email: `loadtest_user_${num}@oxid-esales.dev`,
    password: TEST_PASSWORD,
  };
}

/**
 * Login via #loginUser/#loginPwd/#loginButton (same as Playwright LoginPage.ts).
 */
export async function loginAndSetup(page, user) {
  await page.goto(`${BASE_URL}/index.php?cl=account`, {
    waitUntil: 'domcontentloaded',
  });

  // Accept cookies
  try {
    const acceptBtn = page.locator('button#onetrust-accept-btn-handler, .uc-accept-all-btn');
    await acceptBtn.waitFor({ state: 'visible', timeout: 5000 });
    await acceptBtn.click();
  } catch { /* no banner */ }

  // Fill login form (Playwright selectors)
  const emailInput = page.locator('#loginUser');
  await emailInput.waitFor({ state: 'visible', timeout: 8000 });
  await emailInput.fill(user.email);
  await page.locator('#loginPwd').fill(user.password);
  await page.locator('#loginButton').click();
  await page.waitForLoadState('domcontentloaded');

  // Extract force_sid from URL — OXID uses URL-based sessions, not cookies
  const afterUrl = page.url();
  const sidMatch = afterUrl.match(/force_sid=([a-f0-9]+)/);
  const sid = sidMatch ? sidMatch[1] : '';
  console.log(`[auth] After login URL: ${afterUrl}, SID: ${sid}`);

  // Switch to English if needed
  try {
    const enLink = page.locator('a[hreflang="en"]');
    await enLink.waitFor({ state: 'visible', timeout: 3000 });
    await enLink.click();
    await page.waitForLoadState('domcontentloaded');
  } catch { /* already English */ }
}
