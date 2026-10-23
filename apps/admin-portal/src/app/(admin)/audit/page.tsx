'use client'

import { formatDate } from '@etijahfly/api-contracts'
import type { AuditLog, Paginated } from '@etijahfly/api-contracts'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Badge, Card, DataTable, Input, PageHeader, Pagination } from '@/components/ui'
import { http } from '@/lib/api'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

function Changes({ log }: { log: AuditLog }) {
  const payload = { ...(log.old_values ? { old: log.old_values } : {}), ...(log.new_values ? { new: log.new_values } : {}), ...(log.metadata ? { meta: log.metadata } : {}) }
  if (!Object.keys(payload).length) return <span className="text-slate-400">—</span>
  return (
    <details>
      <summary className="cursor-pointer text-xs text-brand-700">JSON</summary>
      <pre className="ltr mt-1 max-w-md overflow-x-auto rounded bg-slate-50 p-2 text-[11px]">{JSON.stringify(payload, null, 2)}</pre>
    </details>
  )
}

export default function AuditPage() {
  const { t, locale } = useI18n()
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const { data, loading } = useQuery(`audit:${action}:${page}`, () => http.get<Paginated<AuditLog>>('/admin/audit-logs', { action, page }))

  return (
    <RequirePermission permission="platform.audit.view">
      <PageHeader title={t('audit.title')} />
      <div className="mb-4 max-w-xs"><Input dir="ltr" placeholder={`${t('audit.filter')} (booking., finance., auth.)`} value={action} onChange={e => { setAction(e.target.value); setPage(1) }} /></div>
      <Card padded={false}>
        <DataTable
          testId="audit-table"
          rows={data?.data}
          loading={loading}
          rowKey={l => l.id}
          columns={[
            { key: 'date', header: t('common.date'), cell: l => <span className="text-xs">{formatDate(l.created_at, locale, true)}</span> },
            { key: 'action', header: t('audit.action'), cell: l => <Badge tone={l.action.includes('failed') || l.action.includes('blocked') ? 'error' : 'neutral'}><span className="ltr">{l.action}</span></Badge> },
            { key: 'actor', header: t('audit.actor'), cell: l => <span className="text-xs">{l.actor?.name ?? l.actor_type}</span> },
            { key: 'subject', header: t('audit.subject'), cell: l => <span className="ltr text-xs">{l.subject_type ? `${l.subject_type}#${l.subject_id}` : '—'}{l.tenant_id ? ` · tenant ${l.tenant_id}` : ''}</span> },
            { key: 'changes', header: t('audit.changes'), cell: l => <Changes log={l} /> },
            { key: 'ip', header: t('audit.ip'), cell: l => <span className="ltr text-xs text-slate-500">{l.ip_address}</span> },
          ]}
        />
      </Card>
      <Pagination page={page} last={data?.meta.last_page ?? 1} onChange={setPage} />
    </RequirePermission>
  )
}
