import { defineConfig, devices } from '@playwright/test'

// Runs against an already running stack (see infrastructure/local/e2e.ps1):
// API on E2E_API_URL, this portal's production build on E2E_PARTNER_URL.
export default defineConfig({
  testDir: './e2e',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_PARTNER_URL ?? 'http://127.0.0.1:3100',
    locale: 'ar-IQ',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
