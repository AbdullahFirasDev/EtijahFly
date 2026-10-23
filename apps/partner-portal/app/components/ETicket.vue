<script setup lang="ts">
import { formatDuration, formatLocalTime } from '@etijahfly/api-contracts'
import type { Booking, BookingStatus } from '@etijahfly/api-contracts'
import { renderSVG } from 'uqr'

/**
 * The booking's electronic ticket as shown at the top of the booking page.
 * Always English and left-to-right (like the airline documents it mirrors), always printed on light paper.
 */
const props = defineProps<{ booking: Booking & Required<Pick<Booking, 'passengers'>> }>()

const api = useApi()
const config = useRuntimeConfig()

const STATUS: Record<BookingStatus, string> = {
  pending: 'Pending',
  on_hold: 'On hold',
  confirmed: 'Confirmed',
  ticketed: 'Ticketed',
  failed: 'Failed',
  expired: 'Expired',
  cancelled: 'Cancelled',
  refund_pending: 'Refund pending',
  refunded: 'Refunded',
}
const CABIN: Record<string, string> = { economy: 'Economy', premium_economy: 'Premium economy', business: 'Business', first: 'First' }
const PAX: Record<string, string> = { ADT: 'Adult', CHD: 'Child', INF: 'Infant' }

const b = computed(() => props.booking)
const isTicketed = computed(() => ['ticketed', 'refund_pending', 'refunded'].includes(b.value.status))
const isVoid = computed(() => ['cancelled', 'expired', 'failed', 'refunded'].includes(b.value.status))
const segments = computed(() => b.value.itinerary.flatMap(s => s.segments))
const validating = computed(() => segments.value[0]?.marketing_carrier ?? '')

// English airline and city names from the platform's reference data; codes stand in when a record is missing.
const airlines = ref<Record<string, string>>({})
const cities = ref<Record<string, string>>({})
async function loadNames() {
  const carriers = [...new Set(segments.value.flatMap(s => [s.marketing_carrier, s.operating_carrier]))]
  const airports = [...new Set(segments.value.flatMap(s => [s.origin, s.destination]))]
  await Promise.all([
    ...carriers.map(async (code) => {
      const res = await api.get<{ data: { iata_code: string | null, name: string }[] }>('/reference/airlines', { q: code }).catch(() => null)
      const hit = res?.data.find(a => a.iata_code === code)
      if (hit) airlines.value[code] = hit.name
    }),
    ...airports.map(async (code) => {
      const res = await api.get<{ data: { iata_code: string, city_en: string }[] }>('/reference/airports', { q: code }).catch(() => null)
      const hit = res?.data.find(a => a.iata_code === code)
      if (hit) cities.value[code] = hit.city_en
    }),
  ])
}
onMounted(loadNames)

const sliceLabel = (i: number) => {
  if (b.value.trip_type === 'round_trip') return i === 0 ? 'Outbound' : 'Return'
  if (b.value.trip_type === 'multi_city') return `Flight ${i + 1}`
  return 'Departure'
}
const day = (iso: string) => new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', numberingSystem: 'latn' })
  .format(new Date(iso.slice(0, 10) + 'T12:00:00'))
const stamp = (iso: string | null | undefined) => (iso
  ? new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', numberingSystem: 'latn' }).format(new Date(iso))
  : '')
const ticketNo = (n: string) => `${n.slice(0, 3)}-${n.slice(3)}`

const footnote = computed(() => {
  const s = b.value.status
  if (s === 'on_hold' && b.value.hold_expires_at) return `Held, not ticketed. The hold is released ${stamp(b.value.hold_expires_at)} unless issued.`
  if (s === 'ticketed') return `Issued ${stamp(b.value.ticketed_at)}`
  if (s === 'refund_pending') return 'A refund is being processed for these tickets.'
  if (s === 'refunded') return 'These tickets have been refunded and are no longer valid.'
  if (s === 'cancelled') return 'This booking was cancelled.'
  if (s === 'expired') return 'This hold expired before the tickets were issued.'
  if (s === 'failed') return 'The airline did not confirm this booking.'
  return 'Awaiting confirmation from the airline.'
})

// QR carries the same identifiers printed on the ticket, nothing more.
const qr = computed(() => {
  const lines = [
    `${config.public.platformName} ${isTicketed.value ? 'e-ticket' : 'reservation'}`,
    `Ref: ${b.value.reference}`,
    b.value.pnr && `PNR: ${b.value.pnr}`,
    ...b.value.passengers.filter(p => p.ticket_number).map(p => `Ticket: ${ticketNo(p.ticket_number!)} ${p.last_name}/${p.first_name}`),
    `Route: ${b.value.route}`,
    b.value.is_test && 'TEST - NOT VALID FOR TRAVEL',
  ].filter(Boolean)
  return renderSVG(lines.join('\n'), { border: 0, ecc: 'M', blackColor: '#16181d', whiteColor: '#ffffff' })
})
</script>

<template>
  <article
    dir="ltr"
    lang="en"
    class="ef-pass"
    :class="{ 'is-void': isVoid }"
    :aria-label="`${isTicketed ? 'Electronic ticket' : 'Flight reservation'} ${booking.reference}`"
  >
    <div v-if="booking.is_test" class="ef-pass__test" role="note">
      Test booking from a simulated provider. Not valid for travel.
    </div>

    <div class="ef-pass__body">
      <!-- Main coupon: carrier, flights, passengers -->
      <div class="ef-pass__main">
        <header class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex min-w-0 items-center gap-3">
            <span class="ef-pass__carrier" aria-hidden="true">{{ validating }}</span>
            <div class="min-w-0">
              <p class="truncate text-base font-semibold">
                {{ airlines[validating] ?? validating }}
              </p>
              <p class="ef-pass__muted text-xs">
                {{ isTicketed ? 'Electronic ticket' : 'Flight reservation' }}
              </p>
            </div>
          </div>
          <span class="ef-pass__status" :data-status="booking.status">{{ STATUS[booking.status] }}</span>
        </header>

        <section v-for="(slice, i) in booking.itinerary" :key="i" class="ef-pass__slice">
          <p class="ef-pass__muted mb-3 text-xs">
            <span class="font-medium text-[var(--pass-ink)]">{{ sliceLabel(i) }}</span>
            <span class="ms-2">{{ day(slice.departure_at) }}</span>
          </p>

          <div class="flex items-start gap-3 sm:gap-5">
            <div class="min-w-0">
              <p class="ef-pass__code">
                {{ slice.origin }}
              </p>
              <p class="ef-pass__muted mt-1 truncate text-xs">
                {{ cities[slice.origin] ?? '' }}&nbsp;
              </p>
              <p class="mt-2 text-xl font-semibold tabular-nums">
                {{ formatLocalTime(slice.departure_at) }}
              </p>
            </div>

            <div class="flex min-w-14 flex-1 flex-col items-center gap-1.5 pt-3">
              <span class="ef-pass__muted text-xs tabular-nums">{{ formatDuration(slice.duration_minutes, 'en') }}</span>
              <div class="flex w-full items-center gap-1.5" aria-hidden="true">
                <span class="ef-pass__dot" />
                <span class="ef-pass__line" />
                <UIcon name="i-lucide-plane" class="size-4 shrink-0 text-[var(--pass-accent)]" />
                <span class="ef-pass__line" />
                <span class="ef-pass__dot" />
              </div>
              <span class="text-xs font-medium" :class="slice.stops === 0 ? 'text-[var(--pass-ok)]' : 'text-[var(--pass-warn)]'">
                {{ slice.stops === 0 ? 'Non-stop' : `${slice.stops} stop${slice.stops > 1 ? 's' : ''} via ${slice.segments.slice(0, -1).map(s => s.destination).join(', ')}` }}
              </span>
            </div>

            <div class="min-w-0 text-end">
              <p class="ef-pass__code">
                {{ slice.destination }}
              </p>
              <p class="ef-pass__muted mt-1 truncate text-xs">
                {{ cities[slice.destination] ?? '' }}&nbsp;
              </p>
              <p class="mt-2 text-xl font-semibold tabular-nums">
                {{ formatLocalTime(slice.arrival_at) }}
              </p>
            </div>
          </div>

          <dl v-for="(seg, s) in slice.segments" :key="s" class="ef-pass__facts">
            <div>
              <dt>Flight</dt>
              <dd class="font-semibold">
                {{ seg.flight_number }}
              </dd>
            </div>
            <div v-if="slice.segments.length > 1">
              <dt>Route</dt>
              <dd>{{ seg.origin }} {{ formatLocalTime(seg.departure_at) }} – {{ seg.destination }} {{ formatLocalTime(seg.arrival_at) }}</dd>
            </div>
            <div>
              <dt>Class</dt>
              <dd>{{ CABIN[booking.cabin] ?? booking.cabin }}<template v-if="seg.booking_class"> ({{ seg.booking_class }})</template></dd>
            </div>
            <div v-if="seg.aircraft">
              <dt>Aircraft</dt>
              <dd>{{ seg.aircraft }}</dd>
            </div>
            <div v-if="seg.departure_terminal || seg.arrival_terminal">
              <dt>Terminal</dt>
              <dd>{{ seg.departure_terminal ?? '–' }} / {{ seg.arrival_terminal ?? '–' }}</dd>
            </div>
            <div v-if="seg.operating_carrier && seg.operating_carrier !== seg.marketing_carrier">
              <dt>Operated by</dt>
              <dd>{{ airlines[seg.operating_carrier] ?? seg.operating_carrier }}</dd>
            </div>
          </dl>
        </section>

        <section class="ef-pass__slice">
          <table class="ef-pass__pax">
            <thead>
              <tr>
                <th scope="col">
                  Passenger
                </th>
                <th scope="col" class="max-sm:hidden">
                  Type
                </th>
                <th scope="col" class="max-md:hidden print:table-cell">
                  Document
                </th>
                <th scope="col" class="text-end">
                  E-ticket number
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in booking.passengers" :key="p.id">
                <td class="font-semibold whitespace-nowrap">
                  {{ p.last_name }}/{{ p.first_name }} {{ p.title }}
                </td>
                <td class="max-sm:hidden">
                  {{ PAX[p.type] ?? p.type }}
                </td>
                <td class="max-md:hidden print:table-cell tabular-nums">
                  {{ p.document_number_masked }}
                </td>
                <td class="text-end whitespace-nowrap tabular-nums">
                  <span v-if="p.ticket_number" class="font-semibold" data-testid="ticket-number">{{ ticketNo(p.ticket_number) }}</span>
                  <span v-else class="ef-pass__muted">Not issued yet</span>
                </td>
              </tr>
            </tbody>
          </table>

          <dl class="ef-pass__facts mt-4">
            <div v-if="booking.baggage?.checked">
              <dt>Checked baggage</dt>
              <dd>{{ booking.baggage.checked }}</dd>
            </div>
            <div v-if="booking.baggage?.cabin">
              <dt>Cabin baggage</dt>
              <dd>{{ booking.baggage.cabin }}</dd>
            </div>
            <div>
              <dt>Fare</dt>
              <dd>{{ booking.refundable ? 'Refundable' : 'Non-refundable' }}</dd>
            </div>
          </dl>
        </section>
      </div>

      <!-- Stub: the identifiers an airline or agent asks for -->
      <aside class="ef-pass__stub">
        <div class="[grid-area:ref]">
          <p class="ef-pass__muted text-xs">
            Booking reference
          </p>
          <p class="ef-pass__ref">
            {{ booking.reference }}
          </p>
        </div>
        <div class="[grid-area:pnr]">
          <p class="ef-pass__muted text-xs">
            Airline PNR
          </p>
          <p class="ef-pass__ref" data-testid="pnr">{{ booking.pnr ?? '–' }}</p>
        </div>
        <!-- eslint-disable-next-line vue/no-v-html -- SVG generated locally by uqr from booking identifiers -->
        <div class="ef-pass__qr [grid-area:qr]" role="img" :aria-label="`QR code for booking ${booking.reference}`" v-html="qr" />
        <p class="ef-pass__muted text-xs leading-relaxed [grid-area:note]">
          {{ footnote }}
        </p>
      </aside>
    </div>
  </article>
</template>

<style scoped>
.ef-pass {
  /* A ticket is paper: the same light surface on screen in either theme and in print. */
  --pass-paper: #ffffff;
  --pass-ink: #16181d;
  --pass-muted: #62666e;
  --pass-rule: #e3e4e8;
  --pass-tint: #f5f5f7;
  --pass-accent: var(--ui-primary);
  --pass-ok: #15803d;
  --pass-warn: #b45309;
  --pass-bad: #b91c1c;
  --stub: 15.5rem;
  --notch: 0.7rem;

  position: relative;
  color: var(--pass-ink);
  background: var(--pass-paper);
  border: 1px solid var(--pass-rule);
  border-radius: 0.75rem;
  overflow: hidden;
  font-feature-settings: "tnum" 1;
  print-color-adjust: exact;
  -webkit-print-color-adjust: exact;
}

/* Agency colour as a thin edge along the top, like the printed band on ticket stock. */
.ef-pass::before {
  content: "";
  display: block;
  height: 5px;
  background: var(--pass-accent);
}

.ef-pass__test {
  padding: 0.5rem 1.25rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #713f12;
  background: repeating-linear-gradient(-45deg, #fde68a 0 12px, #fcd34d 12px 24px);
}

.ef-pass__body {
  display: grid;
  grid-template-columns: 1fr;
}

.ef-pass__main {
  min-width: 0;
  padding: 1.25rem;
}

.ef-pass__slice {
  margin-top: 1.5rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--pass-rule);
}

.ef-pass__muted {
  color: var(--pass-muted);
}

.ef-pass__carrier {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  flex-shrink: 0;
  border-radius: 0.5rem;
  background: var(--pass-ink);
  color: #fff;
  font-family: var(--font-display);
  font-size: 1.05rem;
  font-weight: 600;
}

.ef-pass__status {
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--pass-muted);
  background: var(--pass-tint);
}
.ef-pass__status[data-status="ticketed"] { color: var(--pass-ok); background: #dcfce7; }
.ef-pass__status[data-status="on_hold"],
.ef-pass__status[data-status="refund_pending"] { color: var(--pass-warn); background: #fef3c7; }
.ef-pass__status[data-status="failed"],
.ef-pass__status[data-status="cancelled"],
.ef-pass__status[data-status="refunded"] { color: var(--pass-bad); background: #fee2e2; }

.ef-pass__code {
  font-family: var(--font-display);
  font-size: clamp(2.25rem, 7vw, 3.25rem);
  font-weight: 600;
  line-height: 0.9;
  letter-spacing: 0.01em;
}
.is-void .ef-pass__code {
  color: var(--pass-muted);
  text-decoration: line-through 2px;
}

.ef-pass__dot {
  width: 0.375rem;
  height: 0.375rem;
  flex-shrink: 0;
  border-radius: 999px;
  background: var(--pass-muted);
}
.ef-pass__line {
  height: 1px;
  flex: 1;
  background: var(--pass-rule);
}

.ef-pass__facts {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.75rem;
  margin-top: 1.25rem;
  font-size: 0.875rem;
}
.ef-pass__facts dt {
  font-size: 0.75rem;
  color: var(--pass-muted);
}
.ef-pass__facts dd {
  margin-top: 0.125rem;
}

.ef-pass__pax {
  width: 100%;
  font-size: 0.875rem;
  border-collapse: collapse;
}
.ef-pass__pax th {
  padding-bottom: 0.5rem;
  font-size: 0.75rem;
  font-weight: 400;
  text-align: start;
  color: var(--pass-muted);
}
.ef-pass__pax th.text-end { text-align: end; }
.ef-pass__pax td {
  padding: 0.6rem 0;
  border-top: 1px solid var(--pass-rule);
}
.ef-pass__pax td + td,
.ef-pass__pax th + th {
  padding-inline-start: 1rem;
}

.ef-pass__stub {
  display: grid;
  grid-template-columns: 1fr auto;
  grid-template-areas: "ref qr" "pnr qr" "note note";
  align-content: start;
  gap: 1.25rem;
  padding: 1.25rem;
  background: var(--pass-tint);
  border-top: 2px dashed var(--pass-rule);
}

.ef-pass__ref {
  margin-top: 0.25rem;
  font-family: var(--font-display);
  font-size: 1.5rem;
  font-weight: 600;
  line-height: 1.1;
  white-space: nowrap;
}

.ef-pass__qr {
  width: 7.5rem;
  padding: 0.5rem;
  background: #fff;
  border-radius: 0.375rem;
  box-shadow: 0 0 0 1px var(--pass-rule);
}
.ef-pass__qr :deep(svg) {
  display: block;
  width: 100%;
  height: auto;
}

@media (min-width: 768px), print {
  .ef-pass__main { padding: 1.75rem 2rem; }

  .ef-pass__body {
    grid-template-columns: 1fr var(--stub);
  }

  /* Notches where the perforation meets the top and bottom edges. */
  .ef-pass {
    mask:
      radial-gradient(circle at calc(100% - var(--stub)) 0, #0000 var(--notch), #000 calc(var(--notch) + 0.5px)) top / 100% 51% no-repeat,
      radial-gradient(circle at calc(100% - var(--stub)) 100%, #0000 var(--notch), #000 calc(var(--notch) + 0.5px)) bottom / 100% 51% no-repeat;
  }

  .ef-pass__stub {
    grid-template-columns: 1fr;
    grid-template-areas: "ref" "pnr" "qr" "note";
    padding: 1.75rem 1.5rem;
    border-top: 0;
    border-inline-start: 2px dashed var(--pass-rule);
  }

  .ef-pass__qr { width: 100%; max-width: 9.5rem; }
}

@media print {
  .ef-pass {
    --stub: 13rem;
    break-inside: avoid;
    font-size: 11pt;
  }
  .ef-pass__code { font-size: 2.75rem; }
}
</style>
