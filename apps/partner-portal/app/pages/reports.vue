<script setup lang="ts">
definePageMeta({ permission: 'reports.view' })

interface Summary {
  period: { from: string, to: string }
  bookings_by_status: Record<string, number>
  sales_by_currency: { currency: string, bookings: number, sales: string, commission: string, agency_net: string }[]
  refunded_bookings: number
  top_airlines: { airline: string, currency: string, bookings: number, sales: string }[]
  daily: { day: string, currency: string, bookings: number, sales: string }[]
}

const { t } = useI18n()
const api = useApi()
useHead({ title: t('nav.reports') })

const range = reactive({ from: new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) })
const { data, pending, refresh } = await useAsyncData('report-summary', () => api.get<Summary>('/partner/reports/summary', { ...range }))

const maxDaily = computed(() => Math.max(1, ...(data.value?.daily ?? []).map(d => Number(d.sales))))
</script>

<template>
  <div>
    <PageHeader :title="t('reports.title')">
      <template #actions>
        <UInput v-model="range.from" type="date" />
        <UInput v-model="range.to" type="date" />
        <UButton icon="i-lucide-filter" :loading="pending" :label="t('reports.apply')" @click="refresh()" />
      </template>
    </PageHeader>

    <template v-if="data">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <template v-for="row in data.sales_by_currency" :key="row.currency">
          <StatCard :label="`${t('reports.sales')} · ${row.currency}`" icon="i-lucide-trending-up" tone="success">
            <MoneyText :amount="row.sales" :currency="row.currency" />
            <template #footer>
              <p class="mt-2 text-xs text-(--ui-text-muted)">
                {{ row.bookings }} {{ t('reports.bookings') }}
              </p>
            </template>
          </StatCard>
          <StatCard :label="`${t('reports.commission')} · ${row.currency}`" icon="i-lucide-percent">
            <MoneyText :amount="row.commission" :currency="row.currency" />
          </StatCard>
          <StatCard :label="`${t('reports.net')} · ${row.currency}`" icon="i-lucide-scale" tone="neutral">
            <MoneyText :amount="row.agency_net" :currency="row.currency" />
          </StatCard>
        </template>
        <UEmpty v-if="!data.sales_by_currency.length" class="sm:col-span-3" icon="i-lucide-chart-column" :title="t('common.empty')" />
      </div>

      <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <UCard>
          <template #header>
            <h3 class="font-semibold">
              {{ t('reports.byStatus') }}
            </h3>
          </template>
          <ul class="space-y-2">
            <li v-for="(count, status) in data.bookings_by_status" :key="status" class="flex items-center justify-between">
              <StatusBadge :status="String(status)" />
              <span class="tabular-nums">{{ count }}</span>
            </li>
          </ul>
        </UCard>
        <UCard>
          <template #header>
            <h3 class="font-semibold">
              {{ t('reports.topAirlines') }}
            </h3>
          </template>
          <ul class="space-y-2">
            <li v-for="row in data.top_airlines" :key="row.airline + row.currency" class="flex items-center justify-between">
              <span class="ltr font-medium">{{ row.airline }}</span>
              <span class="text-sm">{{ row.bookings }} · <MoneyText :amount="row.sales" :currency="row.currency" /></span>
            </li>
          </ul>
        </UCard>
      </div>

      <UCard class="mt-6">
        <template #header>
          <h3 class="font-semibold">
            {{ t('reports.daily') }}
          </h3>
        </template>
        <div v-if="data.daily.length" class="flex h-48 items-end gap-1" role="img" :aria-label="t('reports.daily')">
          <div v-for="d in data.daily" :key="d.day + d.currency" class="flex flex-1 flex-col items-center gap-1" :title="`${d.day}: ${d.sales} ${d.currency}`">
            <div class="w-full rounded-t bg-(--ui-primary)/80" :style="{ height: `${(Number(d.sales) / maxDaily) * 100}%` }" />
            <span class="ltr text-[10px] text-(--ui-text-dimmed)">{{ d.day.slice(5) }}</span>
          </div>
        </div>
        <p v-else class="text-sm text-(--ui-text-muted)">
          {{ t('common.empty') }}
        </p>
      </UCard>
    </template>
  </div>
</template>
