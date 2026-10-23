'use client'

import { CURRENCIES } from '@etijahfly/api-contracts'
import type { Paginated, Tenant } from '@etijahfly/api-contracts'
import { Plus } from 'lucide-react'
import Link from 'next/link'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Button, Card, DataTable, Dialog, Field, Input, Money, PageHeader, Pagination, Select, StatusBadge } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

const EMPTY = { name: '', legal_name: '', default_currency: 'IQD', contact_email: '', contact_phone: '', owner_email: '', owner_name: '', credit_limit: '', custom_domain: '' }

export default function TenantsPage() {
  const { t } = useI18n()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const { data, loading, refresh } = useQuery(`tenants:${q}:${page}`, () => http.get<Paginated<Tenant>>('/admin/tenants', { q, page }))

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm(f => ({ ...f, [key]: e.target.value }))

  async function create(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setError(null)
    try {
      const body = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      await http.post('/admin/tenants', body)
      setOpen(false)
      setForm(EMPTY)
      setNotice(t('tenants.created'))
      await refresh()
    }
    catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fieldErrors)
        setError(err.message)
      }
    }
    finally {
      setSaving(false)
    }
  }

  return (
    <RequirePermission permission="platform.tenants.view">
      <PageHeader
        title={t('tenants.title')}
        actions={can('platform.tenants.manage') && <Button onClick={() => setOpen(true)} data-testid="new-tenant"><Plus className="size-4" />{t('tenants.new')}</Button>}
      />
      {notice && <div className="mb-4"><Alert tone="success">{notice}</Alert></div>}
      <div className="mb-4 max-w-xs"><Input placeholder={t('common.search')} value={q} onChange={e => { setQ(e.target.value); setPage(1) }} /></div>
      <Card padded={false}>
        <DataTable
          testId="tenants-table"
          rows={data?.data}
          loading={loading}
          rowKey={r => r.id}
          columns={[
            { key: 'name', header: t('common.name'), cell: r => <Link href={`/tenants/${r.id}`} className="font-medium text-brand-700">{r.name}</Link> },
            { key: 'slug', header: t('tenants.slug'), cell: r => <span className="ltr text-slate-500">{r.slug}</span> },
            { key: 'status', header: t('common.status'), cell: r => <StatusBadge status={r.status} /> },
            { key: 'balance', header: t('tenants.balance'), cell: r => r.accounts?.[0] ? <Money amount={r.accounts[0].balance} currency={r.accounts[0].currency} /> : '—' },
            { key: 'credit', header: t('tenants.creditLimit'), cell: r => r.accounts?.[0] ? <Money amount={r.accounts[0].credit_limit} currency={r.accounts[0].currency} /> : '—' },
            { key: 'users', header: t('tenants.users'), cell: r => r.users_count ?? 0 },
          ]}
        />
      </Card>
      <Pagination page={page} last={data?.meta.last_page ?? 1} onChange={setPage} />

      <Dialog open={open} title={t('tenants.new')} onClose={() => setOpen(false)}>
        <form onSubmit={create} className="space-y-3" id="tenant-form">
          {error && <Alert tone="error">{error}</Alert>}
          <Field label={t('common.name')} required error={errors.name}>{id => <Input id={id} value={form.name} onChange={set('name')} required data-testid="tenant-name" />}</Field>
          <Field label={t('tenants.legalName')}>{id => <Input id={id} value={form.legal_name} onChange={set('legal_name')} />}</Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('tenants.currency')} required>{id => <Select id={id} value={form.default_currency} onChange={set('default_currency')} options={CURRENCIES.map(c => ({ value: c, label: c }))} />}</Field>
            <Field label={t('tenants.creditLimit')} error={errors.credit_limit}>{id => <Input id={id} dir="ltr" inputMode="decimal" value={form.credit_limit} onChange={set('credit_limit')} />}</Field>
          </div>
          <Field label={t('tenants.contact')} required error={errors.contact_email}>{id => <Input id={id} type="email" dir="ltr" value={form.contact_email} onChange={set('contact_email')} required data-testid="tenant-contact" />}</Field>
          <Field label={t('common.phone')}>{id => <Input id={id} dir="ltr" value={form.contact_phone} onChange={set('contact_phone')} />}</Field>
          <Field label={t('tenants.domain')} error={errors.custom_domain} hint="agency.example.com">{id => <Input id={id} dir="ltr" value={form.custom_domain} onChange={set('custom_domain')} />}</Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('tenants.owner')} required error={errors.owner_email}>{id => <Input id={id} type="email" dir="ltr" value={form.owner_email} onChange={set('owner_email')} required data-testid="tenant-owner" />}</Field>
            <Field label={t('tenants.ownerName')}>{id => <Input id={id} value={form.owner_name} onChange={set('owner_name')} />}</Field>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
            <Button type="submit" loading={saving} data-testid="tenant-submit">{t('common.create')}</Button>
          </div>
        </form>
      </Dialog>
    </RequirePermission>
  )
}
