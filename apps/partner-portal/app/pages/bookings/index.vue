<script setup lang="ts">
import { BOOKING_STATUSES, formatDate } from '@etijahfly/api-contracts'
import type { Booking, BookingStatus, Paginated } from '@etijahfly/api-contracts'
import type { TableColumn } from '@nuxt/ui'

definePageMeta({ permission: ['bookings.view', 'bookings.view_all'] })

const { t, locale } = useI18n()
const api = useApi()

useHead({ title: t('nav.bookings') })

const filters = reactive({ q: '', status: undefined as BookingStatus | undefined, page: 1 })
const statusItems = computed(() => [{ label: t('common.all'), value: undefined }, ...BOOKING_STATUSES.map(s => ({ label: t(`status.${s}`), value: s }))])

const { data, pending, refresh } = await useAsyncData('bookings', () => api.get<Paginated<Booking>>('/partner/bookings', {
  q: filters.q || undefined,
  status: filters.status,
  page: filters.page,
}), { watch: [() => filters.status, () => filters.page] })

let timer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    filters.page = 1
    refresh()
  }, 300)
})

const columns: TableColumn<Booking>[] = [
  { accessorKey: 'reference', header: () => t('bookings.reference') },
  { accessorKey: 'route', header: () => t('bookings.route') },
  { accessorKey: 'pnr', header: () => t('bookings.pnr') },
  { accessorKey: 'status', header: () => t('common.status') },
  { accessorKey: 'price', header: () => t('bookings.total') },
  { accessorKey: 'created_at', header: () => t('bookings.createdAt') },
]
</script>

<template>
  <div>
    <PageHeader :title="t('bookings.title')" />

    <div class="mb-4 flex flex-wrap gap-3">
      <UInput v-model="filters.q" icon="i-lucide-search" :placeholder="t('bookings.searchPlaceholder')" class="w-72" />
      <USelect v-model="filters.status" :items="statusItems" class="w-56" :placeholder="t('common.status')" />
    </div>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <UTable :data="data?.data ?? []" :columns="columns" :loading="pending" :empty="t('common.empty')" data-testid="bookings-table">
        <template #reference-cell="{ row }">
          <NuxtLink :to="`/bookings/${row.original.id}`" class="ltr font-medium text-(--ui-primary)">
            {{ row.original.reference }}
          </NuxtLink>
          <UBadge v-if="row.original.is_test" color="warning" variant="soft" size="sm" label="TEST" class="ms-2" />
        </template>
        <template #route-cell="{ row }">
          <span class="ltr">{{ row.original.route }}</span>
        </template>
        <template #pnr-cell="{ row }">
          <span class="ltr font-mono">{{ row.original.pnr ?? '—' }}</span>
        </template>
        <template #status-cell="{ row }">
          <StatusBadge :status="row.original.status" />
        </template>
        <template #price-cell="{ row }">
          <MoneyText :amount="row.original.price.total" :currency="row.original.price.currency" />
        </template>
        <template #created_at-cell="{ row }">
          {{ formatDate(row.original.created_at, locale, true) }}
        </template>
      </UTable>
    </UCard>

    <div v-if="(data?.meta.last_page ?? 1) > 1" class="mt-4 flex justify-center">
      <UPagination v-model:page="filters.page" :total="data?.meta.total ?? 0" :items-per-page="data?.meta.per_page ?? 20" />
    </div>
  </div>
</template>
