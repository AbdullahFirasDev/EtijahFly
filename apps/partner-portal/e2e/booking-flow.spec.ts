import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'

const PASSWORD = process.env.E2E_PASSWORD ?? 'Etijah-Dev-2026!'

async function login(page: Page, email: string) {
  await page.goto('/login?agency=rafidain')
  await page.getByTestId('email').fill(email)
  await page.getByTestId('password').fill(PASSWORD)
  await page.getByTestId('login-submit').click()
  await page.waitForURL(url => !url.pathname.startsWith('/login'))
}

test('login page is white-labelled, RTL Arabic by default, and rejects wrong passwords', async ({ page }) => {
  await page.goto('/login?agency=rafidain')
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
  await expect(page.getByRole('heading', { name: 'Al-Rafidain Travel' })).toBeVisible()

  await page.getByTestId('email').fill('agent@rafidain.test')
  await page.getByTestId('password').fill('not-the-password')
  await page.getByTestId('login-submit').click()
  await expect(page.getByTestId('login-error')).toBeVisible()
  await expect(page).toHaveURL(/\/login/)
})

test('the API token never reaches browser JavaScript', async ({ page, context }) => {
  await login(page, 'agent@rafidain.test')
  const cookie = (await context.cookies()).find(c => c.name === 'ef_partner_session')
  expect(cookie?.httpOnly).toBe(true)
  expect(cookie?.sameSite).toBe('Strict')
  const visible = await page.evaluate(() => document.cookie)
  expect(visible).not.toContain('ef_partner_session')
})

test('agent searches, holds, issues and gets provider tickets (test data clearly labelled)', async ({ page }) => {
  await login(page, 'agent@rafidain.test')

  await page.goto('/flights')
  await page.getByTestId('search-submit').click()
  await expect(page.getByTestId('test-data-banner')).toBeVisible()
  const offers = page.getByTestId('offer-card')
  await expect(offers.first()).toBeVisible()

  await offers.first().getByTestId('select-offer').click()
  await expect(page.getByTestId('booking-form')).toBeVisible()
  await expect(page.getByTestId('test-data-banner')).toBeVisible()

  await page.getByTestId('p0-first').fill('ALI')
  await page.getByTestId('p0-last').fill('HASSAN')
  await page.getByTestId('p0-dob').fill('1988-04-12')
  await page.getByTestId('p0-nat').fill('IQ')
  await page.getByTestId('p0-doc').fill('A12345678')
  await page.getByTestId('p0-exp').fill('2031-01-01')
  await page.getByTestId('p0-country').fill('IQ')
  await page.getByTestId('contact-email').fill('traveller@example.com')

  await expect(page.getByTestId('booking-submit')).toBeEnabled()
  await page.getByTestId('booking-submit').click()

  // Hold placed at the provider: PNR shown, no ticket number yet
  await page.waitForURL(/\/bookings\/\d+/)
  await expect(page.getByTestId('pnr')).toHaveText(/^[A-Z2-9]{6}$/)
  await expect(page.getByTestId('ticket-number')).toHaveCount(0)
  await expect(page.getByTestId('timeline')).toContainText('PNR')

  await page.getByTestId('issue-button').click()
  await page.getByTestId('confirm-action').click()

  // Ticket numbers only appear once the provider issued them (test prefix 000)
  await expect(page.getByTestId('ticket-number')).toHaveText(/^000-\d{10}$/, { timeout: 30_000 })
  await expect(page.getByTestId('open-eticket')).toBeVisible({ timeout: 30_000 })
})

test('an accountant sees finance but not flight search', async ({ page }) => {
  await login(page, 'accountant@rafidain.test')

  await expect(page.getByRole('link', { name: 'بحث الرحلات' })).toHaveCount(0)
  await page.goto('/finance')
  await expect(page.getByTestId('available').first()).toBeVisible()

  const response = await page.goto('/flights')
  expect(response?.status() ?? 200).toBeLessThan(500)
  await expect(page.getByText('403')).toBeVisible()
})

test('the partner proxy refuses admin API routes', async ({ page }) => {
  await login(page, 'owner@rafidain.test')
  const status = await page.evaluate(async () => (await fetch('/api/proxy/admin/tenants')).status)
  expect(status).toBe(404)
})
