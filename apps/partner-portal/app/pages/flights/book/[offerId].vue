<script setup lang="ts">
import { newIdempotencyKey } from '@etijahfly/api-contracts'
import type { Booking, FlightOffer, Money, Paginated, Traveler } from '@etijahfly/api-contracts'
import type { PassengerInput } from '~/components/PassengerFields.vue'
import { ApiError } from '~/utils/api-error'

definePageMeta({ permission: 'bookings.create' })

interface PriceCheck {
  available: boolean
  price_changed: boolean
  previous_total: Money
  current_total: Money
  offer: FlightOffer
}

const { t } = useI18n()
const { can } = useAuth()
const api = useApi()
const toast = useToast()
const route = useRoute()
const offerId = String(route.params.offerId)

useHead({ title: t('book.title') })

const offer = ref<FlightOffer | null>(null)
const check = ref<PriceCheck | null>(null)
const checkError = ref<string | null>(null)
const checking = ref(true)
const acceptedNewPrice = ref(false)

const travelers = ref<Traveler[]>([])
const passengers = ref<PassengerInput[]>([])
const contact = reactive({ email: '', phone: '' })
const saveTravelers = ref(false)

const submitting = ref(false)
const submitError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
// One key per booking attempt; reused if the user retries the same submission.
let idempotencyKey = newIdempotencyKey()

function blank(type: PassengerInput['type']): PassengerInput {
  return {
    type, traveler_id: null, title: type === 'ADT' ? 'MR' : 'MSTR', first_name: '', last_name: '', gender: 'M',
    date_of_birth: '', nationality: 'IQ', document_type: 'passport', document_number: '', document_expiry: '', document_country: 'IQ',
  }
}

async function load() {
  checking.value = true
  checkError.value = null
  try {
    offer.value = (await api.get<{ offer: FlightOffer }>(`/partner/flights/offers/${offerId}`)).offer
    const counts = offer.value.passengers as { adults: number, children: number, infants: number }
    passengers.value = [
      ...Array.from({ length: counts.adults }, () => blank('ADT')),
      ...Array.from({ length: counts.children }, () => blank('CHD')),
      ...Array.from({ length: counts.infants }, () => blank('INF')),
    ]
    check.value = await api.post<PriceCheck>(`/partner/flights/offers/${offerId}/price-check`)
    offer.value = check.value.offer
  }
  catch (e) {
    checkError.value = e instanceof ApiError ? e.message : t('common.error')
  }
  finally {
    checking.value = false
  }

  if (can('travelers.manage')) {
    travelers.value = (await api.get<Paginated<Traveler>>('/partner/travelers').catch(() => ({ data: [] }) as unknown as Paginated<Traveler>)).data
  }
}
onMounted(load)

const canSubmit = computed(() => !!offer.value && !!check.value && (!check.value.price_changed || acceptedNewPrice.value))

async function submit() {
  if (!offer.value) return
  submitting.value = true
  submitError.value = null
  fieldErrors.value = {}
  try {
    const { data } = await api.post<{ data: Booking }>('/partner/bookings', {
      offer_id: offer.value.id,
      expected_total: offer.value.price.total,
      contact: { email: contact.email, phone: contact.phone || null },
      passengers: passengers.value.map(p => ({ ...p, title: p.title || null })),
      save_travelers: saveTravelers.value,
    }, { idempotent: idempotencyKey })
    toast.add({ title: t('book.created', { reference: data.reference }), color: 'success', icon: 'i-lucide-check' })
    await navigateTo(`/bookings/${data.id}`)
  }
  catch (e) {
    if (!(e instanceof ApiError)) throw e
    submitError.value = e.message
    fieldErrors.value = e.fieldErrors
    if (e.code === 'PRICE_CHANGED') {
      // re-check so the agent sees and explicitly accepts the new price
      acceptedNewPrice.value = false
      check.value = await api.post<PriceCheck>(`/partner/flights/offers/${offerId}/price-check`)
      offer.value = check.value.offer
    }
    if (e.status !== 0) idempotencyKey = newIdempotencyKey() // a definitive answer: next attempt is a new request
  }
  finally {
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('book.title')">
      <template #actions>
        <UButton to="/flights" color="neutral" variant="ghost" icon="i-lucide-arrow-right" :label="t('common.back')" />
      </template>
    </PageHeader>

    <UAlert v-if="checking" color="info" variant="subtle" icon="i-lucide-loader-circle" :description="t('book.priceCheck')" class="mb-4" />
    <UAlert v-if="checkError" color="error" variant="subtle" icon="i-lucide-circle-alert" :description="checkError" class="mb-4">
      <template #actions>
        <UButton to="/flights" :label="t('nav.search')" />
      </template>
    </UAlert>

    <template v-if="offer">
      <TestDataBanner v-if="offer.is_test" class="mb-4" />

      <div class="grid gap-6 lg:grid-cols-3">
        <form class="space-y-6 lg:col-span-2" data-testid="booking-form" @submit.prevent="submit">
          <PassengerFields
            v-for="(p, i) in passengers"
            :key="i"
            v-model="passengers[i]!"
            :index="i"
            :travelers="travelers"
            :errors="fieldErrors"
          />

          <UCard>
            <template #header>
              <h3 class="font-semibold">
                {{ t('book.contact') }}
              </h3>
            </template>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField :label="t('common.email')" required :error="fieldErrors['contact.email']">
                <UInput v-model="contact.email" type="email" dir="ltr" class="w-full" required data-testid="contact-email" />
              </UFormField>
              <UFormField :label="t('common.phone')" :error="fieldErrors['contact.phone']">
                <UInput v-model="contact.phone" dir="ltr" placeholder="+964…" class="w-full" />
              </UFormField>
            </div>
            <UCheckbox v-if="can('travelers.manage')" v-model="saveTravelers" :label="t('book.saveTravelers')" class="mt-4" />
          </UCard>

          <UAlert v-if="submitError" color="error" variant="subtle" icon="i-lucide-circle-alert" :description="submitError" data-testid="booking-error" />
        </form>

        <div class="space-y-4">
          <UCard class="lg:sticky lg:top-20">
            <template #header>
              <h3 class="font-semibold">
                {{ t('book.summary') }}
              </h3>
            </template>
            <ItineraryView :slices="offer.itinerary" :airline-names="offer.validating_airline && offer.validating_airline_name ? { [offer.validating_airline]: offer.validating_airline_name } : {}" />
            <USeparator class="my-4" />
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between">
                <dt class="text-(--ui-text-muted)">
                  {{ t('offer.baggage') }}
                </dt>
                <dd class="ltr">
                  {{ offer.baggage?.checked ?? '—' }} / {{ offer.baggage?.cabin ?? '—' }}
                </dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-(--ui-text-muted)">
                  {{ t('offer.commission') }}
                </dt>
                <dd><MoneyText :amount="offer.price.commission" :currency="offer.price.currency" /></dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-(--ui-text-muted)">
                  {{ t('offer.net') }}
                </dt>
                <dd><MoneyText :amount="offer.price.agency_net" :currency="offer.price.currency" /></dd>
              </div>
              <div class="flex justify-between text-base font-semibold">
                <dt>{{ t('offer.total') }}</dt>
                <dd data-testid="summary-total">
                  <MoneyText :amount="offer.price.total" :currency="offer.price.currency" />
                </dd>
              </div>
            </dl>

            <UAlert
              v-if="check?.price_changed"
              color="warning"
              variant="subtle"
              icon="i-lucide-triangle-alert"
              class="mt-4"
              :description="t('book.priceChanged', { old: `${check.previous_total.amount} ${check.previous_total.currency}`, new: `${check.current_total.amount} ${check.current_total.currency}` })"
            />
            <UCheckbox v-if="check?.price_changed" v-model="acceptedNewPrice" :label="t('book.acceptPrice')" class="mt-3" />

            <p class="mt-4 text-xs text-(--ui-text-muted)">
              {{ t('book.holdNote') }}
            </p>
            <UButton
              block
              size="lg"
              class="mt-4"
              icon="i-lucide-lock"
              :label="t('book.submitHold')"
              :loading="submitting"
              :disabled="!canSubmit"
              data-testid="booking-submit"
              @click="submit"
            />
          </UCard>
        </div>
      </div>
    </template>
  </div>
</template>
