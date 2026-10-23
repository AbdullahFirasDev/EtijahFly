'use client'

import { RequirePermission } from '@/components/shell'
import { Card, Ledger, PageHeader, QueueItem, StatusBadge, Spinner, Money } from '@/components/ui'
import type { LedgerRow } from '@/components/ui'
import { http } from '@/lib/api'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

interface Dashboard {
  tenants: { active: number, suspended: number }
  bookings_today: number
  on_hold: number
  needs_attention: number
  pending_payments: number
  pending_adjustments: number
  month: { sales_by_currency: LedgerRow[], bookings_by_status: Record<string, number>, top_airlines: { airline: string, currency: string, bookings: number, sales: string }[] }
}

export default function DashboardPage() {
  const { t } = useI18n()
  const { data, loading } = useQuery('dashboard', () => http.get<Dashboard>('/admin/dashboard'))

  return (
    <RequirePermission permission="platform.dashboard">
      <PageHeader title={t('nav.dashboard')} />
      {loading && !data && <Spinner />}
      {data && (
        <div className="space-y-8">
          <section aria-labelledby="queues">
            <h2 id="queues" className="mb-3 font-semibold">{t('dashboard.waiting')}</h2>
            <div className="grid gap-px overflow-hidden rounded-[10px] border border-slate-200 bg-slate-200 grid-cols-2 lg:grid-cols-4">
              <QueueItem count={data.needs_attention} label={t('dashboard.attention')} href="/bookings?ticketing_status=pending_verification,needs_attention" tone="error" />
              <QueueItem count={data.pending_payments} label={t('dashboard.pendingPayments')} href="/finance/payments?status=pending" />
              <QueueItem count={data.pending_adjustments} label={t('dashboard.pendingAdjustments')} href="/finance/adjustments?status=pending" />
              <QueueItem count={data.on_hold} label={t('dashboard.onHold')} href="/bookings?status=on_hold" />
            </div>
          </section>

          <div className="grid gap-8 lg:grid-cols-5">
            <Card title={t('dashboard.monthSales')} className="lg:col-span-3">
              {data.month.sales_by_currency.length === 0 && <p className="text-sm text-slate-500">{t('common.empty')}</p>}
              <div className="grid gap-x-10 gap-y-8 md:grid-cols-2">
                {data.month.sales_by_currency.map(row => <Ledger key={row.currency} row={row} />)}
              </div>
            </Card>

            <div className="space-y-8 lg:col-span-2">
              <Card title={t('dashboard.today')}>
                <dl className="grid grid-cols-2 gap-4">
                  <div>
                    <dt className="text-sm text-slate-500">{t('dashboard.bookingsToday')}</dt>
                    <dd className="mt-1 font-display text-3xl font-semibold tabular-nums">{data.bookings_today}</dd>
                  </div>
                  <div>
                    <dt className="text-sm text-slate-500">{t('dashboard.activeTenants')}</dt>
                    <dd className="mt-1 font-display text-3xl font-semibold tabular-nums">{data.tenants.active}</dd>
                    {data.tenants.suspended > 0 && <dd className="mt-1 text-xs text-loss">{t('dashboard.suspendedCount', { n: data.tenants.suspended })}</dd>}
                  </div>
                </dl>
              </Card>

              <Card title={t('reports.byStatus')}>
                {!Object.keys(data.month.bookings_by_status).length && <p className="text-sm text-slate-500">{t('common.empty')}</p>}
                <ul className="divide-y divide-slate-100">
                  {Object.entries(data.month.bookings_by_status).map(([status, count]) => (
                    <li key={status} className="flex items-center justify-between py-2"><StatusBadge status={status} /><span className="font-medium tabular-nums">{count}</span></li>
                  ))}
                </ul>
              </Card>

              <Card title={t('reports.topAirlines')}>
                {!data.month.top_airlines.length && <p className="text-sm text-slate-500">{t('common.empty')}</p>}
                <ul className="divide-y divide-slate-100 text-sm">
                  {data.month.top_airlines.map(a => (
                    <li key={a.airline + a.currency} className="flex items-center justify-between gap-4 py-2">
                      <span className="ltr font-display text-base font-semibold">{a.airline}</span>
                      <span className="flex items-baseline gap-3">
                        <span className="text-xs text-slate-500">{t('ledger.bookings', { n: a.bookings })}</span>
                        <Money amount={a.sales} currency={a.currency} className="font-medium" />
                      </span>
                    </li>
                  ))}
                </ul>
              </Card>
            </div>
          </div>
        </div>
      )}
    </RequirePermission>
  )
}
