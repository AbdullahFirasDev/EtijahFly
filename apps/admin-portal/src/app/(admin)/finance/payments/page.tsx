'use client'

import { formatDate } from '@etijahfly/api-contracts'
import type { Paginated, Payment } from '@etijahfly/api-contracts'
import { useSearchParams } from 'next/navigation'
import { Suspense, useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Button, Card, DataTable, Dialog, Field, Money, PageHeader, Pagination, Select, StatusBadge, Textarea } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

function PaymentsList() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const params = useSearchParams()
  const [status, setStatus] = useState(params.get('status') ?? 'pending')
  const [page, setPage] = useState(1)
  const { data, loading, refresh } = useQuery(`payments:${status}:${page}`, () => http.get<Paginated<Payment>>('/admin/finance/payments', { status, page }))

  const [review, setReview] = useState<{ payment: Payment, action: 'approve' | 'reject' } | null>(null)
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function openProof(p: Payment) {
    const { url } = await http.get<{ url: string | null }>(`/admin/finance/payments/${p.id}/proof`)
    if (url) window.open(url, '_blank', 'noopener')
  }

  async function submit() {
    if (!review) return
    setBusy(true)
    setError(null)
    try {
      await http.post(`/admin/finance/payments/${review.payment.id}/${review.action}`, { notes: notes || null })
      setReview(null)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
    finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader title={t('payments.title')} />
      <div className="mb-4 max-w-xs">
        <Select value={status} onChange={e => { setStatus(e.target.value); setPage(1) }} placeholder={t('common.all')} options={['pending', 'approved', 'rejected'].map(s => ({ value: s, label: t(`status.${s}`) }))} />
      </div>
      <Card padded={false}>
        <DataTable
          testId="payments-table"
          rows={data?.data}
          loading={loading}
          rowKey={p => p.id}
          columns={[
            { key: 'ref', header: t('payments.reference'), cell: p => <span className="ltr font-mono text-xs">{p.reference}</span> },
            { key: 'tenant', header: t('common.tenant'), cell: p => p.tenant?.name },
            { key: 'amount', header: t('common.amount'), cell: p => <Money amount={p.amount} currency={p.currency} className="font-medium" /> },
            { key: 'method', header: t('payments.method'), cell: p => `${t(`payments.methods.${p.method}`)}${p.external_reference ? ` · ${p.external_reference}` : ''}` },
            { key: 'paid', header: t('payments.paidAt'), cell: p => formatDate(p.paid_at, locale) },
            { key: 'by', header: t('payments.submittedBy'), cell: p => p.submitted_by?.name },
            { key: 'status', header: t('common.status'), cell: p => <StatusBadge status={p.status} /> },
            { key: 'actions', header: '', cell: p => (
              <div className="flex gap-1">
                {p.has_proof && can('platform.payments.review') && <Button size="sm" variant="ghost" onClick={() => openProof(p)}>{t('payments.proof')}</Button>}
                {p.status === 'pending' && can('platform.payments.review') && (
                  <>
                    <Button size="sm" variant="success" onClick={() => { setReview({ payment: p, action: 'approve' }); setNotes(''); setError(null) }} data-testid="approve-payment">{t('common.approve')}</Button>
                    <Button size="sm" variant="ghost" onClick={() => { setReview({ payment: p, action: 'reject' }); setNotes(''); setError(null) }}>{t('common.reject')}</Button>
                  </>
                )}
              </div>
            ) },
          ]}
        />
      </Card>
      <Pagination page={page} last={data?.meta.last_page ?? 1} onChange={setPage} />

      <Dialog
        open={review !== null}
        title={review?.action === 'approve' ? t('common.approve') : t('common.reject')}
        onClose={() => setReview(null)}
        footer={<Button variant={review?.action === 'approve' ? 'success' : 'danger'} onClick={submit} loading={busy} disabled={review?.action === 'reject' && !notes.trim()} data-testid="confirm-review">{t('common.confirm')}</Button>}
      >
        {review && (
          <div className="space-y-3">
            {error && <Alert tone="error">{error}</Alert>}
            <p className="text-sm">{review.payment.tenant?.name} · <Money amount={review.payment.amount} currency={review.payment.currency} className="font-semibold" /></p>
            <Field label={t('payments.reviewNotes')} required={review.action === 'reject'}>{id => <Textarea id={id} rows={3} value={notes} onChange={e => setNotes(e.target.value)} />}</Field>
          </div>
        )}
      </Dialog>
    </>
  )
}

export default function PaymentsPage() {
  return (
    <RequirePermission permission="platform.finance.view">
      <Suspense><PaymentsList /></Suspense>
    </RequirePermission>
  )
}
