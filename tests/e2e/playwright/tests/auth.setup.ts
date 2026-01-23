import { test as setup } from '@playwright/test';
import { AdminLoginPage } from '../pages/admin/AdminLoginPage';

/**
 * Admin Authentication Setup
 *
 * This setup project runs before admin tests and saves the authentication
 * state to a file. Subsequent admin tests reuse this state, avoiding
 * the need to log in for each test.
 */
setup('admin authentication', async ({ page }) => {
  console.log('Setting up admin authentication...');

  const adminLogin = new AdminLoginPage(page);
  await adminLogin.navigate();
  await adminLogin.login();

  // Wait for login to complete and page to stabilize
  await page.waitForLoadState('networkidle');

  // Verify login was successful
  const isLoggedIn = await adminLogin.isLoggedIn();
  if (!isLoggedIn) {
    throw new Error('Admin login failed during setup');
  }

  console.log('Admin login successful, saving session state...');

  // Save signed-in state to file
  await page.context().storageState({ path: 'reports/admin-auth.json' });

  console.log('Admin session state saved to reports/admin-auth.json');
});
