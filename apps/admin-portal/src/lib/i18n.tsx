'use client'

import { createContext, useCallback, useContext, useMemo } from 'react'
import type { ReactNode } from 'react'
import ar from '@/messages/ar.json'
import en from '@/messages/en.json'
import { LOCALE_COOKIE } from './locale'
import type { Locale } from './locale'

type Messages = typeof ar
const DICTIONARIES: Record<Locale, Messages> = { ar, en }

interface I18nValue {
  locale: Locale
  t: (key: string, params?: Record<string, string | number>) => string
  setLocale: (locale: Locale) => void
}

const I18nContext = createContext<I18nValue | null>(null)

function lookup(messages: unknown, key: string): string | undefined {
  const value = key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), messages)
  return typeof value === 'string' ? value : undefined
}

export function translate(locale: Locale, key: string, params?: Record<string, string | number>): string {
  const template = lookup(DICTIONARIES[locale], key) ?? lookup(DICTIONARIES.en, key) ?? key
  return params ? template.replace(/\{(\w+)\}/g, (_, name: string) => String(params[name] ?? `{${name}}`)) : template
}

export function I18nProvider({ locale, children }: { locale: Locale, children: ReactNode }) {
  const t = useCallback((key: string, params?: Record<string, string | number>) => translate(locale, key, params), [locale])

  const setLocale = useCallback((next: Locale) => {
    document.cookie = `${LOCALE_COOKIE}=${next}; path=/; max-age=31536000; samesite=strict`
    window.location.reload()
  }, [])

  const value = useMemo(() => ({ locale, t, setLocale }), [locale, t, setLocale])
  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}

export function useI18n(): I18nValue {
  const value = useContext(I18nContext)
  if (!value) throw new Error('useI18n must be used inside I18nProvider')
  return value
}
