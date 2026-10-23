'use client'

import { formatDate } from '@etijahfly/api-contracts'
import type { Paginated, Refund } from '@etijahfly/api-contracts'
import Link from 'next/link'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Button, Card, DataTable, Dialog, Field, Input, Money, PageHeader, Pagination, Select, StatusBadge, Textarea } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

export default function RefundsPage() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const [status, setStatus] = useState('requested,pending')
  const [page, setPage] = useState(1)
  const { data, loading, refresh } = useQuery(`refunds:${status}:${page}`, () => http.get<Paginated<Refund>>('/admin/refunds', { status, page }))

  const [target, setTarget] = useState<{ refund: Refund, mode: 'complete' | 'reject' } | null>(null)
  const [form, setForm] = useState({ supplier_refund_amount: '', penalty_amount: '', confirmation_reference: '', reason: '' })
  const [error, setError] = useState<string | null>(null)

  async function submit() {
    if (!target) return
    setError(null)
    try {
      if (target.mode === 'complete') {
        await http.post(`/admin/refunds/${target.refund.id}/complete`, {
          supplier_refund_amount: form.supplier_refund_amount,
          penalty_amount: form.penalty_amount || null,
          confirmation_reference: form.confirmation_reference,
        })
      }
      else {
        await http.post(`/admin/refunds/${target.refund.id}/reject`, { reason: form.reason })
      }
      setTarget(null)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  return (
    <RequirePermission permission="platform.bookings.view">
      <PageHeader title={t('refunds.title')} />
      <div className="mb-4 max-w-xs">
        <Select value={status} onChange={e => setStatus(e.target.value)} options={[
          { value: 'requested,pending', label: `${t('status.requested')} / ${t('status.pending')}` },
          { value: 'completed', label: t('status.completed') },
          { value: 'rejected', label: t('status.rejected') },
          { value: '', label: t('common.all') },
        ]} />
      </div>
      <Card padded={false}>
        <DataTable
          rows={data?.data}
          loading={loading}
          rowKey={r => r.id}
          columns={[
            { key: 'booking', header: t('bookings.reference'), cell: r => <Link href={`/bookings/${r.booking_id}`} className="text-brand-700">#{r.booking_id}</Link> },
            { key: 'status', header: t('common.status'), cell: r => <StatusBadge status={r.status} /> },
            { key: 'reason', header: t('common.reason'), cell: r => r.reason },
            { key: 'supplier', header: t('refunds.supplierAmount'), cell: r => <Money amount={r.supplier_refund_amount} currency={r.currency} /> },
            { key: 'agency', header: t('refunds.agencyAmount'), cell: r => <Money amount={r.agency_refund_amount} currency={r.account_currency} /> },
            { key: 'date', header: t('common.date'), cell: r => formatDate(r.created_at, locale, true) },
            { key: 'actions', header: '', cell: r => can('platform.bookings.manage') && ['requested', 'pending'].includes(r.status) && (
              <div className="flex gap-1">
                <Button size="sm" variant="success" onClick={() => { setTarget({ refund: r, mode: 'complete' }); setError(null) }}>{t('refunds.complete')}</Button>
                <Button size="sm" variant="ghost" onClick={() => { setTarget({ refund: r, mode: 'reject' }); setError(null) }}>{t('common.reject')}</Button>
              </div>
            ) },
          ]}
        />
      </Card>
      <Pagination page={page} last={data?.meta.last_page ?? 1} onChange={setPage} />

      <Dialog open={target !== null} title={target?.mode === 'complete' ? t('refunds.complete') : t('common.reject')} onClose={() => setTarget(null)} footer={<Button onClick={submit}>{t('common.confirm')}</Button>}>
        <div className="space-y-3">
          {error && <Alert tone="error">{error}</Alert>}
          {target?.mode === 'complete'
            ? (
                <>
                  <Alert tone="warning">{t('refunds.manualNote')}</Alert>
                  <Field label={`${t('refunds.supplierAmount')} (${target.refund.currency})`} required>{id => <Input id={id} dir="ltr" value={form.supplier_refund_amount} onChange={e => setForm(f => ({ ...f, supplier_refund_amount: e.target.value }))} />}</Field>
                  <Field label={t('refunds.penalty')}>{id => <Input id={id} dir="ltr" value={form.penalty_amount} onChange={e => setForm(f => ({ ...f, penalty_amount: e.target.value }))} />}</Field>
                  <Field label={t('refunds.confirmation')} required>{id => <Input id={id} dir="ltr" value={form.confirmation_reference} onChange={e => setForm(f => ({ ...f, confirmation_reference: e.target.value }))} />}</Field>
                </>
              )
            : <Field label={t('common.reason')} required>{id => <Textarea id={id} rows={3} value={form.reason} onChange={e => setForm(f => ({ ...f, reason: e.target.value }))} />}</Field>}
        </div>
      </Dialog>
    </RequirePermission>
  )
}
