// Shared by server components (root layout) and the client i18n provider. Must not be a
// 'use client' module: values imported from one into a server component become client references.
export const LOCALES = ['ar', 'en'] as const
export type Locale = (typeof LOCALES)[number]
export const LOCALE_COOKIE = 'ef_admin_locale'

export function resolveLocale(value: string | undefined): Locale {
  return (LOCALES as readonly string[]).includes(value ?? '') ? (value as Locale) : 'ar'
}
