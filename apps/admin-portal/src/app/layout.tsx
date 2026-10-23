import type { Metadata } from 'next'
import { IBM_Plex_Sans_Arabic, Reem_Kufi } from 'next/font/google'
import { cookies } from 'next/headers'
import type { ReactNode } from 'react'
import { AuthProvider } from '@/lib/auth'
import { I18nProvider } from '@/lib/i18n'
import { LOCALE_COOKIE, resolveLocale } from '@/lib/locale'
import './globals.css'

const plex = IBM_Plex_Sans_Arabic({
  subsets: ['arabic', 'latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-plex-arabic',
  display: 'swap',
})

const kufi = Reem_Kufi({
  subsets: ['arabic', 'latin'],
  weight: ['500', '600', '700'],
  variable: '--font-kufi',
  display: 'swap',
})

export const metadata: Metadata = {
  title: { default: 'EtijahFly Admin', template: '%s · EtijahFly Admin' },
  robots: { index: false, follow: false },
}

export default async function RootLayout({ children }: { children: ReactNode }) {
  const locale = resolveLocale((await cookies()).get(LOCALE_COOKIE)?.value)

  return (
    <html lang={locale} dir={locale === 'ar' ? 'rtl' : 'ltr'} className={`${plex.variable} ${kufi.variable}`}>
      <body>
        <I18nProvider locale={locale}>
          <AuthProvider>{children}</AuthProvider>
        </I18nProvider>
      </body>
    </html>
  )
}
