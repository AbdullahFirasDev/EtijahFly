<script setup lang="ts">
import { CABINS, formatDate } from '@etijahfly/api-contracts'
import type { FlightOffer } from '@etijahfly/api-contracts'
import { ApiError } from '~/utils/api-error'

definePageMeta({ permission: 'flights.search' })

interface SearchResponse {
  search_id: number
  status: string
  provider_errors: Record<string, unknown> | null
  result_count: number
  has_test_offers: boolean
  offers: FlightOffer[]
}

interface SearchForm {
  trip_type: 'one_way' | 'round_trip' | 'multi_city'
  slices: { origin: string, destination: string, date: string }[]
  passengers: { adults: number, children: number, infants: number }
  cabin: (typeof CABINS)[number]
}

const { t, locale } = useI18n()
const api = useApi()

useHead({ title: t('nav.search') })

const inDays = (n: number) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10)

const form = useState<SearchForm>('search-form', () => ({
  trip_type: 'one_way',
  slices: [{ origin: 'BGW', destination: 'DXB', date: inDays(14) }],
  passengers: { adults: 1, children: 0, infants: 0 },
  cabin: 'economy',
}))
const result = useState<SearchResponse | null>('search-result', () => null)
const loading = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const sort = ref<'price' | 'duration' | 'departure'>('price')
const directOnly = ref(false)

const tripTypes = computed(() => (['one_way', 'round_trip', 'multi_city'] as const).map(v => ({ label: t(`tripTypes.${v}`), value: v })))
const cabins = computed(() => CABINS.map(v => ({ label: t(`cabins.${v}`), value: v })))
const sortItems = computed(() => [
  { label: t('search.sortPrice'), value: 'price' },
  { label: t('search.sortDuration'), value: 'duration' },
  { label: t('search.sortDeparture'), value: 'departure' },
])

watch(() => form.value.trip_type, (type) => {
  const first = form.value.slices[0]!
  if (type === 'one_way') form.value.slices = [first]
  if (type === 'round_trip') form.value.slices = [first, { origin: first.destination, destination: first.origin, date: form.value.slices[1]?.date ?? inDays(21) }]
  if (type === 'multi_city' && form.value.slices.length < 2) form.value.slices.push({ origin: first.destination, destination: '', date: inDays(21) })
})

function syncReturn() {
  if (form.value.trip_type === 'round_trip' && form.value.slices[1]) {
    form.value.slices[1].origin = form.value.slices[0]!.destination
    form.value.slices[1].destination = form.value.slices[0]!.origin
  }
}

function swap(i: number) {
  const leg = form.value.slices[i]!
  ;[leg.origin, leg.destination] = [leg.destination, leg.origin]
  syncReturn()
}

function addLeg() {
  const last = form.value.slices[form.value.slices.length - 1]!
  form.value.slices.push({ origin: last.destination, destination: '', date: last.date })
}

async function search() {
  loading.value = true
  error.value = null
  fieldErrors.value = {}
  syncReturn()
  try {
    result.value = await api.post<SearchResponse>('/partner/flights/search', { ...form.value } as unknown as Record<string, unknown>)
  }
  catch (e) {
    result.value = null
    if (e instanceof ApiError) {
      error.value = e.message
      fieldErrors.value = e.fieldErrors
    }
  }
  finally {
    loading.value = false
  }
}

const offers = computed(() => {
  const list = [...(result.value?.offers ?? [])].filter(o => !directOnly.value || o.itinerary.every(s => s.stops === 0))
  const duration = (o: FlightOffer) => o.itinerary.reduce((a, s) => a + s.duration_minutes, 0)
  return list.sort((a, b) => sort.value === 'price'
    ? Number(a.price.total) - Number(b.price.total)
    : sort.value === 'duration' ? duration(a) - duration(b) : a.itinerary[0]!.departure_at.localeCompare(b.itinerary[0]!.departure_at))
})

const airlineNames = computed<Record<string, string>>(() => Object.fromEntries((result.value?.offers ?? [])
  .filter(o => o.validating_airline && o.validating_airline_name)
  .map(o => [o.validating_airline as string, o.validating_airline_name as string])))
</script>

<template>
  <div>
    <PageHeader :title="t('search.title')" />

    <UCard class="mb-8">
      <form class="space-y-4" data-testid="search-form" @submit.prevent="search">
        <div class="flex flex-wrap items-end gap-4">
          <UFormField :label="t('search.tripType')">
            <URadioGroup v-model="form.trip_type" :items="tripTypes" orientation="horizontal" />
          </UFormField>
          <UFormField :label="t('search.cabin')" class="ms-auto w-48">
            <USelect v-model="form.cabin" :items="cabins" class="w-full" />
          </UFormField>
        </div>

        <div v-for="(slice, i) in form.slices" :key="i" class="grid items-end gap-3 md:grid-cols-[1fr_auto_1fr_12rem_auto]">
          <UFormField :label="form.trip_type === 'multi_city' ? `${t('search.leg', { n: i + 1 })} · ${t('search.from')}` : t('search.from')" :error="fieldErrors[`slices.${i}.origin`]">
            <AirportInput v-model="slice.origin" :placeholder="t('search.airportPlaceholder')" :test-id="`origin-${i}`" :disabled="form.trip_type === 'round_trip' && i === 1" />
          </UFormField>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-arrow-left-right"
            :aria-label="t('search.swap')"
            :disabled="form.trip_type === 'round_trip' && i === 1"
            class="justify-self-center"
            @click="swap(i)"
          />
          <UFormField :label="t('search.to')" :error="fieldErrors[`slices.${i}.destination`]">
            <AirportInput v-model="slice.destination" :placeholder="t('search.airportPlaceholder')" :test-id="`destination-${i}`" :disabled="form.trip_type === 'round_trip' && i === 1" />
          </UFormField>
          <UFormField :label="form.trip_type === 'round_trip' && i === 1 ? t('search.return') : t('search.depart')" :error="fieldErrors[`slices.${i}.date`]">
            <UInput v-model="slice.date" type="date" :min="inDays(0)" class="w-full" :data-testid="`date-${i}`" />
          </UFormField>
          <UButton
            v-if="form.trip_type === 'multi_city' && form.slices.length > 2"
            color="error"
            variant="ghost"
            icon="i-lucide-x"
            :aria-label="t('search.removeLeg')"
            @click="form.slices.splice(i, 1)"
          />
        </div>
        <UButton v-if="form.trip_type === 'multi_city' && form.slices.length < 6" variant="soft" icon="i-lucide-plus" :label="t('search.addLeg')" @click="addLeg" />

        <div class="flex flex-wrap items-end gap-4">
          <UFormField :label="t('search.adults')">
            <UInputNumber v-model="form.passengers.adults" :min="1" :max="9" class="w-32" />
          </UFormField>
          <UFormField :label="t('search.children')">
            <UInputNumber v-model="form.passengers.children" :min="0" :max="8" class="w-32" />
          </UFormField>
          <UFormField :label="t('search.infants')" :error="fieldErrors['passengers.infants']">
            <UInputNumber v-model="form.passengers.infants" :min="0" :max="form.passengers.adults" class="w-32" />
          </UFormField>
          <UButton type="submit" size="lg" icon="i-lucide-search" :loading="loading" :label="t('search.submit')" class="ms-auto" data-testid="search-submit" />
        </div>
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :description="error" />
      </form>
    </UCard>

    <template v-if="result">
      <TestDataBanner v-if="result.has_test_offers" class="mb-4" />
      <UAlert v-if="result.provider_errors" color="warning" variant="subtle" class="mb-4" :description="t('search.providerErrors')" />

      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="font-medium" data-testid="result-count">
          {{ t('search.results', { count: offers.length }) }}
        </p>
        <div class="flex items-center gap-4">
          <UCheckbox v-model="directOnly" :label="t('search.direct')" />
          <USelect v-model="sort" :items="sortItems" class="w-44" />
        </div>
      </div>

      <UEmpty v-if="!offers.length" icon="i-lucide-plane" :title="t('search.noResults')" />

      <div class="space-y-4">
        <article v-for="offer in offers" :key="offer.id" class="ef-ticket" data-testid="offer-card">
          <div class="min-w-0 p-5 sm:p-6">
            <div class="mb-6 flex flex-wrap items-center gap-x-3 gap-y-2">
              <span class="font-semibold">{{ offer.validating_airline_name ?? offer.validating_airline }}</span>
              <UBadge v-if="offer.is_test" color="warning" variant="soft" size="sm" label="TEST" />
              <UBadge :color="offer.refundable ? 'success' : 'neutral'" variant="subtle" size="sm" :label="offer.refundable ? t('offer.refundable') : t('offer.nonRefundable')" />
              <span v-if="offer.baggage?.checked" class="inline-flex items-center gap-1 text-xs text-(--ui-text-muted)">
                <UIcon name="i-lucide-luggage" /> <span class="ltr">{{ offer.baggage.checked }}</span>
              </span>
              <span v-if="offer.seats_remaining && offer.seats_remaining < 5" class="text-xs font-medium text-(--ui-error)">{{ t('offer.seatsLeft', { n: offer.seats_remaining }) }}</span>
            </div>
            <ItineraryView :slices="offer.itinerary" :airline-names="airlineNames" compact />
          </div>
          <div class="ef-ticket__stub flex flex-col justify-between gap-4 p-5 sm:p-6">
            <div>
              <p class="text-xs text-(--ui-text-muted)">
                {{ t('offer.total') }}
              </p>
              <MoneyText :amount="offer.price.total" :currency="offer.price.currency" class="mt-1 block font-display text-2xl font-semibold" />
              <p class="mt-1.5 text-xs text-(--ui-text-muted)">
                {{ t('offer.net') }} <MoneyText :amount="offer.price.agency_net" :currency="offer.price.currency" class="font-medium text-(--ui-text)" />
              </p>
            </div>
            <div>
              <UButton :to="`/flights/book/${offer.id}`" :label="t('offer.select')" block data-testid="select-offer" />
              <p class="mt-2 text-[11px] text-(--ui-text-dimmed)">
                {{ t('offer.expiresAt', { time: formatDate(offer.expires_at, locale, true) }) }}
              </p>
            </div>
          </div>
        </article>
      </div>
    </template>
  </div>
</template>
