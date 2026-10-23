'use client'

import { formatDate } from '@etijahfly/api-contracts'
import type { LedgerAccount } from '@etijahfly/api-contracts'
import { useSearchParams } from 'next/navigation'
import { Suspense, useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Badge, Button, Card, DataTable, Input, Money, PageHeader } from '@/components/ui'
import { http } from '@/lib/api'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

type AccountRow = LedgerAccount & { name: string, tenant: { id: number, name: string } | null, normal_side: string }
interface Entry { id: number, date: string, type: string, description: string, debit: string | null, credit: string | null, balance: string, transaction_id: number }
interface Statement { account: AccountRow, opening_balance: string, closing_balance: string, total_debits: string, total_credits: string, entries: Entry[] }
interface Receivable { account_id: number, tenant: string, currency: string, balance: string, credit_limit: string, available: string, outstanding: string, status: string }

function Accounts() {
  const { t, locale } = useI18n()
  const params = useSearchParams()
  const [selected, setSelected] = useState<number | null>(params.get('account') ? Number(params.get('account')) : null)
  const today = new Date().toISOString().slice(0, 10)
  const [range, setRange] = useState({ from: today.slice(0, 8) + '01', to: today })
  const [applied, setApplied] = useState(range)

  const { data: accounts, loading } = useQuery('accounts', () => http.get<{ data: AccountRow[] }>('/admin/finance/accounts'))
  const { data: receivables } = useQuery('receivables', () => http.get<{ data: Receivable[] }>('/admin/reports/receivables'))
  const { data: statement, loading: stLoading } = useQuery(selected ? `statement:${selected}:${applied.from}:${applied.to}` : null, () => http.get<Statement>(`/admin/finance/accounts/${selected}/statement`, applied))

  const platform = (accounts?.data ?? []).filter(a => a.tenant === null)
  const agency = (accounts?.data ?? []).filter(a => a.tenant !== null)

  const columns = [
    { key: 'name', header: t('accounts.code'), cell: (a: AccountRow) => <button type="button" className="text-start font-medium text-brand-700" onClick={() => setSelected(a.id)}>{a.tenant?.name ?? a.name}<span className="ltr ms-2 text-xs text-slate-400">{a.code}</span></button> },
    { key: 'currency', header: t('common.currency'), cell: (a: AccountRow) => a.currency },
    { key: 'side', header: t('accounts.normalSide'), cell: (a: AccountRow) => <Badge>{a.normal_side}</Badge> },
    { key: 'balance', header: t('accounts.balance'), cell: (a: AccountRow) => <Money amount={a.balance} currency={a.currency} className="font-medium" /> },
  ]

  return (
    <>
      <PageHeader title={t('accounts.title')} />
      <div className="grid gap-6 xl:grid-cols-2">
        <Card title={t('accounts.platform')} padded={false}><DataTable rows={platform} loading={loading} rowKey={a => a.id} columns={columns} /></Card>
        <Card title={t('accounts.receivables')} padded={false}>
          <DataTable
            rows={receivables?.data}
            rowKey={r => r.account_id}
            columns={[
              { key: 'tenant', header: t('common.tenant'), cell: r => <button type="button" className="text-brand-700" onClick={() => setSelected(r.account_id)}>{r.tenant}</button> },
              { key: 'balance', header: t('accounts.balance'), cell: r => <Money amount={r.balance} currency={r.currency} /> },
              { key: 'available', header: t('tenants.available'), cell: r => <Money amount={r.available} currency={r.currency} /> },
              { key: 'outstanding', header: t('accounts.outstanding'), cell: r => <Money amount={r.outstanding} currency={r.currency} className={Number(r.outstanding) > 0 ? 'font-semibold text-red-700' : ''} /> },
            ]}
          />
        </Card>
      </div>

      {agency.length > 0 && !selected && <p className="mt-6 text-sm text-slate-500">{t('accounts.statement')}: {t('common.view')} ↑</p>}

      {selected && (
        <Card
          className="mt-6"
          title={`${t('accounts.statement')} · ${statement?.account.tenant?.name ?? statement?.account.name ?? ''} ${statement?.account.currency ?? ''}`}
          actions={
            <>
              <Input type="date" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} className="w-40" />
              <Input type="date" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} className="w-40" />
              <Button variant="secondary" onClick={() => setApplied(range)}>{t('common.apply')}</Button>
            </>
          }
          padded={false}
        >
          {statement && (
            <div className="grid gap-2 border-b border-slate-100 p-4 text-sm sm:grid-cols-4">
              <span>{t('accounts.opening')}: <Money amount={statement.opening_balance} currency={statement.account.currency} /></span>
              <span>{t('accounts.debit')}: <Money amount={statement.total_debits} currency={statement.account.currency} /></span>
              <span>{t('accounts.credit')}: <Money amount={statement.total_credits} currency={statement.account.currency} /></span>
              <span className="font-semibold">{t('accounts.closing')}: <Money amount={statement.closing_balance} currency={statement.account.currency} /></span>
            </div>
          )}
          <DataTable
            rows={statement?.entries}
            loading={stLoading}
            rowKey={e => e.id}
            columns={[
              { key: 'date', header: t('common.date'), cell: e => formatDate(e.date, locale, true) },
              { key: 'type', header: t('accounts.code'), cell: e => <Badge>{e.type}</Badge> },
              { key: 'desc', header: t('common.notes'), cell: e => <span className="ltr text-xs">{e.description} <span className="text-slate-400">#{e.transaction_id}</span></span> },
              { key: 'debit', header: t('accounts.debit'), cell: e => e.debit && <Money amount={e.debit} currency={statement!.account.currency} /> },
              { key: 'credit', header: t('accounts.credit'), cell: e => e.credit && <Money amount={e.credit} currency={statement!.account.currency} /> },
              { key: 'balance', header: t('accounts.balance'), cell: e => <Money amount={e.balance} currency={statement!.account.currency} className="font-medium" /> },
            ]}
          />
        </Card>
      )}
    </>
  )
}

export default function AccountsPage() {
  return (
    <RequirePermission permission="platform.finance.view">
      <Suspense><Accounts /></Suspense>
    </RequirePermission>
  )
}
