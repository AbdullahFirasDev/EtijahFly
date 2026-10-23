<script setup lang="ts">
import type { Traveler } from '@etijahfly/api-contracts'

export interface PassengerInput {
  type: 'ADT' | 'CHD' | 'INF'
  traveler_id: number | null
  title: string | undefined
  first_name: string
  last_name: string
  gender: 'M' | 'F'
  date_of_birth: string
  nationality: string
  document_type: 'passport' | 'national_id'
  document_number: string
  document_expiry: string
  document_country: string
}

const model = defineModel<PassengerInput>({ required: true })
const props = defineProps<{ index: number, travelers: Traveler[], errors: Record<string, string> }>()

const { t } = useI18n()
const api = useApi()

const prefix = computed(() => `passengers.${props.index}.`)
const err = (field: string) => props.errors[prefix.value + field]

const titles = computed(() => (model.value.type === 'ADT' ? ['MR', 'MRS', 'MS'] : ['MSTR', 'MISS']).map(v => ({ label: v, value: v })))
const genders = computed(() => [{ label: t('passenger.male'), value: 'M' }, { label: t('passenger.female'), value: 'F' }])
const documentTypes = computed(() => [{ label: t('passenger.passport'), value: 'passport' }, { label: t('passenger.nationalId'), value: 'national_id' }])
const travelerItems = computed(() => props.travelers.map(tr => ({ label: `${tr.first_name} ${tr.last_name}`, value: tr.id })))

const selectedTraveler = ref<number | undefined>()
watch(selectedTraveler, async (id) => {
  if (!id) return
  // Full record (with document number) is only fetched on explicit selection.
  const { data } = await api.get<{ data: Traveler & { document_number?: string } }>(`/partner/travelers/${id}`)
  Object.assign(model.value, {
    traveler_id: data.id,
    title: data.title ?? model.value.title,
    first_name: data.first_name,
    last_name: data.last_name,
    gender: (data.gender as 'M' | 'F') ?? model.value.gender,
    date_of_birth: data.date_of_birth ?? '',
    nationality: data.nationality ?? '',
    document_type: (data.document_type as 'passport' | 'national_id') ?? 'passport',
    document_number: data.document_number ?? '',
    document_expiry: data.document_expiry ?? '',
    document_country: data.document_country ?? '',
  })
})

function upper(field: 'first_name' | 'last_name' | 'nationality' | 'document_country' | 'document_number') {
  model.value[field] = model.value[field].toUpperCase().trimStart()
}
</script>

<template>
  <UCard :data-testid="`passenger-${index}`">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-semibold">
          {{ t('book.passenger', { n: index + 1, type: t(`passenger.${model.type}`) }) }}
        </h3>
        <USelectMenu
          v-if="travelers.length"
          v-model="selectedTraveler"
          :items="travelerItems"
          value-key="value"
          :placeholder="t('book.fromSaved')"
          icon="i-lucide-user-search"
          class="w-64"
        />
      </div>
    </template>

    <p class="mb-4 text-xs text-(--ui-text-muted)">
      {{ t('book.namesHint') }}
    </p>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <UFormField :label="t('passenger.title')" :error="err('title')">
        <USelect v-model="model.title" :items="titles" class="w-full" />
      </UFormField>
      <UFormField :label="t('passenger.firstName')" required :error="err('first_name')">
        <UInput v-model="model.first_name" class="w-full" dir="ltr" autocomplete="off" :data-testid="`p${index}-first`" @blur="upper('first_name')" />
      </UFormField>
      <UFormField :label="t('passenger.lastName')" required :error="err('last_name')">
        <UInput v-model="model.last_name" class="w-full" dir="ltr" autocomplete="off" :data-testid="`p${index}-last`" @blur="upper('last_name')" />
      </UFormField>
      <UFormField :label="t('passenger.gender')" required :error="err('gender')">
        <USelect v-model="model.gender" :items="genders" class="w-full" />
      </UFormField>
      <UFormField :label="t('passenger.dob')" required :error="err('date_of_birth')">
        <UInput v-model="model.date_of_birth" type="date" class="w-full" :data-testid="`p${index}-dob`" />
      </UFormField>
      <UFormField :label="t('passenger.nationality')" required :error="err('nationality')">
        <UInput v-model="model.nationality" maxlength="2" placeholder="IQ" class="w-full" dir="ltr" :data-testid="`p${index}-nat`" @blur="upper('nationality')" />
      </UFormField>
      <UFormField :label="t('passenger.documentType')" :error="err('document_type')">
        <USelect v-model="model.document_type" :items="documentTypes" class="w-full" />
      </UFormField>
      <UFormField :label="t('passenger.documentNumber')" required :error="err('document_number')">
        <UInput v-model="model.document_number" class="w-full" dir="ltr" autocomplete="off" :data-testid="`p${index}-doc`" @blur="upper('document_number')" />
      </UFormField>
      <UFormField :label="t('passenger.documentExpiry')" required :error="err('document_expiry')">
        <UInput v-model="model.document_expiry" type="date" class="w-full" :data-testid="`p${index}-exp`" />
      </UFormField>
      <UFormField :label="t('passenger.documentCountry')" required :error="err('document_country')">
        <UInput v-model="model.document_country" maxlength="2" placeholder="IQ" class="w-full" dir="ltr" :data-testid="`p${index}-country`" @blur="upper('document_country')" />
      </UFormField>
    </div>
  </UCard>
</template>
