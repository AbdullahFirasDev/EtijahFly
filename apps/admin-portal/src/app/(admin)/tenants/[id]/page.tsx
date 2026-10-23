'use client'

import { formatDate } from '@etijahfly/api-contracts'
import type { Invitation, LedgerAccount, Tenant, User } from '@etijahfly/api-contracts'
import Link from 'next/link'
import { useParams } from 'next/navigation'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Badge, Button, Card, DataTable, Dialog, Field, Input, Money, PageHeader, StatusBadge, Textarea } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

interface Detail { tenant: { data: Tenant } | Tenant, users: { data: User[] } | User[], invitations: { data: Invitation[] } | Invitation[] }
const unwrap = <T,>(v: { data: T } | T): T => (v && typeof v === 'object' && 'data' in (v as object) ? (v as { data: T }).data : (v as T))

export default function TenantDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const { data, refresh } = useQuery(`tenant:${id}`, () => http.get<Detail>(`/admin/tenants/${id}`))
  const tenant = data ? unwrap(data.tenant) : undefined
  const users = data ? unwrap(data.users) : []
  const invitations = data ? unwrap(data.invitations) : []

  const [dialog, setDialog] = useState<null | { kind: 'credit', account: LedgerAccount } | { kind: 'suspend' } | { kind: 'freeze', account: LedgerAccount }>(null)
  const [value, setValue] = useState('')
  const [threshold, setThreshold] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  function openDialog(d: NonNullable<typeof dialog>) {
    setDialog(d)
    setError(null)
    setReason('')
    setValue(d.kind === 'credit' ? d.account.credit_limit : '')
    setThreshold(d.kind === 'credit' ? d.account.low_balance_threshold ?? '' : '')
  }

  async function submit() {
    if (!dialog) return
    setBusy(true)
    setError(null)
    try {
      if (dialog.kind === 'credit') await http.put(`/admin/tenants/${id}/credit-limit`, { currency: dialog.account.currency, credit_limit: value, low_balance_threshold: threshold || null, reason })
      if (dialog.kind === 'freeze') await http.put(`/admin/tenants/${id}/account-status`, { currency: dialog.account.currency, status: dialog.account.status === 'frozen' ? 'active' : 'frozen', reason })
      if (dialog.kind === 'suspend') await http.post(`/admin/tenants/${id}/suspend`, { reason })
      setDialog(null)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
    finally {
      setBusy(false)
    }
  }

  async function activate() {
    await http.post(`/admin/tenants/${id}/activate`)
    await refresh()
  }

  async function toggleUser(user: User) {
    await http.put(`/admin/users/${user.id}/status`, { status: user.status === 'active' ? 'disabled' : 'active' })
    await refresh()
  }

  if (!tenant) return null

  return (
    <RequirePermission permission="platform.tenants.view">
      <PageHeader
        title={tenant.name}
        description={`${tenant.slug} · ${tenant.contact_email}`}
        actions={
          <>
            <StatusBadge status={tenant.status} />
            <Link href={`/bookings?tenant_id=${tenant.id}`} className="text-sm text-brand-700">{t('nav.bookings')}</Link>
            {can('platform.tenants.manage') && (tenant.status === 'active'
              ? <Button variant="danger" onClick={() => openDialog({ kind: 'suspend' })}>{t('tenants.suspend')}</Button>
              : <Button variant="success" onClick={activate}>{t('tenants.activate')}</Button>)}
          </>
        }
      />
      {tenant.suspension_reason && <div className="mb-4"><Alert tone="error" title={t('tenants.suspendReason')}>{tenant.suspension_reason}</Alert></div>}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card title={t('tenants.users')} padded={false}>
            <DataTable
              rows={users}
              rowKey={u => u.id}
              columns={[
                { key: 'name', header: t('common.name'), cell: u => <div><p className="font-medium">{u.name}</p><p className="ltr text-xs text-slate-500">{u.email}</p></div> },
                { key: 'roles', header: t('users.roles'), cell: u => <div className="flex flex-wrap gap-1">{u.roles?.map(r => <Badge key={r.name} tone="info">{r.label}</Badge>)}</div> },
                { key: 'status', header: t('common.status'), cell: u => <StatusBadge status={u.status} /> },
                { key: 'login', header: t('users.lastLogin'), cell: u => formatDate(u.last_login_at, locale, true) },
                { key: 'actions', header: '', cell: u => can('platform.tenants.manage') && <Button size="sm" variant="ghost" onClick={() => toggleUser(u)}>{u.status === 'active' ? t('users.disable') : t('users.enable')}</Button> },
              ]}
            />
          </Card>
          <Card title={t('tenants.invitations')} padded={false}>
            <DataTable
              rows={invitations}
              rowKey={i => i.id}
              columns={[
                { key: 'email', header: t('common.email'), cell: i => <span className="ltr">{i.email}</span> },
                { key: 'roles', header: t('users.roles'), cell: i => i.roles.join(', ') },
                { key: 'status', header: t('common.status'), cell: i => <StatusBadge status={i.status} /> },
                { key: 'expires', header: t('common.date'), cell: i => formatDate(i.expires_at, locale, true) },
              ]}
            />
          </Card>
        </div>

        <div className="space-y-6">
          {tenant.accounts?.map(account => (
            <Card key={account.id} title={`${t('tenants.account')} · ${account.currency}`} actions={<StatusBadge status={account.status} />}>
              <dl className="space-y-2 text-sm">
                <div className="flex justify-between"><dt className="text-slate-500">{t('tenants.balance')}</dt><dd><Money amount={account.balance} currency={account.currency} /></dd></div>
                <div className="flex justify-between"><dt className="text-slate-500">{t('tenants.creditLimit')}</dt><dd><Money amount={account.credit_limit} currency={account.currency} /></dd></div>
                <div className="flex justify-between font-semibold"><dt>{t('tenants.available')}</dt><dd><Money amount={account.available} currency={account.currency} /></dd></div>
                <div className="flex justify-between"><dt className="text-slate-500">{t('accounts.outstanding')}</dt><dd><Money amount={account.outstanding} currency={account.currency} /></dd></div>
              </dl>
              <div className="mt-4 flex flex-wrap gap-2">
                {can('platform.credit.manage') && <Button size="sm" variant="secondary" onClick={() => openDialog({ kind: 'credit', account })} data-testid="set-credit">{t('tenants.setCredit')}</Button>}
                {can('platform.credit.manage') && <Button size="sm" variant={account.status === 'frozen' ? 'success' : 'ghost'} onClick={() => openDialog({ kind: 'freeze', account })}>{account.status === 'frozen' ? t('tenants.unfreeze') : t('tenants.freeze')}</Button>}
                <Link href={`/finance/accounts?account=${account.id}`} className="self-center text-xs text-brand-700">{t('accounts.statement')}</Link>
              </div>
            </Card>
          ))}
          <Card title={t('common.view')}>
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between"><dt className="text-slate-500">{t('tenants.legalName')}</dt><dd>{tenant.legal_name ?? '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">{t('tenants.domain')}</dt><dd className="ltr">{tenant.custom_domain ?? '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">{t('common.phone')}</dt><dd className="ltr">{tenant.contact_phone ?? '—'}</dd></div>
            </dl>
          </Card>
        </div>
      </div>

      <Dialog
        open={dialog !== null}
        title={dialog?.kind === 'credit' ? t('tenants.setCredit') : dialog?.kind === 'suspend' ? t('tenants.suspend') : t('tenants.freeze')}
        onClose={() => setDialog(null)}
        footer={<><Button variant="ghost" onClick={() => setDialog(null)}>{t('common.cancel')}</Button><Button onClick={submit} loading={busy} disabled={!reason.trim()} data-testid="dialog-confirm">{t('common.confirm')}</Button></>}
      >
        <div className="space-y-3">
          {error && <Alert tone="error">{error}</Alert>}
          {dialog?.kind === 'credit' && (
            <>
              <Field label={`${t('tenants.creditLimit')} (${dialog.account.currency})`} required>{fid => <Input id={fid} dir="ltr" inputMode="decimal" value={value} onChange={e => setValue(e.target.value)} data-testid="credit-value" />}</Field>
              <Field label={t('tenants.lowBalance')}>{fid => <Input id={fid} dir="ltr" inputMode="decimal" value={threshold} onChange={e => setThreshold(e.target.value)} />}</Field>
            </>
          )}
          <Field label={t('common.reason')} required>{fid => <Textarea id={fid} rows={3} value={reason} onChange={e => setReason(e.target.value)} data-testid="dialog-reason" />}</Field>
        </div>
      </Dialog>
    </RequirePermission>
  )
}
