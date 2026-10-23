import { describe, expect, it } from 'vitest'
import ar from '@/messages/ar.json'
import en from '@/messages/en.json'
import { translate } from '@/lib/i18n'

function keys(node: unknown, prefix = ''): string[] {
  if (!node || typeof node !== 'object') return [prefix]
  return Object.entries(node as Record<string, unknown>).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k))
}

describe('admin i18n', () => {
  it('has the same keys in Arabic and English', () => {
    expect(keys(ar).sort()).toEqual(keys(en).sort())
  })

  it('translates with parameters and falls back to the key', () => {
    expect(translate('en', 'common.page', { page: 2, last: 5 })).toBe('Page 2 of 5')
    expect(translate('ar', 'common.page', { page: 2, last: 5 })).toBe('صفحة 2 من 5')
    expect(translate('ar', 'does.not.exist')).toBe('does.not.exist')
  })
})
