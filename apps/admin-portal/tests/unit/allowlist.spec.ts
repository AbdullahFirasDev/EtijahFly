import { describe, expect, it } from 'vitest'
import { isAllowedApiPath } from '@/lib/allowlist'

describe('admin proxy allowlist', () => {
  it.each([
    'admin/dashboard',
    'admin/tenants/4/credit-limit',
    'admin/finance/payments/7/approve',
    'admin/providers/mock/health-check',
    'auth/me',
    'auth/sessions/3',
    'notifications/read-all',
  ])('allows %s', path => expect(isAllowedApiPath(path)).toBe(true))

  it.each([
    'partner/bookings',
    'partner/finance/account',
    'admin/../partner/bookings',
    'admin//tenants',
    'auth/login',
    'public/branding',
    '',
  ])('blocks %s', path => expect(isAllowedApiPath(path)).toBe(false))
})
