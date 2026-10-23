'use client'

import { CABINS, CURRENCIES, formatDate } from '@etijahfly/api-contracts'
import type { Paginated, PricingRule, Tenant } from '@etijahfly/api-contracts'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Badge, Button, Card, DataTable, Dialog, Field, Input, Money, PageHeader, Select } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

interface Rate { id: number, base_currency: string, quote_currency: string, rate: string, effective_at: string, source: string, creator: { name: string } | null }
interface Preview { currency: string, supplier_total: string, markup: string, sale_total: string, commission: string, agency_net: string, platform_profit: string, markup_rule: { name: string } | null, commission_rule: { name: string } | null }

const EMPTY_RULE = { name: '', type: 'markup', calculation: 'percent', value: '', currency: '', basis: 'supplier_total', tenant_id: '', airline_codes: '', cabin: '', origin: '', destination: '', priority: '0', min_amount: '', max_amount: '' }

export default function PricingPage() {
  const { t, locale } = useI18n()
  const { can } = useAuth()
  const { data: rules, refresh } = useQuery('rules', () => http.get<{ data: PricingRule[] }>('/admin/pricing-rules'))
  const { data: rates, refresh: refreshRates } = useQuery('rates', () => http.get<{ data: Rate[] }>('/admin/exchange-rates'))
  const { data: tenants } = useQuery('tenants:pricing', () => http.get<Paginated<Tenant>>('/admin/tenants'))

  const [ruleForm, setRuleForm] = useState<typeof EMPTY_RULE | null>(null)
  const [rateForm, setRateForm] = useState<{ base_currency: string, quote_currency: string, rate: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [preview, setPreview] = useState({ base: '200', taxes: '61', currency: 'USD', tenant_id: '', airline: '' })
  const [previewResult, setPreviewResult] = useState<Preview | null>(null)

  const setR = (key: keyof typeof EMPTY_RULE) => (e: { target: { value: string } }) => setRuleForm(f => (f ? { ...f, [key]: e.target.value } : f))

  async function saveRule() {
    if (!ruleForm) return
    setError(null)
    const body: Record<string, unknown> = Object.fromEntries(Object.entries(ruleForm).filter(([, v]) => v !== ''))
    if (ruleForm.airline_codes) body.airline_codes = ruleForm.airline_codes.split(/[\s,]+/).filter(Boolean)
    if (ruleForm.tenant_id) body.tenant_id = Number(ruleForm.tenant_id)
    try {
      await http.post('/admin/pricing-rules', body)
      setRuleForm(null)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  async function toggleRule(rule: PricingRule) {
    await http.put(`/admin/pricing-rules/${rule.id}`, { ...rule, is_active: !rule.is_active, tenant_id: rule.tenant_id ?? null })
    await refresh()
  }

  async function deleteRule(rule: PricingRule) {
    await http.del(`/admin/pricing-rules/${rule.id}`)
    await refresh()
  }

  async function saveRate() {
    if (!rateForm) return
    setError(null)
    try {
      await http.post('/admin/exchange-rates', rateForm)
      setRateForm(null)
      await refreshRates()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  async function runPreview() {
    const body = { ...preview, tenant_id: preview.tenant_id ? Number(preview.tenant_id) : null, airline: preview.airline || null }
    setPreviewResult((await http.post<{ data: Preview }>('/admin/pricing-rules/preview', body)).data)
  }

  const tenantOptions = (tenants?.data ?? []).map(tn => ({ value: String(tn.id), label: tn.name }))

  return (
    <RequirePermission permission="platform.pricing.view">
      <PageHeader title={t('pricing.title')} actions={can('platform.pricing.manage') && <Button onClick={() => { setRuleForm(EMPTY_RULE); setError(null) }} data-testid="new-rule"><Plus className="size-4" />{t('pricing.new')}</Button>} />
      <div className="mb-6"><Alert tone="info" title={t('pricing.rules')}>{t('pricing.rulesHelp')}</Alert></div>

      <Card padded={false}>
        <DataTable
          rows={rules?.data}
          rowKey={r => r.id}
          columns={[
            { key: 'name', header: t('common.name'), cell: r => <span className="font-medium">{r.name}</span> },
            { key: 'type', header: t('pricing.type'), cell: r => <Badge tone={r.type === 'markup' ? 'info' : 'success'}>{t(`pricing.${r.type}`)}</Badge> },
            { key: 'value', header: t('pricing.value'), cell: r => <span className="ltr">{r.calculation === 'percent' ? `${Number(r.value)}% · ${t(`pricing.${r.basis}`)}` : `${Number(r.value)} ${r.currency}`}</span> },
            { key: 'scope', header: t('pricing.scope'), cell: r => r.tenant?.name ?? t('pricing.global') },
            { key: 'filters', header: '', cell: r => <span className="ltr text-xs text-slate-500">{[r.airline_codes?.join(','), r.cabin, r.origin && `${r.origin}→`, r.destination && `→${r.destination}`].filter(Boolean).join(' ')}</span> },
            { key: 'priority', header: t('pricing.priority'), cell: r => r.priority },
            { key: 'active', header: t('pricing.active'), cell: r => (can('platform.pricing.manage')
              ? <button type="button" onClick={() => toggleRule(r)}><Badge tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? t('common.yes') : t('common.no')}</Badge></button>
              : <Badge tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? t('common.yes') : t('common.no')}</Badge>) },
            { key: 'del', header: '', cell: r => can('platform.pricing.manage') && <button type="button" onClick={() => deleteRule(r)} aria-label={t('common.delete')} className="text-red-600"><Trash2 className="size-4" /></button> },
          ]}
        />
      </Card>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card title={t('pricing.preview')}>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Base">{id => <Input id={id} dir="ltr" value={preview.base} onChange={e => setPreview(p => ({ ...p, base: e.target.value }))} />}</Field>
            <Field label="Taxes">{id => <Input id={id} dir="ltr" value={preview.taxes} onChange={e => setPreview(p => ({ ...p, taxes: e.target.value }))} />}</Field>
            <Field label={t('common.currency')}>{id => <Select id={id} value={preview.currency} onChange={e => setPreview(p => ({ ...p, currency: e.target.value }))} options={CURRENCIES.map(c => ({ value: c, label: c }))} />}</Field>
            <Field label={t('common.tenant')}>{id => <Select id={id} value={preview.tenant_id} placeholder={t('pricing.global')} onChange={e => setPreview(p => ({ ...p, tenant_id: e.target.value }))} options={tenantOptions} />}</Field>
            <Field label="IATA">{id => <Input id={id} dir="ltr" maxLength={2} value={preview.airline} onChange={e => setPreview(p => ({ ...p, airline: e.target.value.toUpperCase() }))} />}</Field>
          </div>
          <Button className="mt-3" variant="secondary" onClick={runPreview}>{t('pricing.preview')}</Button>
          {previewResult && (
            <dl className="mt-4 grid grid-cols-2 gap-1 rounded-lg bg-slate-50 p-3 text-sm">
              <dt>{t('bookings.supplier')}</dt><dd className="text-end"><Money amount={previewResult.supplier_total} currency={previewResult.currency} /></dd>
              <dt>{t('bookings.markup')} <span className="text-xs text-slate-500">{previewResult.markup_rule?.name}</span></dt><dd className="text-end"><Money amount={previewResult.markup} currency={previewResult.currency} /></dd>
              <dt className="font-semibold">{t('bookings.total')}</dt><dd className="text-end font-semibold"><Money amount={previewResult.sale_total} currency={previewResult.currency} /></dd>
              <dt>{t('bookings.commission')} <span className="text-xs text-slate-500">{previewResult.commission_rule?.name}</span></dt><dd className="text-end"><Money amount={previewResult.commission} currency={previewResult.currency} /></dd>
              <dt className="text-emerald-700">{t('bookings.profit')}</dt><dd className="text-end text-emerald-700"><Money amount={previewResult.platform_profit} currency={previewResult.currency} /></dd>
            </dl>
          )}
        </Card>

        <Card title={t('pricing.fx')} actions={can('platform.exchange_rates.manage') && <Button size="sm" variant="secondary" onClick={() => { setRateForm({ base_currency: 'USD', quote_currency: 'IQD', rate: '' }); setError(null) }}>{t('pricing.newRate')}</Button>} padded={false}>
          <p className="px-5 pt-4 text-xs text-slate-500">{t('pricing.fxHelp')}</p>
          <DataTable
            rows={rates?.data}
            rowKey={r => r.id}
            columns={[
              { key: 'pair', header: '', cell: r => <span className="ltr font-medium">1 {r.base_currency} = {Number(r.rate)} {r.quote_currency}</span> },
              { key: 'eff', header: t('pricing.effective'), cell: r => formatDate(r.effective_at, locale, true) },
              { key: 'src', header: '', cell: r => <span className="text-xs text-slate-500">{r.source}{r.creator ? ` · ${r.creator.name}` : ''}</span> },
            ]}
          />
        </Card>
      </div>

      <Dialog open={ruleForm !== null} title={t('pricing.new')} onClose={() => setRuleForm(null)} footer={<Button onClick={saveRule} data-testid="save-rule">{t('common.save')}</Button>}>
        {ruleForm && (
          <div className="space-y-3">
            {error && <Alert tone="error">{error}</Alert>}
            <Field label={t('common.name')} required>{id => <Input id={id} value={ruleForm.name} onChange={setR('name')} />}</Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label={t('pricing.type')}>{id => <Select id={id} value={ruleForm.type} onChange={setR('type')} options={[{ value: 'markup', label: t('pricing.markup') }, { value: 'commission', label: t('pricing.commission') }]} />}</Field>
              <Field label={t('pricing.calculation')}>{id => <Select id={id} value={ruleForm.calculation} onChange={setR('calculation')} options={[{ value: 'percent', label: t('pricing.percent') }, { value: 'fixed', label: t('pricing.fixed') }]} />}</Field>
              <Field label={t('pricing.value')} required>{id => <Input id={id} dir="ltr" value={ruleForm.value} onChange={setR('value')} />}</Field>
              {ruleForm.calculation === 'fixed'
                ? <Field label={t('common.currency')} required>{id => <Select id={id} value={ruleForm.currency} placeholder="—" onChange={setR('currency')} options={CURRENCIES.map(c => ({ value: c, label: c }))} />}</Field>
                : <Field label={t('pricing.basis')}>{id => <Select id={id} value={ruleForm.basis} onChange={setR('basis')} options={[{ value: 'supplier_total', label: t('pricing.supplier_total') }, { value: 'base_fare', label: t('pricing.base_fare') }]} />}</Field>}
              <Field label={t('pricing.scope')}>{id => <Select id={id} value={ruleForm.tenant_id} placeholder={t('pricing.global')} onChange={setR('tenant_id')} options={tenantOptions} />}</Field>
              <Field label={t('pricing.priority')}>{id => <Input id={id} dir="ltr" type="number" value={ruleForm.priority} onChange={setR('priority')} />}</Field>
              <Field label={t('pricing.airlines')}>{id => <Input id={id} dir="ltr" placeholder="IA, FZ" value={ruleForm.airline_codes} onChange={setR('airline_codes')} />}</Field>
              <Field label={t('pricing.cabin')}>{id => <Select id={id} value={ruleForm.cabin} placeholder={t('common.all')} onChange={setR('cabin')} options={CABINS.map(c => ({ value: c, label: c }))} />}</Field>
              <Field label={t('pricing.origin')}>{id => <Input id={id} dir="ltr" maxLength={3} value={ruleForm.origin} onChange={setR('origin')} />}</Field>
              <Field label={t('pricing.destination')}>{id => <Input id={id} dir="ltr" maxLength={3} value={ruleForm.destination} onChange={setR('destination')} />}</Field>
              <Field label={t('pricing.min')}>{id => <Input id={id} dir="ltr" value={ruleForm.min_amount} onChange={setR('min_amount')} />}</Field>
              <Field label={t('pricing.max')}>{id => <Input id={id} dir="ltr" value={ruleForm.max_amount} onChange={setR('max_amount')} />}</Field>
            </div>
          </div>
        )}
      </Dialog>

      <Dialog open={rateForm !== null} title={t('pricing.newRate')} onClose={() => setRateForm(null)} footer={<Button onClick={saveRate}>{t('common.save')}</Button>}>
        {rateForm && (
          <div className="space-y-3">
            {error && <Alert tone="error">{error}</Alert>}
            <div className="grid grid-cols-3 gap-3">
              <Field label="1">{id => <Select id={id} value={rateForm.base_currency} onChange={e => setRateForm(f => f && ({ ...f, base_currency: e.target.value }))} options={CURRENCIES.map(c => ({ value: c, label: c }))} />}</Field>
              <Field label="=">{id => <Input id={id} dir="ltr" value={rateForm.rate} onChange={e => setRateForm(f => f && ({ ...f, rate: e.target.value }))} />}</Field>
              <Field label=" ">{id => <Select id={id} value={rateForm.quote_currency} onChange={e => setRateForm(f => f && ({ ...f, quote_currency: e.target.value }))} options={CURRENCIES.map(c => ({ value: c, label: c }))} />}</Field>
            </div>
          </div>
        )}
      </Dialog>
    </RequirePermission>
  )
}
