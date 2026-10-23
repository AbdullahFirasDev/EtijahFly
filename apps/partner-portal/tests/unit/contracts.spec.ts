import { BOOKING_STATUS_TONE, BOOKING_STATUSES, errorMessage, formatDuration, formatLocalTime, formatMoney, newIdempotencyKey } from '@etijahfly/api-contracts'
import { describe, expect, it } from 'vitest'

describe('shared contracts helpers', () => {
  it('formats IQD without decimals and USD with two, using Latin digits', () => {
    // Intl separates the currency code with a non-breaking space
    expect(formatMoney('1250000.00', 'IQD', 'en').replace(/\s/g, ' ')).toBe('IQD 1,250,000')
    expect(formatMoney('274.05', 'USD', 'en')).toBe('$274.05')
    expect(formatMoney('274.05', 'USD', 'ar')).toMatch(/274\.05/)
    expect(formatMoney(null, 'USD')).toBe('—')
  })

  it('keeps airport local times from the ISO offset', () => {
    expect(formatLocalTime('2026-11-14T07:35:00+03:00')).toBe('07:35')
    expect(formatDuration(170, 'en')).toBe('2h 50m')
    expect(formatDuration(65, 'ar')).toBe('1س 05د')
  })

  it('maps every booking status to a tone', () => {
    for (const status of BOOKING_STATUSES) {
      expect(BOOKING_STATUS_TONE[status]).toBeDefined()
    }
    expect(BOOKING_STATUS_TONE.ticketed).toBe('success')
    expect(BOOKING_STATUS_TONE.failed).toBe('error')
  })

  it('creates unique idempotency keys accepted by the API pattern', () => {
    const a = newIdempotencyKey()
    const b = newIdempotencyKey()
    expect(a).not.toBe(b)
    expect(a).toMatch(/^[A-Za-z0-9\-_:]{8,120}$/)
  })

  it('extracts API error messages', () => {
    expect(errorMessage({ message: 'Price changed', code: 'PRICE_CHANGED' })).toBe('Price changed')
    expect(errorMessage('boom', 'fallback')).toBe('fallback')
  })
})
