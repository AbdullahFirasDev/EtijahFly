<script setup lang="ts">
import { BOOKING_STATUS_TONE } from '@etijahfly/api-contracts'
import type { BookingStatus, Tone } from '@etijahfly/api-contracts'

const props = defineProps<{ status: string }>()
const { t } = useI18n()

const OTHER: Record<string, Tone> = {
  approved: 'success', completed: 'success', active: 'success', accepted: 'success', issued: 'success',
  pending: 'warning', requested: 'warning',
  rejected: 'error', disabled: 'error', failed: 'error',
  revoked: 'neutral', expired: 'neutral', credited: 'neutral',
}

const color = computed(() => {
  const tone = BOOKING_STATUS_TONE[props.status as BookingStatus] ?? OTHER[props.status] ?? 'neutral'
  return tone
})
</script>

<template>
  <UBadge :color="color" variant="subtle" :label="t(`status.${status}`)" />
</template>
