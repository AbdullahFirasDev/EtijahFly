/**
 * Shared API contracts for the EtijahFly portals.
 *
 * `schema.d.ts` is generated from the backend OpenAPI document (Scramble):
 *   php artisan scramble:export   (apps/backend)
 *   npm run contracts:generate    (repo root)
 * The aliases below refine a few loosely-typed JSON columns (itineraries) and add
 * framework-neutral helpers used by both the Nuxt and the Next.js portal.
 */
import type { components } from './schema'

export type { paths, components } from './schema'

type Schemas = components['schemas']

// ------------------------------------------------------------------ itinerary

export interface Segment {
  marketing_carrier: string
  operating_carrier: string
  flight_number: string
  origin: string
  destination: string
  departure_at: string
  arrival_at: string
  duration_minutes: number
  aircraft: string | null
  booking_class: string | null
  departure_terminal?: string | null
  arrival_terminal?: string | null
}

export interface Slice {
  origin: string
  destination: string
  departure_at: string
  arrival_at: string
  duration_minutes: number
  stops: number
  segments: Segment[]
}

export interface Baggage {
  checked?: string | null
  cabin?: string | null
}

// ------------------------------------------------------------------ resources

export type FlightOffer = Omit<Schemas['FlightOfferResource'], 'itinerary' | 'baggage'> & {
  itinerary: Slice[]
  baggage: Baggage | null
}

export type Booking = Omit<Schemas['BookingResource'], 'itinerary' | 'baggage' | 'status'> & {
  status: BookingStatus
  itinerary: Slice[]
  baggage: Baggage | null
}

export type Refund = Schemas['RefundResource']
export type User = Schemas['UserResource']
export type Tenant = Schemas['TenantResource']
export type Branding = Schemas['BrandingResource']
export type LedgerAccount = Schemas['LedgerAccountResource']
export type Payment = Schemas['PaymentResource']
export type Invoice = Schemas['InvoiceResource']
export type Traveler = Schemas['TravelerResource']
export type Role = Schemas['RoleResource']
export type Invitation = Schemas['InvitationResource']
export type PricingRule = Schemas['PricingRuleResource']
export type Adjustment = Schemas['AdjustmentResource']
export type AuditLog = Schemas['AuditLogResource']

export interface Me {
  id: number
  name: string
  email: string
  phone: string | null
  locale: string
  portal: 'partner' | 'admin'
  roles: { name: string, label: string }[]
  permissions: string[]
  tenant: Tenant | null
  branding: Branding | null
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
  links?: Record<string, string | null>
}

/** Uniform error body: { message, code, details? , errors? } */
export interface ApiErrorBody {
  message: string
  code: string
  details?: Record<string, unknown>
  errors?: Record<string, string[]>
}

export interface Money {
  amount: string
  currency: string
}

// ------------------------------------------------------------------ domain constants

export const BOOKING_STATUSES = [
  'pending', 'on_hold', 'confirmed', 'ticketed', 'failed', 'expired', 'cancelled', 'refund_pending', 'refunded',
] as const
export type BookingStatus = (typeof BOOKING_STATUSES)[number]

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'error'

export const BOOKING_STATUS_TONE: Record<BookingStatus, Tone> = {
  pending: 'neutral',
  on_hold: 'warning',
  confirmed: 'info',
  ticketed: 'success',
  failed: 'error',
  expired: 'neutral',
  cancelled: 'neutral',
  refund_pending: 'warning',
  refunded: 'info',
}

export const CURRENCIES = ['IQD', 'USD'] as const
export type Currency = (typeof CURRENCIES)[number]

export const CABINS = ['economy', 'premium_economy', 'business', 'first'] as const
export const TRIP_TYPES = ['one_way', 'round_trip', 'multi_city'] as const

/** Header required by money-moving endpoints (bookings, issue, refund, payments). */
export const IDEMPOTENCY_HEADER = 'Idempotency-Key'

export function newIdempotencyKey(): string {
  return globalThis.crypto.randomUUID()
}

// ------------------------------------------------------------------ formatting

const DECIMALS: Record<string, number> = { IQD: 0, USD: 2 }

/** Format a decimal string from the API without converting through floating point maths. */
export function formatMoney(amount: string | number | null | undefined, currency: string, locale = 'ar'): string {
  if (amount === null || amount === undefined || amount === '') return '—'
  const value = typeof amount === 'number' ? amount : Number(amount)
  const digits = DECIMALS[currency] ?? 2
  return new Intl.NumberFormat(locale === 'ar' ? 'ar-IQ' : 'en-US', {
    style: 'currency',
    currency,
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
    numberingSystem: 'latn',
  }).format(value)
}

export function formatDuration(minutes: number, locale = 'ar'): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return locale === 'ar' ? `${h}س ${String(m).padStart(2, '0')}د` : `${h}h ${String(m).padStart(2, '0')}m`
}

/** Local wall-clock time of an ISO timestamp, preserving the airport offset. */
export function formatLocalTime(iso: string): string {
  return iso.slice(11, 16)
}

export function formatDate(iso: string | null | undefined, locale = 'ar', withTime = false): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-IQ' : 'en-GB', {
    dateStyle: 'medium',
    ...(withTime ? { timeStyle: 'short' } : {}),
    numberingSystem: 'latn',
  }).format(new Date(iso))
}

/** Human message for an API error, falling back to a generic text. */
export function errorMessage(body: unknown, fallback = 'Unexpected error'): string {
  if (body && typeof body === 'object' && 'message' in body && typeof (body as ApiErrorBody).message === 'string') {
    return (body as ApiErrorBody).message
  }
  return fallback
}
