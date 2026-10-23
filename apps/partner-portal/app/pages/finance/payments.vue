<script setup lang="ts">
import { CURRENCIES, formatDate, newIdempotencyKey } from '@etijahfly/api-contracts'
import type { Currency, Paginated, Payment } from '@etijahfly/api-contracts'
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/utils/api-error'

definePageMeta({ permission: 'finance.view' })

const { t, locale } = useI18n()
const { me, can } = useAuth()
const api = useApi()
const toast = useToast()
useHead({ title: t('nav.payments') })

const page = ref(1)
const { data, pending, refresh } = await useAsyncData('payments', () => api.get<Paginated<Payment>>('/partner/finance/payments', { page: page.value }), { watch: [page] })

const open = ref(false)
const form = reactive({ amount: '', currency: (me.value?.tenant?.default_currency ?? 'IQD') as Currency, method: 'bank_transfer', paid_at: new Date().toISOString().slice(0, 10), external_reference: '', notes: '' })
const proof = ref<File | null>(null)
const errors = ref<Record<string, string>>({})
const saving = ref(false)
let key = newIdempotencyKey()

const methods = computed(() => ['bank_transfer', 'cash', 'cheque'].map(v => ({ label: t(`payments.methods.${v}`), value: v })))

function onFile(e: Event) {
  proof.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function submit() {
  saving.value = true
  errors.value = {}
  const body = new FormData()
  Object.entries(form).forEach(([k, v]) => v !== '' && body.append(k, String(v)))
  if (proof.value) body.append('proof', proof.value)
  try {
    await api.post('/partner/finance/payments', body, { idempotent: key })
    open.value = false
    key = newIdempotencyKey()
    toast.add({ title: t('payments.submitted'), color: 'success' })
    await refresh()
  }
  catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.fieldErrors
      if (e.status !== 0) key = newIdempotencyKey()
    }
  }
  finally {
    saving.value = false
  }
}

const columns: TableColumn<Payment>[] = [
  { accessorKey: 'reference', header: () => t('payments.reference') },
  { accessorKey: 'amount', header: () => t('common.amount') },
  { accessorKey: 'method', header: () => t('payments.method') },
  { accessorKey: 'paid_at', header: () => t('payments.paidAt') },
  { accessorKey: 'status', header: () => t('common.status') },
  { accessorKey: 'review_notes', header: () => t('payments.reviewNotes') },
]
</script>

<template>
  <div>
    <PageHeader :title="t('payments.title')" :description="t('payments.pendingNote')">
      <template #actions>
        <UButton v-if="can('payments.submit')" icon="i-lucide-plus" :label="t('payments.new')" @click="open = true" />
      </template>
    </PageHeader>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <UTable :data="data?.data ?? []" :columns="columns" :loading="pending" :empty="t('common.empty')">
        <template #reference-cell="{ row }">
          <span class="ltr font-mono text-sm">{{ row.original.reference }}</span>
        </template>
        <template #amount-cell="{ row }">
          <MoneyText :amount="row.original.amount" :currency="row.original.currency" />
        </template>
        <template #method-cell="{ row }">
          {{ t(`payments.methods.${row.original.method}`) }}
        </template>
        <template #paid_at-cell="{ row }">
          {{ formatDate(row.original.paid_at, locale) }}
        </template>
        <template #status-cell="{ row }">
          <StatusBadge :status="row.original.status" />
        </template>
      </UTable>
    </UCard>
    <div v-if="(data?.meta.last_page ?? 1) > 1" class="mt-4 flex justify-center">
      <UPagination v-model:page="page" :total="data?.meta.total ?? 0" :items-per-page="data?.meta.per_page ?? 20" />
    </div>

    <UModal v-model:open="open" :title="t('payments.new')">
      <template #body>
        <form class="space-y-4" @submit.prevent="submit">
          <div class="grid grid-cols-2 gap-3">
            <UFormField :label="t('common.amount')" :error="errors.amount" required>
              <UInput v-model="form.amount" inputmode="decimal" dir="ltr" class="w-full" />
            </UFormField>
            <UFormField :label="t('common.currency')" :error="errors.currency">
              <USelect v-model="form.currency" :items="[...CURRENCIES]" class="w-full" />
            </UFormField>
            <UFormField :label="t('payments.method')" :error="errors.method">
              <USelect v-model="form.method" :items="methods" class="w-full" />
            </UFormField>
            <UFormField :label="t('payments.paidAt')" :error="errors.paid_at">
              <UInput v-model="form.paid_at" type="date" class="w-full" />
            </UFormField>
          </div>
          <UFormField :label="t('payments.externalReference')" :error="errors.external_reference">
            <UInput v-model="form.external_reference" dir="ltr" class="w-full" />
          </UFormField>
          <UFormField :label="t('payments.proof')" :error="errors.proof">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm" @change="onFile">
          </UFormField>
          <UFormField :label="t('common.notes')">
            <UTextarea v-model="form.notes" class="w-full" />
          </UFormField>
          <UButton type="submit" block :loading="saving" :label="t('common.save')" />
        </form>
      </template>
    </UModal>
  </div>
</template>
