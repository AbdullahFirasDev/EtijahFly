import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'

const PASSWORD = process.env.E2E_PASSWORD ?? 'Etijah-Dev-2026!'
const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8100/api/v1'

async function login(page: Page, email: string) {
  await page.goto('/login')
  await page.getByTestId('email').fill(email)
  await page.getByTestId('password').fill(PASSWORD)
  await page.getByTestId('login-submit').click()
  await page.waitForURL(url => !url.pathname.startsWith('/login'))
}

/** Submit a pending payment as an agency accountant, directly against the API. */
async function submitPayment(amount: string): Promise<string> {
  const auth = await fetch(`${API}/auth/login`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', accept: 'application/json' },
    body: JSON.stringify({ email: 'accountant@babylon.test', password: PASSWORD, portal: 'partner' }),
  }).then(r => r.json())

  const payment = await fetch(`${API}/partner/finance/payments`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'accept': 'application/json', 'authorization': `Bearer ${auth.token}`, 'idempotency-key': crypto.randomUUID() },
    body: JSON.stringify({ amount, currency: 'USD', method: 'bank_transfer', paid_at: new Date().toISOString().slice(0, 10), external_reference: 'E2E-TRX' }),
  }).then(r => r.json())

  return payment.data.reference as string
}

test('unauthenticated visitors are redirected to login', async ({ page }) => {
  await page.goto('/tenants')
  await expect(page).toHaveURL(/\/login\?redirect=%2Ftenants/)
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
})

test('agency users cannot sign in to the admin portal', async ({ page }) => {
  await page.goto('/login')
  await page.getByTestId('email').fill('owner@rafidain.test')
  await page.getByTestId('password').fill(PASSWORD)
  await page.getByTestId('login-submit').click()
  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page).toHaveURL(/\/login/)
})

test('super admin sees the platform dashboard and seeded companies', async ({ page }) => {
  await login(page, 'admin@etijahfly.test')
  await expect(page.getByRole('heading', { name: 'لوحة المنصة' })).toBeVisible()

  await page.goto('/tenants')
  const table = page.getByTestId('tenants-table')
  await expect(table).toContainText('Al-Rafidain Travel')
  await expect(table).toContainText('Babylon Tours')

  await table.getByRole('link', { name: 'Babylon Tours' }).click()
  await expect(page.getByRole('heading', { name: 'Babylon Tours' })).toBeVisible()
})

test('platform finance approves an agency payment, which is credited once', async ({ page }) => {
  const reference = await submitPayment('321.50')

  await login(page, 'finance@etijahfly.test')
  await page.goto('/finance/payments?status=pending')
  const row = page.getByTestId('payments-table').locator('tr', { hasText: reference })
  await expect(row).toBeVisible()

  await row.getByTestId('approve-payment').click()
  await page.getByTestId('confirm-review').click()
  await expect(page.getByTestId('payments-table').locator('tr', { hasText: reference })).toHaveCount(0)

  await page.goto('/finance/payments?status=approved')
  await expect(page.getByTestId('payments-table').locator('tr', { hasText: reference })).toBeVisible()
})

test('platform staff can read the audit log and system health but not manage users', async ({ page }) => {
  await login(page, 'staff@etijahfly.test')

  await page.goto('/audit')
  await expect(page.getByTestId('audit-table')).toContainText('auth.login')

  await page.goto('/system')
  await expect(page.getByTestId('health-checks')).toContainText('database')

  await page.goto('/users')
  await expect(page.getByText('ليست لديك صلاحية لعرض هذه الصفحة.')).toBeVisible()
})
