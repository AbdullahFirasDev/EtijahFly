'use client'

import { formatDate, formatDuration, formatLocalTime } from '@etijahfly/api-contracts'
import type { Booking } from '@etijahfly/api-contracts'
import { useParams } from 'next/navigation'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Badge, Button, Card, Dialog, Field, Money, PageHeader, StatusBadge, Textarea } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

export default function AdminBookingPage() {
  const { id } = useParams<{ id: string }>()
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const { data, refresh } = useQuery(`booking:${id}`, () => http.get<{ data: Booking }>(`/admin/bookings/${id}`))
  const booking = data?.data
  const [cancelOpen, setCancelOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function verify() {
    await http.post(`/admin/bookings/${id}/verify-ticketing`)
    setNotice(t('bookings.verifyQueued'))
  }

  async function cancel() {
    try {
      await http.post(`/admin/bookings/${id}/cancel`, { reason })
      setCancelOpen(false)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  if (!booking) return null
  const internal = booking.internal

  return (
    <RequirePermission permission="platform.bookings.view">
      <PageHeader
        title={booking.reference}
        description={`${internal?.tenant?.name ?? ''} · ${booking.route}`}
        actions={
          <>
            <StatusBadge status={booking.status} />
            {booking.is_test && <Badge tone="warning">{t('bookings.testOnly')}</Badge>}
            {can('platform.bookings.manage') && ['pending_verification', 'needs_attention'].includes(booking.ticketing_status ?? '') && <Button variant="secondary" onClick={verify}>{t('bookings.verify')}</Button>}
            {can('platform.bookings.manage') && ['pending', 'on_hold'].includes(booking.status) && <Button variant="danger" onClick={() => setCancelOpen(true)}>{t('bookings.cancel')}</Button>}
          </>
        }
      />
      {notice && <div className="mb-4"><Alert tone="success">{notice}</Alert></div>}
      {booking.failure && <div className="mb-4"><Alert tone="error" title={booking.failure.code ?? ''}>{booking.failure.reason}</Alert></div>}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card title={`${t('bookings.pnr')}: ${booking.pnr ?? '—'}`}>
            <div className="space-y-4">
              {booking.itinerary.map((slice, i) => (
                <div key={i} className="rounded-lg bg-slate-50 p-3 text-sm">
                  <p className="font-medium"><span className="ltr">{slice.origin} → {slice.destination}</span> · <span className="ltr">{formatDuration(slice.duration_minutes, locale)}</span></p>
                  {slice.segments.map((s, j) => (
                    <p key={j} className="ltr text-slate-600">{s.flight_number} · {s.origin} {formatLocalTime(s.departure_at)} → {s.destination} {formatLocalTime(s.arrival_at)} · {s.departure_at.slice(0, 10)}</p>
                  ))}
                </div>
              ))}
            </div>
          </Card>
          <Card title={t('bookings.passengers')}>
            <ul className="divide-y divide-slate-100 text-sm">
              {booking.passengers?.map(p => (
                <li key={p.id} className="flex justify-between py-2">
                  <span className="ltr font-medium">{p.last_name}/{p.first_name} {p.title} <span className="text-slate-500">({p.type} · {p.document_number_masked})</span></span>
                  <span className="ltr font-mono">{p.ticket_number ?? ''}</span>
                </li>
              ))}
            </ul>
          </Card>
          <Card title={t('bookings.timeline')}>
            <ol className="space-y-3 border-s border-slate-200 ps-4 text-sm">
              {booking.events?.map(e => (
                <li key={e.id}>
                  <p>{e.message}</p>
                  <p className="text-xs text-slate-500">{formatDate(e.created_at, locale, true)} · {e.actor?.name ?? e.actor_type} · <span className="ltr">{e.type}</span></p>
                </li>
              ))}
            </ol>
          </Card>
        </div>
        <div className="space-y-6">
          <Card title={t('bookings.total')}>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between"><dt className="text-slate-500">{t('bookings.supplier')}</dt><dd><Money amount={internal?.supplier_total} currency={booking.price.currency} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">{t('bookings.markup')}</dt><dd><Money amount={internal?.markup} currency={booking.price.currency} /></dd></div>
              <div className="flex justify-between font-semibold"><dt>{t('bookings.total')}</dt><dd><Money amount={booking.price.total} currency={booking.price.currency} /></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">{t('bookings.commission')}</dt><dd><Money amount={booking.price.commission} currency={booking.price.currency} /></dd></div>
              <div className="flex justify-between text-emerald-700"><dt>{t('bookings.profit')}</dt><dd><Money amount={internal?.platform_profit} currency={booking.price.currency} /></dd></div>
              {booking.settlement.charged_amount && <div className="flex justify-between border-t border-slate-100 pt-2"><dt className="text-slate-500">{t('bookings.charged')}</dt><dd><Money amount={booking.settlement.charged_amount} currency={booking.settlement.account_currency} /> {booking.settlement.fx_rate && booking.settlement.account_currency !== booking.price.currency && <span className="ltr text-xs text-slate-500">@ {Number(booking.settlement.fx_rate)}</span>}</dd></div>}
            </dl>
          </Card>
          <Card title={t('bookings.rules')}>
            <pre className="ltr overflow-x-auto rounded bg-slate-50 p-2 text-xs">{JSON.stringify(internal?.pricing_rules, null, 2)}</pre>
            <p className="mt-2 text-xs text-slate-500">{t('bookings.providerId')}: <span className="ltr">{booking.provider} · {internal?.provider_booking_id ?? '—'}</span></p>
          </Card>
          <Card title={t('bookings.documents')}>
            <ul className="space-y-1 text-sm">
              {booking.documents?.map(d => (
                <li key={d.id}><a className="text-brand-700" target="_blank" rel="noreferrer" href={`/api/proxy/admin/bookings/${booking.id}/documents/${d.id}/file`}>{d.filename}</a></li>
              ))}
            </ul>
          </Card>
        </div>
      </div>

      <Dialog open={cancelOpen} title={t('bookings.cancel')} onClose={() => setCancelOpen(false)} footer={<Button variant="danger" onClick={cancel} disabled={!reason.trim()}>{t('common.confirm')}</Button>}>
        {error && <div className="mb-3"><Alert tone="error">{error}</Alert></div>}
        <Field label={t('common.reason')} required>{fid => <Textarea id={fid} rows={3} value={reason} onChange={e => setReason(e.target.value)} />}</Field>
      </Dialog>
    </RequirePermission>
  )
}
