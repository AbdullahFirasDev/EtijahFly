'use client'

import { BOOKING_STATUSES, formatDate } from '@etijahfly/api-contracts'
import type { Booking, Paginated } from '@etijahfly/api-contracts'
import Link from 'next/link'
import { useSearchParams } from 'next/navigation'
import { Suspense, useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Badge, Card, DataTable, Input, Money, PageHeader, Pagination, Select, StatusBadge } from '@/components/ui'
import { http } from '@/lib/api'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

function BookingsList() {
  const { t, locale } = useI18n()
  const params = useSearchParams()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState(params.get('status') ?? '')
  const [page, setPage] = useState(1)
  const tenantId = params.get('tenant_id') ?? ''
  const ticketing = params.get('ticketing_status') ?? ''

  const { data, loading } = useQuery(`bookings:${q}:${status}:${page}:${tenantId}:${ticketing}`, () =>
    http.get<Paginated<Booking>>('/admin/bookings', { q, status, page, tenant_id: tenantId, ticketing_status: ticketing }))

  return (
    <>
      <PageHeader title={t('bookings.title')} />
      <div className="mb-4 flex flex-wrap gap-3">
        <Input className="max-w-xs" placeholder={t('common.search')} value={q} onChange={e => { setQ(e.target.value); setPage(1) }} />
        <Select className="max-w-xs" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }} placeholder={t('common.all')} options={BOOKING_STATUSES.map(s => ({ value: s, label: t(`status.${s}`) }))} />
      </div>
      <Card padded={false}>
        <DataTable
          testId="admin-bookings-table"
          rows={data?.data}
          loading={loading}
          rowKey={b => b.id}
          columns={[
            { key: 'ref', header: t('bookings.reference'), cell: b => <span className="flex items-center gap-2"><Link href={`/bookings/${b.id}`} className="ltr font-medium text-brand-700">{b.reference}</Link>{b.is_test && <Badge tone="warning">TEST</Badge>}</span> },
            { key: 'tenant', header: t('common.tenant'), cell: b => b.internal?.tenant?.name ?? b.internal?.tenant_id },
            { key: 'route', header: t('bookings.route'), cell: b => <span className="ltr">{b.route}</span> },
            { key: 'status', header: t('common.status'), cell: b => <StatusBadge status={b.status} /> },
            { key: 'ticketing', header: t('bookings.ticketing'), cell: b => <StatusBadge status={b.ticketing_status} /> },
            { key: 'total', header: t('bookings.total'), cell: b => <Money amount={b.price.total} currency={b.price.currency} /> },
            { key: 'profit', header: t('bookings.profit'), cell: b => <Money amount={b.internal?.platform_profit} currency={b.price.currency} className="text-emerald-700" /> },
            { key: 'created', header: t('common.date'), cell: b => formatDate(b.created_at, locale, true) },
          ]}
        />
      </Card>
      <Pagination page={page} last={data?.meta.last_page ?? 1} onChange={setPage} />
    </>
  )
}

export default function BookingsPage() {
  return (
    <RequirePermission permission="platform.bookings.view">
      <Suspense><BookingsList /></Suspense>
    </RequirePermission>
  )
}
