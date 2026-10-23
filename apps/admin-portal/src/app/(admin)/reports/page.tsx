'use client'

import { formatDate } from '@etijahfly/api-contracts'
import type { Paginated, Tenant } from '@etijahfly/api-contracts'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Button, Card, DataTable, Input, Ledger, Money, PageHeader, Select, StatusBadge } from '@/components/ui'
import type { LedgerRow } from '@/components/ui'
import { http } from '@/lib/api'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

interface Summary { bookings_by_status: Record<string, number>, sales_by_currency: LedgerRow[], refunded_bookings: number, top_airlines: { airline: string, currency: string, bookings: number, sales: string }[], daily: { day: string, currency: string, bookings: number, sales: string }[] }
interface CommissionRow { booking_id: number, reference: string, currency: string, sale_total: string, commission: string, account_currency: string, commission_credited: string | null, clawed_back: boolean, date: string }

export default function ReportsPage() {
  const { t, locale } = useI18n()
  const [range, setRange] = useState(() => ({
    from: new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10),
    to: new Date().toISOString().slice(0, 10),
    tenant_id: '',
  }))
  const [applied, setApplied] = useState(range)
  const { data: tenants } = useQuery('tenants:reports', () => http.get<Paginated<Tenant>>('/admin/tenants'))
  const { data } = useQuery(`summary:${JSON.stringify(applied)}`, () => http.get<Summary>('/admin/reports/summary', applied))
  const { data: commissions } = useQuery(`commissions:${JSON.stringify(applied)}`, () => http.get<{ data: CommissionRow[] }>('/admin/reports/commissions', applied))

  return (
    <RequirePermission permission="platform.reports.view">
      <PageHeader
        title={t('reports.title')}
        actions={
          <>
            <Select value={range.tenant_id} placeholder={t('common.all')} onChange={e => setRange(r => ({ ...r, tenant_id: e.target.value }))} options={(tenants?.data ?? []).map(tn => ({ value: String(tn.id), label: tn.name }))} className="w-48" />
            <Input type="date" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} className="w-40" />
            <Input type="date" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} className="w-40" />
            <Button variant="secondary" onClick={() => setApplied(range)}>{t('common.apply')}</Button>
          </>
        }
      />
      {data && (
        <div className="grid gap-8 lg:grid-cols-5">
          <Card title={t('reports.sales')} className="lg:col-span-3">
            {!data.sales_by_currency.length && <p className="text-sm text-slate-500">{t('common.empty')}</p>}
            <div className="grid gap-x-10 gap-y-8 md:grid-cols-2">
              {data.sales_by_currency.map(row => <Ledger key={row.currency} row={row} />)}
            </div>
          </Card>

          <div className="space-y-8 lg:col-span-2">
            <Card title={t('reports.byStatus')}>
              <ul className="divide-y divide-slate-100">{Object.entries(data.bookings_by_status).map(([s, c]) => <li key={s} className="flex items-center justify-between py-2"><StatusBadge status={s} /><span className="font-medium tabular-nums">{c}</span></li>)}</ul>
            </Card>
            <Card title={t('reports.topAirlines')}>
              <ul className="divide-y divide-slate-100 text-sm">{data.top_airlines.map(a => <li key={a.airline + a.currency} className="flex items-center justify-between gap-4 py-2"><span className="ltr font-display text-base font-semibold">{a.airline}</span><span className="flex items-baseline gap-3"><span className="text-xs text-slate-500">{t('ledger.bookings', { n: a.bookings })}</span><Money amount={a.sales} currency={a.currency} className="font-medium" /></span></li>)}</ul>
            </Card>
          </div>
        </div>
      )}
      <Card className="mt-8" title={t('reports.commissions')} padded={false}>
        <DataTable
          rows={commissions?.data}
          rowKey={c => c.booking_id}
          columns={[
            { key: 'ref', header: t('bookings.reference'), cell: c => <span className="ltr">{c.reference}</span> },
            { key: 'date', header: t('common.date'), cell: c => formatDate(c.date, locale) },
            { key: 'sale', header: t('bookings.total'), cell: c => <Money amount={c.sale_total} currency={c.currency} /> },
            { key: 'comm', header: t('bookings.commission'), cell: c => <Money amount={c.commission} currency={c.currency} /> },
            { key: 'credited', header: '', cell: c => <Money amount={c.commission_credited} currency={c.account_currency} className={c.clawed_back ? 'line-through opacity-60' : ''} /> },
          ]}
        />
      </Card>
    </RequirePermission>
  )
}
