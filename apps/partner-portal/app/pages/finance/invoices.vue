<script setup lang="ts">
import { formatDate } from '@etijahfly/api-contracts'
import type { Invoice, Paginated } from '@etijahfly/api-contracts'
import type { TableColumn } from '@nuxt/ui'

definePageMeta({ permission: 'invoices.view' })

const { t, locale } = useI18n()
const api = useApi()
useHead({ title: t('nav.invoices') })

const page = ref(1)
const { data, pending } = await useAsyncData('invoices', () => api.get<Paginated<Invoice>>('/partner/finance/invoices', { page: page.value }), { watch: [page] })

const columns: TableColumn<Invoice>[] = [
  { accessorKey: 'number', header: () => t('invoices.number') },
  { accessorKey: 'type', header: () => t('invoices.type') },
  { accessorKey: 'booking_reference', header: () => t('invoices.booking') },
  { accessorKey: 'total', header: () => t('common.amount') },
  { accessorKey: 'issued_at', header: () => t('common.date') },
  { accessorKey: 'status', header: () => t('common.status') },
  { accessorKey: 'file', header: '' },
]
</script>

<template>
  <div>
    <PageHeader :title="t('invoices.title')" />
    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <UTable :data="data?.data ?? []" :columns="columns" :loading="pending" :empty="t('common.empty')">
        <template #number-cell="{ row }">
          <span class="ltr font-mono text-sm">{{ row.original.number }}</span>
        </template>
        <template #type-cell="{ row }">
          <UBadge :color="row.original.type === 'credit_note' ? 'info' : 'neutral'" variant="subtle" :label="t(`invoices.${row.original.type}`)" />
        </template>
        <template #booking_reference-cell="{ row }">
          <NuxtLink v-if="row.original.booking_id" :to="`/bookings/${row.original.booking_id}`" class="ltr text-(--ui-primary)">
            {{ row.original.booking_reference }}
          </NuxtLink>
        </template>
        <template #total-cell="{ row }">
          <MoneyText :amount="row.original.total" :currency="row.original.currency" />
        </template>
        <template #issued_at-cell="{ row }">
          {{ formatDate(row.original.issued_at, locale) }}
        </template>
        <template #status-cell="{ row }">
          <StatusBadge :status="row.original.status" />
        </template>
        <template #file-cell="{ row }">
          <UButton :to="`/api/proxy/partner/finance/invoices/${row.original.id}/file`" external target="_blank" size="sm" variant="ghost" icon="i-lucide-file-down" :aria-label="t('common.download')" />
        </template>
      </UTable>
    </UCard>
    <div v-if="(data?.meta.last_page ?? 1) > 1" class="mt-4 flex justify-center">
      <UPagination v-model:page="page" :total="data?.meta.total ?? 0" :items-per-page="data?.meta.per_page ?? 25" />
    </div>
  </div>
</template>
