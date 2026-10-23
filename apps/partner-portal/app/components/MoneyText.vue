<script setup lang="ts">
import { formatMoney } from '@etijahfly/api-contracts'

defineProps<{ amount: string | number | null | undefined, currency: string }>()
const { locale } = useI18n()
</script>

<template>
  <span class="ltr tabular-nums">{{ formatMoney(amount, currency, locale) }}</span>
</template>
