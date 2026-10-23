<script setup lang="ts">
import { formatDate } from '@etijahfly/api-contracts'
import type { TableColumn } from '@nuxt/ui'

definePageMeta({ permission: 'commissions.view' })

interface CommissionRow {
  booking_id: number
  reference: string
  status: string
  currency: string
  sale_total: string
  commission: string
  account_currency: string
  commission_credited: string | null
  clawed_back: boolean
  date: string
}

const { t, locale } = useI18n()
const api = useApi()
useHead({ title: t('nav.commissions') })

const range = reactive({ from: new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) })
const { data, pending, refresh } = await useAsyncData('commissions', () => api.get<{ data: CommissionRow[] }>('/partner/finance/commissions', { ...range }))

const columns: TableColumn<CommissionRow>[] = [
  { accessorKey: 'reference', header: () => t('bookings.reference') },
  { accessorKey: 'date', header: () => t('common.date') },
  { accessorKey: 'sale_total', header: () => t('bookings.total') },
  { accessorKey: 'commission', header: () => t('commissions.commission') },
  { accessorKey: 'commission_credited', header: () => t('commissions.credited') },
  { accessorKey: 'status', header: () => t('common.status') },
]
</script>

<template>
  <div>
    <PageHeader :title="t('commissions.title')">
      <template #actions>
        <UInput v-model="range.from" type="date" />
        <UInput v-model="range.to" type="date" />
        <UButton icon="i-lucide-filter" :label="t('reports.apply')" @click="refresh()" />
      </template>
    </PageHeader>
    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <UTable :data="data?.data ?? []" :columns="columns" :loading="pending" :empty="t('common.empty')">
        <template #reference-cell="{ row }">
          <NuxtLink :to="`/bookings/${row.original.booking_id}`" class="ltr text-(--ui-primary)">
            {{ row.original.reference }}
          </NuxtLink>
        </template>
        <template #date-cell="{ row }">
          {{ formatDate(row.original.date, locale) }}
        </template>
        <template #sale_total-cell="{ row }">
          <MoneyText :amount="row.original.sale_total" :currency="row.original.currency" />
        </template>
        <template #commission-cell="{ row }">
          <MoneyText :amount="row.original.commission" :currency="row.original.currency" />
        </template>
        <template #commission_credited-cell="{ row }">
          <MoneyText :amount="row.original.commission_credited" :currency="row.original.account_currency" :class="row.original.clawed_back ? 'line-through opacity-60' : ''" />
          <UBadge v-if="row.original.clawed_back" color="warning" variant="subtle" size="sm" :label="t('commissions.clawedBack')" class="ms-2" />
        </template>
        <template #status-cell="{ row }">
          <StatusBadge :status="row.original.status" />
        </template>
      </UTable>
    </UCard>
  </div>
</template>
