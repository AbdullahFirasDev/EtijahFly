'use client'

import { BOOKING_STATUS_TONE, formatMoney } from '@etijahfly/api-contracts'
import type { BookingStatus, Tone } from '@etijahfly/api-contracts'
import { CircleAlert, CircleCheck, Info, LoaderCircle, TriangleAlert, X } from 'lucide-react'
import Link from 'next/link'
import { useEffect, useId, useRef } from 'react'
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { useI18n } from '@/lib/i18n'

export function cn(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ')
}

// ---------------------------------------------------------------- buttons & inputs

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'success'
const VARIANTS: Record<Variant, string> = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-600/50',
  secondary: 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50',
  danger: 'bg-red-600 text-white hover:bg-red-700 disabled:bg-red-600/50',
  success: 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-emerald-600/50',
  ghost: 'text-slate-600 hover:bg-slate-100',
}

export function Button({ variant = 'primary', loading, size = 'md', className, children, disabled, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant, loading?: boolean, size?: 'sm' | 'md' }) {
  return (
    <button
      type="button"
      {...props}
      disabled={disabled || loading}
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors disabled:cursor-not-allowed',
        size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3.5 py-2 text-sm',
        VARIANTS[variant],
        className,
      )}
    >
      {loading && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
      {children}
    </button>
  )
}

const fieldClass = 'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:bg-slate-100'

/** Fields fill their container unless the caller sets a width (w-48 and w-full would otherwise race). */
const field = (className?: string) => cn(fieldClass, !/(^|\s)w-/.test(className ?? '') && 'w-full', className)

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={field(className)} />
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} className={field(className)} />
}

export function Select({ className, options, placeholder, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { options: { value: string, label: string }[], placeholder?: string }) {
  return (
    <select {...props} className={field(cn('pe-8', className))}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  )
}

export function Field({ label, error, hint, children, required }: { label: string, error?: string, hint?: string, required?: boolean, children: (id: string) => ReactNode }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">
        {label}
        {required && <span className="ms-0.5 text-red-500">*</span>}
      </label>
      {children(id)}
      {hint && !error && <p className="text-xs text-slate-500">{hint}</p>}
      {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
    </div>
  )
}

// ---------------------------------------------------------------- surfaces

export function Card({ title, actions, children, className, padded = true }: { title?: ReactNode, actions?: ReactNode, children: ReactNode, className?: string, padded?: boolean }) {
  return (
    <section className={cn('rounded-[10px] border border-slate-200 bg-white', className)}>
      {(title || actions) && (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
          {title && <h2 className="font-semibold text-slate-800">{title}</h2>}
          {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
      )}
      <div className={padded ? 'p-5' : ''}>{children}</div>
    </section>
  )
}

export function PageHeader({ title, description, actions }: { title: string, description?: string, actions?: ReactNode }) {
  return (
    <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
      <div className="min-w-0">
        <h1 className="font-display text-3xl font-semibold leading-tight">{title}</h1>
        {description && <p className="mt-1.5 text-slate-500">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export interface LedgerRow { currency: string, bookings: number, sales: string, commission: string, agency_net: string, supplier_cost: string, markup: string, profit: string }

/**
 * A period's sales read as an income statement: sales, less agency commissions = agency net,
 * less supplier cost = platform profit (double-ruled, as totals are in a ledger).
 */
export function Ledger({ row }: { row: LedgerRow }) {
  const { t, locale } = useI18n()
  const money = (v: string, sign = '') => <span className="ltr tabular-nums">{sign}{formatMoney(v, row.currency, locale)}</span>
  const line = 'flex items-baseline justify-between gap-6 py-2'
  return (
    <div className="text-sm">
      <p className="mb-2 flex items-baseline justify-between gap-4">
        <span className="ltr font-display text-lg font-semibold">{row.currency}</span>
        <span className="text-xs text-slate-500">{t('ledger.bookings', { n: row.bookings })}</span>
      </p>
      <dl>
        <div className={line}><dt>{t('reports.sales')}</dt><dd className="font-medium">{money(row.sales)}</dd></div>
        <div className={line}><dt className="text-slate-500">{t('ledger.lessCommission')}</dt><dd className="text-slate-600">{money(row.commission, '− ')}</dd></div>
        <div className={cn(line, 'border-t border-slate-300')}><dt>{t('ledger.agencyNet')}</dt><dd className="font-medium">{money(row.agency_net)}</dd></div>
        <div className={line}><dt className="text-slate-500">{t('ledger.lessSupplier')}</dt><dd className="text-slate-600">{money(row.supplier_cost, '− ')}</dd></div>
        <div className={cn(line, 'border-t border-slate-300')}>
          <dt className="font-semibold">{t('dashboard.profit')}</dt>
          <dd className={cn('border-b-[3px] border-double pb-0.5 font-display text-xl font-semibold', Number(row.profit) < 0 ? 'border-loss text-loss' : 'border-gain text-gain')}>{money(row.profit)}</dd>
        </div>
      </dl>
      <p className="mt-2 text-xs text-slate-500">{t('ledger.markupNote')} {money(row.markup)}</p>
    </div>
  )
}

/** One queue of work waiting on platform staff: a count, what it is, and where to clear it. */
export function QueueItem({ count, label, href, tone = 'warning' }: { count: number, label: string, href: string, tone?: 'warning' | 'error' }) {
  const { t } = useI18n()
  const waiting = count > 0
  return (
    <Link href={href} className={cn('group flex flex-col gap-1 bg-white p-5 transition-colors hover:bg-slate-50', !waiting && 'text-slate-400')}>
      <span className={cn('font-display text-4xl font-semibold leading-none tabular-nums', waiting && (tone === 'error' ? 'text-loss' : 'text-ochre-600'))}>{count}</span>
      <span className={cn('mt-1 text-sm', waiting && 'font-medium text-slate-800')}>{label}</span>
      <span className={cn('text-xs', waiting && 'text-brand-600 group-hover:underline')}>{waiting ? t('dashboard.review') : t('dashboard.nothingWaiting')}</span>
    </Link>
  )
}

const TONE_BADGE: Record<Tone, string> = {
  neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
  info: 'bg-sky-50 text-sky-700 ring-sky-200',
  success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  warning: 'bg-amber-50 text-amber-800 ring-amber-200',
  error: 'bg-red-50 text-red-700 ring-red-200',
}

export function Badge({ tone = 'neutral', children }: { tone?: Tone, children: ReactNode }) {
  return <span className={cn('inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset', TONE_BADGE[tone])}>{children}</span>
}

const OTHER_TONES: Record<string, Tone> = {
  approved: 'success', completed: 'success', active: 'success', healthy: 'success', ok: 'success', accepted: 'success', issued: 'success',
  pending: 'warning', requested: 'warning', warning: 'warning', pending_verification: 'warning', in_progress: 'info',
  rejected: 'error', suspended: 'error', disabled: 'error', frozen: 'error', unhealthy: 'error', error: 'error', needs_attention: 'error', failed: 'error',
}

export function StatusBadge({ status }: { status: string | null | undefined }) {
  const { t } = useI18n()
  if (!status) return <span className="text-slate-400">—</span>
  const tone = BOOKING_STATUS_TONE[status as BookingStatus] ?? OTHER_TONES[status] ?? 'neutral'
  return <Badge tone={tone}>{t(`status.${status}`)}</Badge>
}

const ALERT_STYLE: Record<Tone, { box: string, Icon: typeof Info }> = {
  neutral: { box: 'border-slate-200 bg-slate-50 text-slate-700', Icon: Info },
  info: { box: 'border-sky-200 bg-sky-50 text-sky-800', Icon: Info },
  success: { box: 'border-emerald-200 bg-emerald-50 text-emerald-800', Icon: CircleCheck },
  warning: { box: 'border-amber-300 bg-amber-50 text-amber-900', Icon: TriangleAlert },
  error: { box: 'border-red-200 bg-red-50 text-red-800', Icon: CircleAlert },
}

export function Alert({ tone = 'info', title, children }: { tone?: Tone, title?: string, children?: ReactNode }) {
  const { box, Icon } = ALERT_STYLE[tone]
  return (
    <div className={cn('flex gap-3 rounded-md border p-3 text-sm', box)} role={tone === 'error' ? 'alert' : 'status'}>
      <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
      <div>
        {title && <p className="font-semibold">{title}</p>}
        {children}
      </div>
    </div>
  )
}

export function Money({ amount, currency, className }: { amount: string | number | null | undefined, currency: string, className?: string }) {
  const { locale } = useI18n()
  return <span className={cn('ltr tabular-nums', className)}>{formatMoney(amount, currency, locale)}</span>
}

export function Spinner() {
  return <LoaderCircle className="size-5 animate-spin text-slate-400" aria-label="loading" />
}

export function Empty({ children }: { children?: ReactNode }) {
  const { t } = useI18n()
  return <p className="py-10 text-center text-sm text-slate-500">{children ?? t('common.empty')}</p>
}

// ---------------------------------------------------------------- table

export interface Column<T> {
  key: string
  header: ReactNode
  cell: (row: T) => ReactNode
  className?: string
}

export function DataTable<T>({ rows, columns, loading, rowKey, testId }: { rows: T[] | undefined, columns: Column<T>[], loading?: boolean, rowKey: (row: T) => string | number, testId?: string }) {
  return (
    <div className="overflow-x-auto" data-testid={testId}>
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead>
          <tr>
            {columns.map(c => <th key={c.key} scope="col" className={cn('px-4 pt-3 pb-2.5 text-start text-xs font-medium text-slate-500', c.className)}>{c.header}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {loading && !rows?.length && (
            <tr><td colSpan={columns.length} className="py-8 text-center"><Spinner /></td></tr>
          )}
          {!loading && !rows?.length && (
            <tr><td colSpan={columns.length}><Empty /></td></tr>
          )}
          {rows?.map(row => (
            <tr key={rowKey(row)} className="hover:bg-slate-50">
              {columns.map(c => <td key={c.key} className={cn('px-4 py-2.5 align-middle', c.className)}>{c.cell(row)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function Pagination({ page, last, onChange }: { page: number, last: number, onChange: (page: number) => void }) {
  const { t } = useI18n()
  if (last <= 1) return null
  return (
    <div className="flex items-center justify-center gap-3 py-4 text-sm">
      <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>{t('common.previous')}</Button>
      <span className="text-slate-500">{t('common.page', { page, last })}</span>
      <Button variant="secondary" size="sm" disabled={page >= last} onClick={() => onChange(page + 1)}>{t('common.next')}</Button>
    </div>
  )
}

// ---------------------------------------------------------------- dialog

export function Dialog({ open, title, onClose, children, footer }: { open: boolean, title: string, onClose: () => void, children: ReactNode, footer?: ReactNode }) {
  const panel = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    panel.current?.querySelector<HTMLElement>('input, textarea, select, button')?.focus()
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" onMouseDown={e => e.target === e.currentTarget && onClose()}>
      <div ref={panel} role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-lg rounded-[10px] bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
          <h2 className="font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="rounded p-1 text-slate-500 hover:bg-slate-100" aria-label="close"><X className="size-4" /></button>
        </div>
        <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>
        {footer && <div className="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">{footer}</div>}
      </div>
    </div>
  )
}
