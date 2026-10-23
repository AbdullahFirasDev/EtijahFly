<script setup lang="ts">
interface Airport {
  iata_code: string
  name_en: string
  name_ar: string | null
  city_en: string
  city_ar: string | null
  country_code: string
}
interface Item { label: string, value: string, description: string }

const model = defineModel<string>({ default: '' })
defineProps<{ placeholder?: string, testId?: string }>()

const { locale } = useI18n()
const api = useApi()
const term = ref('')
const items = ref<Item[]>([])
const loading = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

function toItem(a: Airport): Item {
  const ar = locale.value === 'ar'
  return {
    value: a.iata_code,
    label: `${(ar ? a.city_ar : a.city_en) ?? a.city_en} (${a.iata_code})`,
    description: (ar ? a.name_ar : a.name_en) ?? a.name_en,
  }
}

async function load(q: string) {
  loading.value = true
  try {
    const res = await api.get<{ data: Airport[] }>('/reference/airports', { q })
    items.value = res.data.map(toItem)
    // keep the selected value visible even if not in the latest results
    if (model.value && !items.value.some(i => i.value === model.value)) {
      items.value.unshift({ value: model.value, label: model.value, description: '' })
    }
  }
  finally {
    loading.value = false
  }
}

watch(term, (q) => {
  clearTimeout(timer)
  timer = setTimeout(() => load(q), 200)
})
onMounted(() => load(model.value || ''))
</script>

<template>
  <UInputMenu
    v-model="model"
    v-model:search-term="term"
    :items="items"
    value-key="value"
    :loading="loading"
    :placeholder="placeholder"
    icon="i-lucide-map-pin"
    ignore-filter
    class="w-full"
    :data-testid="testId"
  />
</template>
