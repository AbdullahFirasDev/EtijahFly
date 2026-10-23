'use client'

import { formatDate } from '@etijahfly/api-contracts'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Badge, Card, DataTable, PageHeader, Pagination, StatusBadge } from '@/components/ui'
import { http } from '@/lib/api'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

interface Health {
  status: string
  checks: Record<string, { status: string, detail: string }>
  providers: { code: string, enabled: boolean, status: string, checked_at: string | null, errors_24h: number, calls_24h: number }[]
  app: Record<string, string>
}
interface ProviderLog { id: number, provider_code: string, operation: string, success: boolean, http_status: number | null, duration_ms: number | null, error_code: string | null, error_message: string | null, created_at: string }
interface LogPage { data: ProviderLog[], current_page: number, last_page: number }

export default function SystemPage() {
  const { t, locale } = useI18n()
  const [page, setPage] = useState(1)
  const { data } = useQuery('health', () => http.get<Health>('/admin/system/health'))
  const { data: logs, loading } = useQuery(`provider-logs:${page}`, () => http.get<LogPage>('/admin/system/provider-logs', { page }))
  const { data: failed } = useQuery('failed-jobs', () => http.get<{ data: { id: number, queue: string, failed_at: string, exception: string }[] }>('/admin/system/failed-jobs'))

  return (
    <RequirePermission permission="platform.system.view">
      <PageHeader title={t('system.title')} actions={data && <StatusBadge status={data.status === 'ok' ? 'ok' : 'warning'} />} />
      {data && (
        <div className="grid gap-6 lg:grid-cols-3">
          <Card title={t('system.checks')} className="lg:col-span-2">
            <ul className="divide-y divide-slate-100 text-sm" data-testid="health-checks">
              {Object.entries(data.checks).map(([name, check]) => (
                <li key={name} className="flex items-center justify-between gap-4 py-2">
                  <span className="font-medium capitalize">{name}</span>
                  <span className="ltr flex-1 truncate text-end text-xs text-slate-500" title={check.detail}>{check.detail}</span>
                  <StatusBadge status={check.status} />
                </li>
              ))}
            </ul>
          </Card>
          <Card title={t('system.app')}>
            <dl className="space-y-1 text-sm">{Object.entries(data.app).map(([k, v]) => <div key={k} className="flex justify-between"><dt className="text-slate-500">{k}</dt><dd className="ltr">{v}</dd></div>)}</dl>
          </Card>
          <Card title={t('system.providers')} className="lg:col-span-3" padded={false}>
            <DataTable
              rows={data.providers}
              rowKey={p => p.code}
              columns={[
                { key: 'code', header: '', cell: p => <span className="ltr font-medium">{p.code}</span> },
                { key: 'enabled', header: '', cell: p => <Badge tone={p.enabled ? 'success' : 'neutral'}>{p.enabled ? 'on' : 'off'}</Badge> },
                { key: 'status', header: t('common.status'), cell: p => <StatusBadge status={p.status} /> },
                { key: 'calls', header: t('system.calls'), cell: p => p.calls_24h },
                { key: 'errors', header: t('system.errors'), cell: p => <span className={p.errors_24h ? 'font-semibold text-red-700' : ''}>{p.errors_24h}</span> },
              ]}
            />
          </Card>
        </div>
      )}

      <Card className="mt-6" title={t('system.providerLogs')} padded={false}>
        <DataTable
          rows={logs?.data}
          loading={loading}
          rowKey={l => l.id}
          columns={[
            { key: 'date', header: t('common.date'), cell: l => <span className="text-xs">{formatDate(l.created_at, locale, true)}</span> },
            { key: 'provider', header: '', cell: l => <span className="ltr">{l.provider_code}</span> },
            { key: 'op', header: t('system.operation'), cell: l => <span className="ltr">{l.operation}</span> },
            { key: 'ok', header: t('common.status'), cell: l => <Badge tone={l.success ? 'success' : 'error'}>{l.success ? 'OK' : l.error_code ?? 'ERR'}</Badge> },
            { key: 'http', header: 'HTTP', cell: l => l.http_status ?? '' },
            { key: 'ms', header: t('system.duration'), cell: l => <span className="ltr">{l.duration_ms} ms</span> },
            { key: 'msg', header: '', cell: l => <span className="ltr line-clamp-1 max-w-sm text-xs text-slate-500" title={l.error_message ?? ''}>{l.error_message}</span> },
          ]}
        />
      </Card>
      <Pagination page={page} last={logs?.last_page ?? 1} onChange={setPage} />

      <Card className="mt-6" title={t('system.failedJobs')} padded={false}>
        <DataTable
          rows={failed?.data}
          rowKey={j => j.id}
          columns={[
            { key: 'date', header: t('common.date'), cell: j => formatDate(j.failed_at, locale, true) },
            { key: 'queue', header: '', cell: j => j.queue },
            { key: 'ex', header: '', cell: j => <span className="ltr line-clamp-2 text-xs">{j.exception}</span> },
          ]}
        />
      </Card>
    </RequirePermission>
  )
}
