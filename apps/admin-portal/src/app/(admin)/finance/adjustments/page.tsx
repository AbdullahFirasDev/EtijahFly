'use client'

import { CURRENCIES, formatDate } from '@etijahfly/api-contracts'
import type { Adjustment, Paginated, Tenant } from '@etijahfly/api-contracts'
import { Plus } from 'lucide-react'
import { useSearchParams } from 'next/navigation'
import { Suspense, useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Badge, Button, Card, DataTable, Dialog, Field, Input, Money, PageHeader, Pagination, Select, StatusBadge, Textarea } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

function AdjustmentsList() {
  const { t, locale } = useI18n()
  const { me, can } = useAuth()
  const params = useSearchParams()
  const [status, setStatus] = useState(params.get('status') ?? '')
  const [page, setPage] = useState(1)
  const { data, loading, refresh } = useQuery(`adjustments:${status}:${page}`, () => http.get<Paginated<Adjustment>>('/admin/finance/adjustments', { status, page }))
  const { data: tenants } = useQuery('tenants:all', () => http.get<Paginated<Tenant>>('/admin/tenants', { page: 1 }))

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ tenant_id: '', direction: 'credit', amount: '', currency: 'IQD', reason: '' })
  const [review, setReview] = useState<{ adjustment: Adjustment, action: 'approve' | 'reject' } | null>(null)
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)

  async function create() {
    setError(null)
    try {
      await http.post('/admin/finance/adjustments', { ...form, tenant_id: Number(form.tenant_id) })
      setOpen(false)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  async function submitReview() {
    if (!review) return
    setError(null)
    try {
      await http.post(`/admin/finance/adjustments/${review.adjustment.id}/${review.action}`, { notes: notes || null })
      setReview(null)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  return (
    <>
      <PageHeader
        title={t('adjustments.title')}
        description={t('adjustments.fourEyes')}
        actions={can('platform.adjustments.request') && <Button onClick={() => { setOpen(true); setError(null) }}><Plus className="size-4" />{t('adjustments.new')}</Button>}
      />
      <div className="mb-4 max-w-xs">
        <Select value={status} onChange={e => setStatus(e.target.value)} placeholder={t('common.all')} options={['pending', 'approved', 'rejected'].map(s => ({ value: s, label: t(`status.${s}`) }))} />
      </div>
      <Card padded={false}>
        <DataTable
          rows={data?.data}
          loading={loading}
          rowKey={a => a.id}
          columns={[
            { key: 'tenant', header: t('common.tenant'), cell: a => a.tenant?.name },
            { key: 'direction', header: t('adjustments.direction'), cell: a => <Badge tone={a.direction === 'credit' ? 'success' : 'warning'}>{t(`adjustments.${a.direction}`)}</Badge> },
            { key: 'amount', header: t('common.amount'), cell: a => <Money amount={a.amount} currency={a.currency} /> },
            { key: 'reason', header: t('common.reason'), cell: a => <span className="line-clamp-2 max-w-xs">{a.reason}</span> },
            { key: 'requested', header: t('adjustments.requestedBy'), cell: a => `${a.requested_by?.name ?? ''} · ${formatDate(a.created_at, locale)}` },
            { key: 'reviewed', header: t('adjustments.reviewedBy'), cell: a => a.reviewed_by?.name ?? '—' },
            { key: 'status', header: t('common.status'), cell: a => <StatusBadge status={a.status} /> },
            { key: 'actions', header: '', cell: a => a.status === 'pending' && can('platform.adjustments.approve') && a.requested_by?.id !== me?.id && (
              <div className="flex gap-1">
                <Button size="sm" variant="success" onClick={() => { setReview({ adjustment: a, action: 'approve' }); setNotes(''); setError(null) }}>{t('common.approve')}</Button>
                <Button size="sm" variant="ghost" onClick={() => { setReview({ adjustment: a, action: 'reject' }); setNotes(''); setError(null) }}>{t('common.reject')}</Button>
              </div>
            ) },
          ]}
        />
      </Card>
      <Pagination page={page} last={data?.meta.last_page ?? 1} onChange={setPage} />

      <Dialog open={open} title={t('adjustments.new')} onClose={() => setOpen(false)} footer={<Button onClick={create} disabled={!form.tenant_id || !form.amount || form.reason.length < 10}>{t('common.create')}</Button>}>
        <div className="space-y-3">
          {error && <Alert tone="error">{error}</Alert>}
          <Field label={t('common.tenant')} required>{id => <Select id={id} value={form.tenant_id} placeholder="—" onChange={e => setForm(f => ({ ...f, tenant_id: e.target.value }))} options={(tenants?.data ?? []).map(tn => ({ value: String(tn.id), label: tn.name }))} />}</Field>
          <div className="grid grid-cols-3 gap-3">
            <Field label={t('adjustments.direction')}>{id => <Select id={id} value={form.direction} onChange={e => setForm(f => ({ ...f, direction: e.target.value }))} options={[{ value: 'credit', label: t('adjustments.credit') }, { value: 'debit', label: t('adjustments.debit') }]} />}</Field>
            <Field label={t('common.amount')} required>{id => <Input id={id} dir="ltr" value={form.amount} onChange={e => setForm(f => ({ ...f, amount: e.target.value }))} />}</Field>
            <Field label={t('common.currency')}>{id => <Select id={id} value={form.currency} onChange={e => setForm(f => ({ ...f, currency: e.target.value }))} options={CURRENCIES.map(c => ({ value: c, label: c }))} />}</Field>
          </div>
          <Field label={t('common.reason')} required hint="min. 10">{id => <Textarea id={id} rows={3} value={form.reason} onChange={e => setForm(f => ({ ...f, reason: e.target.value }))} />}</Field>
        </div>
      </Dialog>

      <Dialog open={review !== null} title={review?.action === 'approve' ? t('common.approve') : t('common.reject')} onClose={() => setReview(null)} footer={<Button variant={review?.action === 'approve' ? 'success' : 'danger'} onClick={submitReview} disabled={review?.action === 'reject' && !notes.trim()}>{t('common.confirm')}</Button>}>
        <div className="space-y-3">
          {error && <Alert tone="error">{error}</Alert>}
          {review && <p className="text-sm">{review.adjustment.tenant?.name} · {t(`adjustments.${review.adjustment.direction}`)} · <Money amount={review.adjustment.amount} currency={review.adjustment.currency} /></p>}
          <Field label={t('common.notes')}>{id => <Textarea id={id} rows={3} value={notes} onChange={e => setNotes(e.target.value)} />}</Field>
        </div>
      </Dialog>
    </>
  )
}

export default function AdjustmentsPage() {
  return (
    <RequirePermission permission="platform.finance.view">
      <Suspense><AdjustmentsList /></Suspense>
    </RequirePermission>
  )
}
