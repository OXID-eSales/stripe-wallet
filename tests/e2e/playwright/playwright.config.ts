import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

dotenv.config({ path: path.resolve(__dirname, '.env') });

export default defineConfig({
  testDir: './tests',
  testMatch: '**/*.spec.ts',
  timeout: 120000,
  expect: { timeout: 15000 },

  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 2 : 0,



  use: {
    baseURL: process.env.SHOP_URL || 'https://localhost.local',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 30000,
    navigationTimeout: 60000,
    viewport: { width: 1280, height: 720 },
    ignoreHTTPSErrors: true,
    locale: 'en-US',
  },

  projects: [
    // Setup project - runs first to authenticate admin
    {
      name: 'admin-setup',
      testMatch: /auth\.setup\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },

    // Admin tests - depend on setup, use saved auth state
    {
      name: 'admin-tests',
      testMatch: /tests\/admin\/.*.spec.ts/,
      dependencies: ['admin-setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'reports/admin-auth.json',
      },
    },

    // Checkout tests - no auth needed, run independently
    {
      name: 'checkout-tests',
      testMatch: /tests\/checkout\/.*.spec.ts/,
      use: { ...devices['Desktop Chrome'] },
    },

    // Default project for running all tests
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  outputDir: './reports/test-results',
});
