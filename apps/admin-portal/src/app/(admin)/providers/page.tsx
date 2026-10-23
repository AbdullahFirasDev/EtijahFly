'use client'

import { formatDate } from '@etijahfly/api-contracts'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Badge, Button, Card, DataTable, Field, Input, PageHeader, Select, StatusBadge } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

interface Quota { limit: number | null, remaining: number | null, reset_at: string | null, budget: number, used_this_month: number }
interface Provider {
  id: number
  code: string
  name: string
  kind: 'booking' | 'airline_data'
  is_enabled: boolean
  is_sandbox: boolean
  priority: number
  settings: { scenario?: string } | null
  environment_allows: boolean
  credentials_configured: boolean
  capabilities: Record<string, boolean> | null
  scenarios: string[] | null
  health: { status: string, message: string | null, checked_at: string | null }
  quota: Quota | null
}
interface Airline { id: number, iata_code: string | null, icao_code: string | null, name: string, country: string | null, home_airport: string | null, source: string, synced_at: string | null, fleet: Record<string, number> | null }

export default function ProvidersPage() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const { data, refresh } = useQuery('providers', () => http.get<{ data: Provider[] }>('/admin/providers'))
  const [q, setQ] = useState('')
  const { data: airlines, refresh: refreshAirlines } = useQuery(`airlines:${q}`, () => http.get<{ data: { data: Airline[] }, quota: Quota }>('/admin/airlines', { q }))
  const [importForm, setImportForm] = useState({ by: 'iata', value: '' })
  const [message, setMessage] = useState<{ tone: 'success' | 'error', text: string } | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  async function update(p: Provider, patch: Record<string, unknown>) {
    setMessage(null)
    try {
      await http.patch(`/admin/providers/${p.code}`, patch)
      await refresh()
    }
    catch (e) {
      setMessage({ tone: 'error', text: e instanceof ApiError ? `${e.code}: ${e.message}` : t('common.error') })
    }
  }

  async function check(p: Provider) {
    setBusy(p.code)
    try {
      await http.post(`/admin/providers/${p.code}/health-check`)
      await refresh()
    }
    finally {
      setBusy(null)
    }
  }

  async function importAirlines() {
    setMessage(null)
    setBusy('import')
    try {
      const res = await http.post<{ data: Airline[] }>('/admin/airlines/import', importForm)
      setMessage({ tone: 'success', text: res.data.map(a => a.name).join(', ') || t('common.empty') })
      await refreshAirlines()
      await refresh()
    }
    catch (e) {
      setMessage({ tone: 'error', text: e instanceof ApiError ? `${e.code}: ${e.message}` : t('common.error') })
    }
    finally {
      setBusy(null)
    }
  }

  const hasRealBooking = data?.data.some(p => p.kind === 'booking' && !p.is_sandbox && p.is_enabled)

  return (
    <RequirePermission permission="platform.providers.view">
      <PageHeader title={t('providers.title')} description={t('providers.secretsNote')} />
      {data && !hasRealBooking && <div className="mb-4"><Alert tone="warning">{t('providers.noBookingProvider')}</Alert></div>}
      {message && <div className="mb-4"><Alert tone={message.tone}>{message.text}</Alert></div>}

      <div className="grid gap-6 lg:grid-cols-2">
        {data?.data.map(p => (
          <Card key={p.code} title={p.name} actions={<><Badge tone="info">{t(`providers.${p.kind}`)}</Badge>{p.is_sandbox && <Badge tone="warning">{t('providers.sandbox')}</Badge>}</>}>
            <dl className="space-y-2 text-sm">
              <div className="flex items-center justify-between">
                <dt className="text-slate-500">{t('providers.enabled')}</dt>
                <dd className="flex items-center gap-2">
                  <Badge tone={p.is_enabled ? 'success' : 'neutral'}>{p.is_enabled ? t('common.yes') : t('common.no')}</Badge>
                  {!p.environment_allows && <Badge tone="error">{t('providers.envDisabled')}</Badge>}
                  {can('platform.providers.manage') && <Button size="sm" variant="ghost" onClick={() => update(p, { is_enabled: !p.is_enabled })}>{p.is_enabled ? t('users.disable') : t('users.enable')}</Button>}
                </dd>
              </div>
              <div className="flex justify-between"><dt className="text-slate-500">{t('providers.credentials')}</dt><dd><Badge tone={p.credentials_configured ? 'success' : 'error'}>{p.credentials_configured ? t('providers.configured') : t('providers.missing')}</Badge></dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">{t('providers.health')}</dt><dd className="flex items-center gap-2"><StatusBadge status={p.health.status} /><span className="text-xs text-slate-500">{formatDate(p.health.checked_at, locale, true)}</span></dd></div>
              {p.health.message && p.health.status !== 'healthy' && <p className="ltr text-xs text-red-600">{p.health.message}</p>}
              {p.capabilities && (
                <div>
                  <dt className="mb-1 text-slate-500">{t('providers.capabilities')}</dt>
                  <dd className="flex flex-wrap gap-1">{Object.entries(p.capabilities).map(([cap, on]) => <Badge key={cap} tone={on ? 'success' : 'neutral'}>{t(`providers.caps.${cap}`)}</Badge>)}</dd>
                </div>
              )}
              {p.scenarios && can('platform.providers.manage') && (
                <div className="flex items-center justify-between gap-3">
                  <dt className="text-slate-500">{t('providers.scenario')}</dt>
                  <dd><Select value={p.settings?.scenario ?? 'normal'} onChange={e => update(p, { settings: { ...(p.settings ?? {}), scenario: e.target.value } })} options={p.scenarios.map(s => ({ value: s, label: s }))} className="ltr w-48" data-testid="mock-scenario" /></dd>
                </div>
              )}
              {p.quota && (
                <div className="rounded-lg bg-slate-50 p-3">
                  <p className="mb-1 font-medium">{t('providers.quota')}</p>
                  <p className="text-xs">{t('providers.used')}: {p.quota.used_this_month} / {t('providers.budget')} {p.quota.budget} · {t('providers.remaining')}: {p.quota.remaining ?? '—'}{p.quota.limit ? ` / ${p.quota.limit}` : ''}</p>
                </div>
              )}
            </dl>
            {can('platform.providers.manage') && <Button className="mt-4" variant="secondary" size="sm" loading={busy === p.code} onClick={() => check(p)}>{t('providers.check')}</Button>}
          </Card>
        ))}
      </div>

      <Card className="mt-6" title={t('providers.airlines')} padded={false}
        actions={can('platform.providers.manage') && (
          <div className="flex items-end gap-2">
            <Select value={importForm.by} onChange={e => setImportForm(f => ({ ...f, by: e.target.value }))} options={[{ value: 'iata', label: 'IATA' }, { value: 'icao', label: 'ICAO' }, { value: 'name', label: t('common.name') }]} className="w-28" />
            <Input dir="ltr" value={importForm.value} onChange={e => setImportForm(f => ({ ...f, value: e.target.value }))} className="w-32" placeholder="IA" />
            <Button size="sm" onClick={importAirlines} loading={busy === 'import'} disabled={importForm.value.length < 2}>{t('providers.import')}</Button>
          </div>
        )}
      >
        <div className="p-4"><Field label={t('common.search')}>{id => <Input id={id} value={q} onChange={e => setQ(e.target.value)} className="max-w-xs" />}</Field></div>
        <DataTable
          rows={airlines?.data.data}
          rowKey={a => a.id}
          columns={[
            { key: 'codes', header: 'IATA / ICAO', cell: a => <span className="ltr font-mono">{a.iata_code ?? '—'} / {a.icao_code ?? '—'}</span> },
            { key: 'name', header: t('common.name'), cell: a => a.name },
            { key: 'country', header: '', cell: a => a.country },
            { key: 'hub', header: '', cell: a => <span className="text-xs">{a.home_airport}</span> },
            { key: 'fleet', header: '', cell: a => a.fleet?.total_count ? <span className="text-xs">✈ {a.fleet.total_count}</span> : '' },
            { key: 'source', header: '', cell: a => <Badge tone={a.source === 'airlines_api' ? 'success' : 'neutral'}>{a.source}</Badge> },
            { key: 'synced', header: '', cell: a => <span className="text-xs text-slate-500">{formatDate(a.synced_at, locale)}</span> },
          ]}
        />
      </Card>
    </RequirePermission>
  )
}
