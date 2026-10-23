<script setup lang="ts">
import { formatDate } from '@etijahfly/api-contracts'
import type { LedgerAccount } from '@etijahfly/api-contracts'
import type { TableColumn } from '@nuxt/ui'

definePageMeta({ permission: 'finance.view' })

interface Entry { id: number, date: string, type: string, description: string, debit: string | null, credit: string | null, balance: string }
interface Statement {
  account: { data: LedgerAccount } | LedgerAccount
  from: string
  to: string
  opening_balance: string
  closing_balance: string
  total_debits: string
  total_credits: string
  entries: Entry[]
}

const { t, locale } = useI18n()
const api = useApi()
useHead({ title: t('nav.finance') })

const { data: accounts } = await useAsyncData('finance-account', () => api.get<{ default_currency: string, accounts: { data: LedgerAccount[] } | LedgerAccount[] }>('/partner/finance/account'))
const accountList = computed<LedgerAccount[]>(() => {
  const a = accounts.value?.accounts
  return (a && 'data' in a ? a.data : a) ?? []
})

const today = new Date().toISOString().slice(0, 10)
const monthStart = today.slice(0, 8) + '01'
const range = reactive({ from: monthStart, to: today, currency: undefined as string | undefined })
watch(accounts, v => (range.currency ??= v?.default_currency), { immediate: true })

const { data: statement, pending, refresh } = await useAsyncData('statement', () => api.get<Statement>('/partner/finance/statement', { ...range }), { watch: [() => range.currency] })
const currency = computed(() => range.currency ?? 'USD')
const csvUrl = computed(() => `/api/proxy/partner/finance/statement?format=csv&from=${range.from}&to=${range.to}&currency=${currency.value}`)

const columns: TableColumn<Entry>[] = [
  { accessorKey: 'date', header: () => t('common.date') },
  { accessorKey: 'type', header: () => t('finance.type') },
  { accessorKey: 'description', header: () => t('finance.description') },
  { accessorKey: 'debit', header: () => t('finance.debit') },
  { accessorKey: 'credit', header: () => t('finance.credit') },
  { accessorKey: 'balance', header: () => t('finance.runningBalance') },
]
</script>

<template>
  <div>
    <PageHeader :title="t('finance.title')" />

    <div v-for="account in accountList" :key="account.id" class="mb-6">
      <UAlert v-if="account.status === 'frozen'" color="error" variant="subtle" icon="i-lucide-lock" class="mb-3" :description="t('finance.frozen')" />
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :label="`${t('finance.available')} · ${account.currency}`" icon="i-lucide-wallet" tone="success">
          <span data-testid="available"><MoneyText :amount="account.available" :currency="account.currency" /></span>
        </StatCard>
        <StatCard :label="t('finance.balance')" icon="i-lucide-landmark">
          <MoneyText :amount="account.balance" :currency="account.currency" />
        </StatCard>
        <StatCard :label="t('finance.creditLimit')" icon="i-lucide-credit-card" tone="neutral">
          <MoneyText :amount="account.credit_limit" :currency="account.currency" />
        </StatCard>
        <StatCard :label="t('finance.outstanding')" icon="i-lucide-receipt" :tone="Number(account.outstanding) > 0 ? 'warning' : 'neutral'">
          <MoneyText :amount="account.outstanding" :currency="account.currency" />
        </StatCard>
      </div>
    </div>

    <UCard>
      <template #header>
        <div class="flex flex-wrap items-end justify-between gap-3">
          <h2 class="font-semibold">
            {{ t('finance.statement') }}
          </h2>
          <div class="flex flex-wrap items-end gap-2">
            <UFormField :label="t('common.from')">
              <UInput v-model="range.from" type="date" />
            </UFormField>
            <UFormField :label="t('common.to')">
              <UInput v-model="range.to" type="date" />
            </UFormField>
            <USelect v-if="accountList.length > 1" v-model="range.currency" :items="accountList.map(a => a.currency)" class="w-24" />
            <UButton icon="i-lucide-filter" :label="t('reports.apply')" @click="refresh()" />
            <UButton :to="csvUrl" external target="_blank" color="neutral" variant="outline" icon="i-lucide-file-spreadsheet" :label="t('finance.exportCsv')" />
          </div>
        </div>
      </template>

      <div v-if="statement" class="mb-4 grid gap-3 text-sm sm:grid-cols-4">
        <div>
          <span class="text-(--ui-text-muted)">{{ t('finance.opening') }}:</span> <MoneyText :amount="statement.opening_balance" :currency="currency" />
        </div>
        <div>
          <span class="text-(--ui-text-muted)">{{ t('finance.debit') }}:</span> <MoneyText :amount="statement.total_debits" :currency="currency" />
        </div>
        <div>
          <span class="text-(--ui-text-muted)">{{ t('finance.credit') }}:</span> <MoneyText :amount="statement.total_credits" :currency="currency" />
        </div>
        <div class="font-semibold">
          <span class="text-(--ui-text-muted)">{{ t('finance.closing') }}:</span> <MoneyText :amount="statement.closing_balance" :currency="currency" />
        </div>
      </div>

      <UTable :data="statement?.entries ?? []" :columns="columns" :loading="pending" :empty="t('common.empty')">
        <template #date-cell="{ row }">
          {{ formatDate(row.original.date, locale, true) }}
        </template>
        <template #type-cell="{ row }">
          <UBadge color="neutral" variant="subtle" :label="t(`finance.types.${row.original.type}`)" />
        </template>
        <template #debit-cell="{ row }">
          <MoneyText v-if="row.original.debit" :amount="row.original.debit" :currency="currency" class="text-(--ui-error)" />
        </template>
        <template #credit-cell="{ row }">
          <MoneyText v-if="row.original.credit" :amount="row.original.credit" :currency="currency" class="text-(--ui-success)" />
        </template>
        <template #balance-cell="{ row }">
          <MoneyText :amount="row.original.balance" :currency="currency" class="font-medium" />
        </template>
      </UTable>
    </UCard>
  </div>
</template>
