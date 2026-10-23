'use client'

import { Activity, BarChart3, Building2, ClipboardList, Landmark, Languages, LayoutDashboard, LogOut, Menu, Percent, Plug, Receipt, RotateCcw, Scale, Ticket, Users, X } from 'lucide-react'
import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Spinner, cn } from '@/components/ui'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'

interface NavItem { href: string, label: string, icon: typeof Ticket, permission: string }

export function Shell({ children }: { children: ReactNode }) {
  const { me, loading, can, logout } = useAuth()
  const { t, locale, setLocale } = useI18n()
  const pathname = usePathname()
  const router = useRouter()
  const [open, setOpen] = useState(false)

  useEffect(() => {
    if (!loading && !me) router.replace(`/login?redirect=${encodeURIComponent(pathname)}`)
  }, [loading, me, pathname, router])

  if (loading || !me) {
    return <div className="flex min-h-screen items-center justify-center"><Spinner /></div>
  }

  const sections: { title: string, items: NavItem[] }[] = [
    { title: t('nav.operations'), items: [
      { href: '/', label: t('nav.dashboard'), icon: LayoutDashboard, permission: 'platform.dashboard' },
      { href: '/tenants', label: t('nav.tenants'), icon: Building2, permission: 'platform.tenants.view' },
      { href: '/bookings', label: t('nav.bookings'), icon: Ticket, permission: 'platform.bookings.view' },
      { href: '/refunds', label: t('nav.refunds'), icon: RotateCcw, permission: 'platform.bookings.view' },
    ] },
    { title: t('nav.finance'), items: [
      { href: '/finance/payments', label: t('nav.payments'), icon: Receipt, permission: 'platform.finance.view' },
      { href: '/finance/adjustments', label: t('nav.adjustments'), icon: Scale, permission: 'platform.finance.view' },
      { href: '/finance/accounts', label: t('nav.accounts'), icon: Landmark, permission: 'platform.finance.view' },
      { href: '/pricing', label: t('nav.pricing'), icon: Percent, permission: 'platform.pricing.view' },
      { href: '/reports', label: t('nav.reports'), icon: BarChart3, permission: 'platform.reports.view' },
    ] },
    { title: t('nav.platform'), items: [
      { href: '/providers', label: t('nav.providers'), icon: Plug, permission: 'platform.providers.view' },
      { href: '/users', label: t('nav.users'), icon: Users, permission: 'platform.users.manage' },
      { href: '/audit', label: t('nav.audit'), icon: ClipboardList, permission: 'platform.audit.view' },
      { href: '/system', label: t('nav.system'), icon: Activity, permission: 'platform.system.view' },
    ] },
  ]

  const isActive = (href: string) => (href === '/' ? pathname === '/' : pathname.startsWith(href))

  const nav = (
    <nav className="space-y-6 p-4">
      {sections.map((section) => {
        const items = section.items.filter(i => can(i.permission))
        if (!items.length) return null
        return (
          <div key={section.title}>
            <p className="mb-1.5 px-3 text-xs font-medium text-slate-400">{section.title}</p>
            <ul className="space-y-0.5">
              {items.map(item => (
                <li key={item.href}>
                  <Link
                    href={item.href}
                    onClick={() => setOpen(false)}
                    aria-current={isActive(item.href) ? 'page' : undefined}
                    className={cn('relative flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors', isActive(item.href) ? 'bg-brand-50 text-brand-700 before:absolute before:inset-y-1.5 before:start-0 before:w-0.5 before:rounded-full before:bg-brand-600' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900')}
                  >
                    <item.icon className="size-4" aria-hidden />
                    {item.label}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
    </nav>
  )

  return (
    <div className="min-h-screen">
      <aside className="fixed inset-y-0 start-0 z-30 hidden w-64 flex-col border-e border-slate-200 bg-white lg:flex">
        <Link href="/" className="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 px-5">
          <span className="size-3 shrink-0 rotate-45 rounded-[2px] bg-brand-600" aria-hidden />
          <div className="leading-tight">
            <p className="font-display text-lg font-semibold">{t('app.name')}</p>
            <p className="text-xs text-slate-500">{t('app.subtitle')}</p>
          </div>
        </Link>
        <div className="flex-1 overflow-y-auto">{nav}</div>
      </aside>

      {open && (
        <div className="fixed inset-0 z-40 bg-slate-900/40 lg:hidden" onClick={() => setOpen(false)}>
          <div className="h-full w-72 overflow-y-auto bg-white" onClick={e => e.stopPropagation()}>
            <div className="flex h-16 items-center justify-between border-b border-slate-200 px-4">
              <span className="font-display text-lg font-semibold">{t('app.name')}</span>
              <button type="button" onClick={() => setOpen(false)} aria-label="close"><X className="size-5" /></button>
            </div>
            {nav}
          </div>
        </div>
      )}

      <div className="lg:ps-64">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-slate-100/85 px-4 backdrop-blur sm:px-8">
          <button type="button" className="rounded-md p-1.5 hover:bg-slate-200 lg:hidden" onClick={() => setOpen(true)} aria-label="menu"><Menu className="size-5" /></button>
          <span className="font-display font-semibold lg:hidden">{t('app.name')}</span>
          <div className="ms-auto flex items-center gap-3">
            <button type="button" onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')} className="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-200">
              <Languages className="size-4" aria-hidden />
              {locale === 'ar' ? 'English' : 'العربية'}
            </button>
            <div className="hidden text-end text-sm sm:block">
              <p className="font-medium">{me.name}</p>
              <p className="text-xs text-slate-500">{me.roles.map(r => r.label).join('، ')}</p>
            </div>
            <button type="button" onClick={() => logout()} className="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-200 hover:text-slate-900" data-testid="logout">
              <LogOut className="size-4" aria-hidden />
              <span className="hidden sm:inline">{t('nav.logout')}</span>
            </button>
          </div>
        </header>
        <main className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-8">{children}</main>
      </div>
    </div>
  )
}

export function RequirePermission({ permission, children }: { permission: string, children: ReactNode }) {
  const { can } = useAuth()
  const { t } = useI18n()
  if (!can(permission)) return <p className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">{t('common.forbidden')}</p>
  return <>{children}</>
}
