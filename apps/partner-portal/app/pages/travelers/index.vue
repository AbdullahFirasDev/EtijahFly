<script setup lang="ts">
import type { Paginated, Traveler } from '@etijahfly/api-contracts'
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/utils/api-error'

definePageMeta({ permission: 'travelers.manage' })

const { t } = useI18n()
const api = useApi()
const toast = useToast()
useHead({ title: t('nav.travelers') })

const q = ref('')
const { data, pending, refresh } = await useAsyncData('travelers', () => api.get<Paginated<Traveler>>('/partner/travelers', { q: q.value || undefined }))
let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(refresh, 300)
})

const empty = () => ({ id: 0, title: 'MR', first_name: '', last_name: '', gender: 'M', date_of_birth: '', nationality: 'IQ', document_type: 'passport', document_number: '', document_expiry: '', document_country: 'IQ', email: '', phone: '', notes: '' })
const editing = ref<ReturnType<typeof empty> | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

async function edit(row?: Traveler) {
  errors.value = {}
  if (!row) {
    editing.value = empty()
    return
  }
  const { data: full } = await api.get<{ data: Traveler & { document_number?: string } }>(`/partner/travelers/${row.id}`)
  editing.value = { ...empty(), ...Object.fromEntries(Object.entries(full).map(([k, v]) => [k, v ?? ''])) } as ReturnType<typeof empty>
}

async function save() {
  if (!editing.value) return
  saving.value = true
  errors.value = {}
  const { id, ...payload } = editing.value
  const body = Object.fromEntries(Object.entries(payload).map(([k, v]) => [k, v === '' ? null : v]))
  try {
    if (id) await api.put(`/partner/travelers/${id}`, body)
    else await api.post('/partner/travelers', body)
    editing.value = null
    toast.add({ title: t('common.saved'), color: 'success' })
    await refresh()
  }
  catch (e) {
    if (e instanceof ApiError) errors.value = e.fieldErrors
  }
  finally {
    saving.value = false
  }
}

async function remove(row: Traveler) {
  await api.del(`/partner/travelers/${row.id}`)
  toast.add({ title: t('travelers.deleted'), color: 'neutral' })
  await refresh()
}

const columns: TableColumn<Traveler>[] = [
  { accessorKey: 'name', header: () => t('common.name') },
  { accessorKey: 'date_of_birth', header: () => t('passenger.dob') },
  { accessorKey: 'nationality', header: () => t('passenger.nationality') },
  { accessorKey: 'document', header: () => t('travelers.document') },
  { accessorKey: 'actions', header: '' },
]
</script>

<template>
  <div>
    <PageHeader :title="t('travelers.title')">
      <template #actions>
        <UButton icon="i-lucide-user-plus" :label="t('travelers.add')" @click="edit()" />
      </template>
    </PageHeader>

    <UInput v-model="q" icon="i-lucide-search" :placeholder="t('travelers.searchPlaceholder')" class="mb-4 w-72" />

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <UTable :data="data?.data ?? []" :columns="columns" :loading="pending" :empty="t('common.empty')">
        <template #name-cell="{ row }">
          <span class="ltr font-medium">{{ row.original.last_name }}/{{ row.original.first_name }} {{ row.original.title }}</span>
        </template>
        <template #document-cell="{ row }">
          <span class="ltr">{{ row.original.document_number_masked ?? '—' }}</span>
          <span v-if="row.original.document_expiry" class="ms-2 text-xs text-(--ui-text-muted)">{{ row.original.document_expiry }}</span>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex justify-end gap-1">
            <UButton size="sm" variant="ghost" icon="i-lucide-pencil" :aria-label="t('common.edit')" @click="edit(row.original)" />
            <UButton size="sm" variant="ghost" color="error" icon="i-lucide-trash-2" :aria-label="t('common.delete')" @click="remove(row.original)" />
          </div>
        </template>
      </UTable>
    </UCard>

    <USlideover :open="editing !== null" :title="editing?.id ? t('travelers.edit') : t('travelers.add')" @update:open="(v: boolean) => { if (!v) editing = null }">
      <template #body>
        <form v-if="editing" class="space-y-4" @submit.prevent="save">
          <div class="grid grid-cols-2 gap-3">
            <UFormField :label="t('passenger.firstName')" :error="errors.first_name" required>
              <UInput v-model="editing.first_name" dir="ltr" class="w-full" />
            </UFormField>
            <UFormField :label="t('passenger.lastName')" :error="errors.last_name" required>
              <UInput v-model="editing.last_name" dir="ltr" class="w-full" />
            </UFormField>
            <UFormField :label="t('passenger.title')">
              <USelect v-model="editing.title" :items="['MR', 'MRS', 'MS', 'MISS', 'MSTR']" class="w-full" />
            </UFormField>
            <UFormField :label="t('passenger.gender')">
              <USelect v-model="editing.gender" :items="[{ label: t('passenger.male'), value: 'M' }, { label: t('passenger.female'), value: 'F' }]" class="w-full" />
            </UFormField>
            <UFormField :label="t('passenger.dob')" :error="errors.date_of_birth">
              <UInput v-model="editing.date_of_birth" type="date" class="w-full" />
            </UFormField>
            <UFormField :label="t('passenger.nationality')" :error="errors.nationality">
              <UInput v-model="editing.nationality" maxlength="2" dir="ltr" class="w-full" />
            </UFormField>
            <UFormField :label="t('passenger.documentNumber')" :error="errors.document_number">
              <UInput v-model="editing.document_number" dir="ltr" class="w-full" autocomplete="off" />
            </UFormField>
            <UFormField :label="t('passenger.documentExpiry')" :error="errors.document_expiry">
              <UInput v-model="editing.document_expiry" type="date" class="w-full" />
            </UFormField>
            <UFormField :label="t('passenger.documentCountry')" :error="errors.document_country">
              <UInput v-model="editing.document_country" maxlength="2" dir="ltr" class="w-full" />
            </UFormField>
            <UFormField :label="t('common.phone')">
              <UInput v-model="editing.phone" dir="ltr" class="w-full" />
            </UFormField>
          </div>
          <UFormField :label="t('common.email')" :error="errors.email">
            <UInput v-model="editing.email" type="email" dir="ltr" class="w-full" />
          </UFormField>
          <UFormField :label="t('common.notes')">
            <UTextarea v-model="editing.notes" class="w-full" />
          </UFormField>
          <UButton type="submit" block :loading="saving" :label="t('common.save')" />
        </form>
      </template>
    </USlideover>
  </div>
</template>
