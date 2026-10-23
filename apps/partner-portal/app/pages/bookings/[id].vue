<script setup lang="ts">
import { formatDate, formatMoney, newIdempotencyKey } from '@etijahfly/api-contracts'
import type { Booking } from '@etijahfly/api-contracts'
import { ApiError } from '~/utils/api-error'

/** The detail endpoint always loads these relations. */
type BookingDetail = Booking & Required<Pick<Booking, 'passengers' | 'events' | 'documents' | 'refunds'>>

const { t, locale } = useI18n()
const { can } = useAuth()
const api = useApi()
const toast = useToast()
const route = useRoute()
const id = Number(route.params.id)

const { data, refresh, error } = await useAsyncData(`booking-${id}`, async () => (await api.get<{ data: BookingDetail }>(`/partner/bookings/${id}`)).data)
const booking = computed(() => data.value)
useHead({ title: () => booking.value?.reference ?? t('nav.bookings') })

// Poll while the provider outcome is still open.
const polling = computed(() => booking.value && (booking.value.status === 'pending' || booking.value.ticketing_status === 'pending_verification' || booking.value.status === 'refund_pending'))
let interval: ReturnType<typeof setInterval> | undefined
watch(polling, (on) => {
  clearInterval(interval)
  if (on) interval = setInterval(refresh, 10000)
}, { immediate: true })
onBeforeUnmount(() => clearInterval(interval))

type Action = 'issue' | 'cancel' | 'refund'
const dialog = ref<Action | null>(null)
const reason = ref('')
const busy = ref(false)
const actionError = ref<string | null>(null)
let actionKey = newIdempotencyKey()

function open(action: Action) {
  dialog.value = action
  reason.value = ''
  actionError.value = null
  actionKey = newIdempotencyKey()
}

async function runAction() {
  if (!dialog.value || !booking.value) return
  busy.value = true
  actionError.value = null
  const action = dialog.value
  try {
    if (action === 'issue') await api.post(`/partner/bookings/${id}/issue`, undefined, { idempotent: actionKey })
    if (action === 'cancel') await api.post(`/partner/bookings/${id}/cancel`, { reason: reason.value })
    if (action === 'refund') await api.post(`/partner/bookings/${id}/refund`, { reason: reason.value }, { idempotent: actionKey })
    dialog.value = null
    await refresh()
    const title = { issue: booking.value?.status === 'ticketed' ? t('bookings.issued') : t(`status.${booking.value?.status}`), cancel: t('bookings.cancelled'), refund: t('bookings.refundRequested') }[action]
    toast.add({ title, color: booking.value?.status === 'failed' ? 'error' : 'success' })
  }
  catch (e) {
    actionError.value = e instanceof ApiError ? e.message : t('common.error')
  }
  finally {
    busy.value = false
  }
}

const generating = ref<string | null>(null)
async function generate(type: 'booking_confirmation' | 'e_ticket') {
  generating.value = type
  try {
    await api.post(`/partner/bookings/${id}/documents/${type}`)
    await refresh()
  }
  catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('common.error'), color: 'error' })
  }
  finally {
    generating.value = null
  }
}

function print() {
  window.print()
}

function docUrl(documentId: number) {
  return `/api/proxy/partner/bookings/${id}/documents/${documentId}/file`
}

const latestDoc = (type: string) => booking.value?.documents.find(d => d.type === type)
const isTicketed = computed(() => ['ticketed', 'refund_pending', 'refunded'].includes(booking.value?.status ?? ''))
const chargeAmount = computed(() => booking.value ? formatMoney(booking.value.price.agency_net, booking.value.price.currency, locale.value) : '')
</script>

<template>
  <div>
    <UAlert v-if="error" color="error" variant="subtle" :description="t('common.error')" class="print:hidden" />

    <template v-if="booking">
      <PageHeader :title="booking.reference" :description="booking.route" class="print:hidden">
        <template #actions>
          <StatusBadge :status="booking.status" class="text-sm" />
          <UButton color="neutral" variant="outline" icon="i-lucide-printer" :label="t('bookings.printTicket')" @click="print" />
          <UButton v-if="booking.actions?.can_issue" icon="i-lucide-ticket-check" :label="t('bookings.issue')" data-testid="issue-button" @click="open('issue')" />
          <UButton v-if="booking.actions?.can_cancel" color="error" variant="soft" icon="i-lucide-x-circle" :label="t('bookings.cancel')" data-testid="cancel-button" @click="open('cancel')" />
          <UButton v-if="booking.actions?.can_refund" color="warning" variant="soft" icon="i-lucide-undo-2" :label="t('bookings.refund')" @click="open('refund')" />
        </template>
      </PageHeader>

      <TestDataBanner v-if="booking.is_test" class="mb-4 print:hidden" />
      <UAlert v-if="booking.ticketing_status === 'pending_verification'" color="info" variant="subtle" icon="i-lucide-loader-circle" class="mb-4 print:hidden" :description="t('bookings.pendingVerification')" />
      <UAlert v-if="booking.ticketing_status === 'needs_attention'" color="warning" variant="subtle" icon="i-lucide-life-buoy" class="mb-4 print:hidden" :description="t('bookings.needsAttention')" />
      <UAlert v-if="booking.failure" color="error" variant="subtle" icon="i-lucide-circle-alert" class="mb-4 print:hidden" :title="t('bookings.failure')" :description="booking.failure.reason ?? booking.failure.code ?? ''" />

      <ETicket :booking="booking" class="mb-8" />

      <div class="grid gap-6 lg:grid-cols-3 print:hidden">
        <div class="space-y-6 lg:col-span-2">
          <UCard>
            <template #header>
              <h3 class="font-semibold">
                {{ t('bookings.timeline') }}
              </h3>
            </template>
            <ol class="relative space-y-4 border-s border-(--ui-border) ps-5" data-testid="timeline">
              <li v-for="event in booking.events" :key="event.id">
                <span class="absolute -start-1.5 mt-1.5 size-3 rounded-full border-2 border-(--ui-bg) bg-(--ui-primary)" />
                <p class="text-sm">
                  {{ event.message }}
                </p>
                <p class="text-xs text-(--ui-text-muted)">
                  {{ formatDate(event.created_at, locale, true) }} · {{ event.actor?.name ?? event.actor_type }}
                </p>
              </li>
            </ol>
          </UCard>
        </div>

        <div class="space-y-6">
          <UCard>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between">
                <dt class="text-(--ui-text-muted)">
                  {{ t('bookings.createdBy') }}
                </dt>
                <dd>{{ booking.created_by?.name ?? '—' }}</dd>
              </div>
              <USeparator />
              <div class="flex justify-between">
                <dt class="text-(--ui-text-muted)">
                  {{ t('offer.total') }}
                </dt>
                <dd class="font-semibold">
                  <MoneyText :amount="booking.price.total" :currency="booking.price.currency" />
                </dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-(--ui-text-muted)">
                  {{ t('offer.commission') }}
                </dt>
                <dd><MoneyText :amount="booking.price.commission" :currency="booking.price.currency" /></dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-(--ui-text-muted)">
                  {{ t('offer.net') }}
                </dt>
                <dd><MoneyText :amount="booking.price.agency_net" :currency="booking.price.currency" /></dd>
              </div>
              <template v-if="booking.settlement.charged_amount">
                <USeparator />
                <div class="flex justify-between">
                  <dt class="text-(--ui-text-muted)">
                    {{ t('bookings.charged') }}
                  </dt>
                  <dd><MoneyText :amount="booking.settlement.charged_amount" :currency="booking.settlement.account_currency" /></dd>
                </div>
                <div v-if="booking.settlement.account_currency !== booking.price.currency" class="flex justify-between">
                  <dt class="text-(--ui-text-muted)">
                    {{ t('bookings.fxRate') }}
                  </dt>
                  <dd class="ltr">
                    1 {{ booking.price.currency }} = {{ Number(booking.settlement.fx_rate) }} {{ booking.settlement.account_currency }}
                  </dd>
                </div>
              </template>
            </dl>
          </UCard>

          <UCard v-if="can('documents.view')">
            <template #header>
              <h3 class="font-semibold">
                {{ t('bookings.documents') }}
              </h3>
            </template>
            <div class="space-y-3">
              <div class="flex items-center justify-between gap-2">
                <span class="text-sm">{{ t('bookings.confirmationDoc') }}</span>
                <div class="flex gap-1">
                  <UButton v-if="latestDoc('booking_confirmation')" :to="docUrl(latestDoc('booking_confirmation')!.id)" target="_blank" external size="sm" variant="soft" icon="i-lucide-download" :label="t('common.open')" />
                  <UButton size="sm" variant="ghost" icon="i-lucide-refresh-cw" :loading="generating === 'booking_confirmation'" :aria-label="t('bookings.generate')" data-testid="generate-confirmation" @click="generate('booking_confirmation')" />
                </div>
              </div>
              <div class="flex items-center justify-between gap-2">
                <span class="text-sm">{{ t('bookings.eticketDoc') }}</span>
                <div v-if="isTicketed" class="flex gap-1">
                  <UButton v-if="latestDoc('e_ticket')" :to="docUrl(latestDoc('e_ticket')!.id)" target="_blank" external size="sm" variant="soft" icon="i-lucide-download" :label="t('common.open')" data-testid="open-eticket" />
                  <UButton size="sm" variant="ghost" icon="i-lucide-refresh-cw" :loading="generating === 'e_ticket'" :aria-label="t('bookings.generate')" @click="generate('e_ticket')" />
                </div>
                <span v-else class="text-xs text-(--ui-text-muted)">—</span>
              </div>
              <p v-if="!isTicketed" class="text-xs text-(--ui-text-muted)">
                {{ t('bookings.noTicketYet') }}
              </p>
            </div>
          </UCard>

          <UCard>
            <template #header>
              <h3 class="font-semibold">
                {{ t('bookings.contact') }}
              </h3>
            </template>
            <p class="ltr text-sm">
              {{ booking.contact.email }}
            </p>
            <p v-if="booking.contact.phone" class="ltr text-sm">
              {{ booking.contact.phone }}
            </p>
          </UCard>

          <UCard v-if="booking.refunds.length">
            <template #header>
              <h3 class="font-semibold">
                {{ t('bookings.refunds') }}
              </h3>
            </template>
            <ul class="space-y-2 text-sm">
              <li v-for="r in booking.refunds" :key="r.id" class="flex items-center justify-between">
                <StatusBadge :status="r.status" />
                <MoneyText v-if="r.agency_refund_amount" :amount="r.agency_refund_amount" :currency="r.account_currency" />
              </li>
            </ul>
          </UCard>
        </div>
      </div>

      <UModal :open="dialog !== null" :title="dialog ? t(`bookings.${dialog}`) : ''" @update:open="(v: boolean) => { if (!v) dialog = null }">
        <template #body>
          <div class="space-y-4">
            <p v-if="dialog === 'issue'" class="text-sm">
              {{ t('bookings.issueConfirm', { amount: chargeAmount }) }}
            </p>
            <p v-if="dialog === 'cancel'" class="text-sm">
              {{ t('bookings.cancelConfirm') }}
            </p>
            <p v-if="dialog === 'refund'" class="text-sm">
              {{ t('bookings.refundConfirm') }}
            </p>
            <UFormField v-if="dialog !== 'issue'" :label="t('common.reason')" required>
              <UTextarea v-model="reason" class="w-full" :rows="3" data-testid="action-reason" />
            </UFormField>
            <UAlert v-if="actionError" color="error" variant="subtle" :description="actionError" data-testid="action-error" />
          </div>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton color="neutral" variant="ghost" :label="t('common.cancel')" @click="dialog = null" />
            <UButton
              :color="dialog === 'issue' ? 'primary' : dialog === 'cancel' ? 'error' : 'warning'"
              :label="t('common.confirm')"
              :loading="busy"
              :disabled="dialog !== 'issue' && !reason.trim()"
              data-testid="confirm-action"
              @click="runAction"
            />
          </div>
        </template>
      </UModal>
    </template>
  </div>
</template>
