<script setup lang="ts">
import { formatDate, formatDuration } from '@etijahfly/api-contracts'
import type { Booking, LedgerAccount } from '@etijahfly/api-contracts'

interface Dashboard {
  account: { data: LedgerAccount } | LedgerAccount | null
  counts: Record<string, number>
  expiring_holds: { data: Booking[] } | Booking[]
  recent: { data: Booking[] } | Booking[]
  unread_notifications: number
}

const { t, locale } = useI18n()
const { me, can } = useAuth()
const api = useApi()

useHead({ title: t('nav.dashboard') })

const { data, pending } = await useAsyncData('dashboard', () => api.get<Dashboard>('/partner/dashboard'))

const unwrap = <T,>(v: { data: T } | T | null | undefined): T | null => (v && typeof v === 'object' && 'data' in (v as object) ? (v as { data: T }).data : (v as T)) ?? null
const account = computed(() => unwrap<LedgerAccount>(data.value?.account))
const holds = computed(() => unwrap<Booking[]>(data.value?.expiring_holds) ?? [])
const recent = computed(() => unwrap<Booking[]>(data.value?.recent) ?? [])
const totalBookings = computed(() => Object.values(data.value?.counts ?? {}).reduce((a, b) => a + Number(b), 0))

// Holds count down live; under an hour left is flagged as urgent.
const now = ref(Date.now())
let tick: ReturnType<typeof setInterval> | undefined
onMounted(() => { tick = setInterval(() => (now.value = Date.now()), 30_000) })
onBeforeUnmount(() => clearInterval(tick))
const minutesLeft = (iso: string | null | undefined) => (iso ? Math.floor((new Date(iso).getTime() - now.value) / 60_000) : 0)
</script>

<template>
  <div>
    <PageHeader :title="t('dashboard.welcome', { name: me?.name ?? '' })" :description="me?.tenant?.name">
      <template #actions>
        <UButton v-if="can('flights.search')" to="/flights" icon="i-lucide-plane-takeoff" :label="t('dashboard.newSearch')" size="lg" />
      </template>
    </PageHeader>

    <USkeleton v-if="pending" class="h-36 rounded-[0.625rem]" />

    <template v-else>
      <section class="ef-sheet grid divide-y divide-(--ui-border) md:grid-cols-2 md:divide-y-0 lg:grid-cols-[1.5fr_1fr_1fr_1.25fr] lg:divide-x">
        <template v-if="account">
          <div class="p-6 md:col-span-2 md:border-b md:border-(--ui-border) lg:col-span-1 lg:border-b-0">
            <p class="text-sm text-(--ui-text-muted)">
              {{ t('dashboard.available') }}
            </p>
            <MoneyText
              :amount="account.available"
              :currency="account.currency"
              class="mt-2 block font-display text-4xl font-semibold leading-none"
              :class="Number(account.available) <= 0 && 'text-(--ui-error)'"
            />
          </div>
          <div class="p-6">
            <p class="text-sm text-(--ui-text-muted)">
              {{ t('dashboard.balance') }}
            </p>
            <MoneyText :amount="account.balance" :currency="account.currency" class="mt-2 block text-xl font-semibold" />
          </div>
          <div class="p-6 md:border-s md:border-(--ui-border) lg:border-s-0">
            <p class="text-sm text-(--ui-text-muted)">
              {{ t('dashboard.creditLimit') }}
            </p>
            <MoneyText :amount="account.credit_limit" :currency="account.currency" class="mt-2 block text-xl font-semibold" />
          </div>
        </template>
        <div class="p-6 md:col-span-2 lg:col-span-1" :class="account && 'md:border-t md:border-(--ui-border) lg:border-t-0'">
          <p class="text-sm text-(--ui-text-muted)">
            {{ t('dashboard.last30') }}
          </p>
          <p class="mt-2 text-xl font-semibold tabular-nums">
            {{ totalBookings }}
          </p>
          <div class="mt-3 flex flex-wrap gap-1">
            <StatusBadge v-for="(count, status) in data?.counts" :key="status" :status="String(status)" class="tabular-nums" />
          </div>
        </div>
      </section>

      <div class="mt-8 grid gap-8 lg:grid-cols-5">
        <section class="lg:col-span-2">
          <h2 class="mb-3 font-display text-lg font-semibold">
            {{ t('dashboard.expiringHolds') }}
          </h2>
          <div class="ef-sheet">
            <p v-if="!holds.length" class="p-5 text-sm text-(--ui-text-muted)">
              {{ t('dashboard.noHolds') }}
            </p>
            <ul v-else class="divide-y divide-(--ui-border)">
              <li v-for="b in holds" :key="b.id">
                <NuxtLink :to="`/bookings/${b.id}`" class="flex items-center gap-4 px-5 py-3.5 transition-colors hover:bg-(--ui-bg-muted)">
                  <div class="min-w-0 flex-1">
                    <p class="ltr font-display text-base font-semibold">
                      {{ b.route }}
                    </p>
                    <p class="ltr text-xs text-(--ui-text-muted)">
                      {{ b.reference }}
                    </p>
                  </div>
                  <span
                    class="shrink-0 rounded px-2 py-1 text-xs font-medium tabular-nums"
                    :class="minutesLeft(b.hold_expires_at) < 60 ? 'bg-(--ui-error)/10 text-(--ui-error)' : 'bg-(--ui-warning)/10 text-(--ui-warning)'"
                    :title="formatDate(b.hold_expires_at, locale, true)"
                  >
                    {{ minutesLeft(b.hold_expires_at) > 0 ? t('dashboard.expiresIn', { time: formatDuration(minutesLeft(b.hold_expires_at), locale) }) : t('dashboard.expired') }}
                  </span>
                </NuxtLink>
              </li>
            </ul>
          </div>
        </section>

        <section class="lg:col-span-3">
          <div class="mb-3 flex items-baseline justify-between gap-4">
            <h2 class="font-display text-lg font-semibold">
              {{ t('dashboard.recent') }}
            </h2>
            <ULink to="/bookings" class="text-sm font-medium text-(--ui-primary)">
              {{ t('dashboard.allBookings') }}
            </ULink>
          </div>
          <div class="ef-sheet">
            <p v-if="!recent.length" class="p-5 text-sm text-(--ui-text-muted)">
              {{ t('common.empty') }}
            </p>
            <ul v-else class="divide-y divide-(--ui-border)">
              <li v-for="b in recent" :key="b.id">
                <NuxtLink :to="`/bookings/${b.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition-colors hover:bg-(--ui-bg-muted)">
                  <div class="min-w-0 flex-1">
                    <p class="ltr font-display text-base font-semibold">
                      {{ b.route }}
                    </p>
                    <p class="ltr text-xs text-(--ui-text-muted)">
                      {{ b.reference }}
                    </p>
                  </div>
                  <MoneyText :amount="b.price.total" :currency="b.price.currency" class="text-sm font-medium" />
                  <StatusBadge :status="b.status" />
                </NuxtLink>
              </li>
            </ul>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>
