import { describe, expect, it } from 'vitest'
import { isAllowedApiPath } from '../../server/utils/allowlist'

describe('partner proxy allowlist', () => {
  it.each([
    'partner/bookings',
    'partner/bookings/12/issue',
    'partner/flights/offers/01JB2C3D4E5F6G7H8J9K0MNPQR/price-check',
    'auth/me',
    'auth/sessions/5',
    'notifications',
    'notifications/1b4e28ba-2fa1-11d2-883f-0016d3cca427/read',
    'reference/airports',
    'public/branding',
    'public/tenants/rafidain/logo',
    'invitations/aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7d/accept',
  ])('allows %s', (path) => {
    expect(isAllowedApiPath(path)).toBe(true)
  })

  it.each([
    'admin/tenants',
    'admin/finance/payments/1/approve',
    'partner/../admin/tenants',
    'partner//bookings',
    'auth/login', // handled by the dedicated BFF route that sets the cookie
    'reference/../../admin',
    'public/tenants/../../admin',
    'invitations/short',
    'partner\\bookings',
    '',
  ])('blocks %s', (path) => {
    expect(isAllowedApiPath(path)).toBe(false)
  })
})
