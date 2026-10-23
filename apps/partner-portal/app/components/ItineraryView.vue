<script setup lang="ts">
import { formatDuration, formatLocalTime } from '@etijahfly/api-contracts'
import type { Slice } from '@etijahfly/api-contracts'

const props = withDefaults(defineProps<{
  slices: Slice[]
  airlineNames?: Record<string, string>
  compact?: boolean
}>(), { airlineNames: () => ({}), compact: false })

const { t, locale } = useI18n()

function day(iso: string) {
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-IQ' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short', numberingSystem: 'latn' })
    .format(new Date(iso.slice(0, 10) + 'T12:00:00'))
}

function carrierName(code: string) {
  return props.airlineNames[code] ?? code
}
</script>

<template>
  <div :class="compact ? 'space-y-5' : 'space-y-6'">
    <div v-for="(slice, i) in slices" :key="i">
      <div class="flex items-end gap-4">
        <div>
          <p class="ltr font-display text-3xl font-semibold leading-none">
            {{ slice.origin }}
          </p>
          <p class="ltr mt-1.5 text-sm font-medium tabular-nums">
            {{ formatLocalTime(slice.departure_at) }}
          </p>
        </div>
        <div class="flex min-w-16 flex-1 flex-col items-center gap-1.5 pb-1">
          <span class="ltr text-xs tabular-nums text-(--ui-text-muted)">{{ formatDuration(slice.duration_minutes, locale) }}</span>
          <div class="flex w-full items-center gap-1.5" aria-hidden="true">
            <span class="size-1.5 shrink-0 rounded-full bg-(--ui-text-dimmed)" />
            <span class="h-px flex-1 bg-(--ui-border-accented)" />
            <UIcon name="i-lucide-plane" class="size-4 shrink-0 text-(--ui-primary) rtl:-scale-x-100" />
            <span class="h-px flex-1 bg-(--ui-border-accented)" />
            <span class="size-1.5 shrink-0 rounded-full bg-(--ui-text-dimmed)" />
          </div>
          <span class="text-xs" :class="slice.stops === 0 ? 'text-(--ui-success)' : 'text-(--ui-warning)'">
            {{ slice.stops === 0 ? t('offer.direct') : t('offer.stops', { n: slice.stops }) }}
            <span v-if="slice.stops > 0" class="ltr">{{ slice.segments.slice(0, -1).map(s => s.destination).join(', ') }}</span>
          </span>
        </div>
        <div class="text-end">
          <p class="ltr font-display text-3xl font-semibold leading-none">
            {{ slice.destination }}
          </p>
          <p class="ltr mt-1.5 text-sm font-medium tabular-nums">
            {{ formatLocalTime(slice.arrival_at) }}
          </p>
        </div>
      </div>

      <div v-if="!compact" class="mt-4 divide-y divide-(--ui-border) rounded-md border border-(--ui-border) text-sm">
        <div v-for="(segment, s) in slice.segments" :key="s" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2.5">
          <div class="flex items-center gap-2">
            <UBadge color="neutral" variant="outline" class="ltr" :label="segment.flight_number" />
            <span>{{ carrierName(segment.marketing_carrier) }}</span>
            <span v-if="segment.aircraft" class="text-(--ui-text-dimmed)">{{ segment.aircraft }}</span>
          </div>
          <div class="ltr tabular-nums text-(--ui-text-muted)">
            {{ segment.origin }} {{ formatLocalTime(segment.departure_at) }} → {{ segment.destination }} {{ formatLocalTime(segment.arrival_at) }}
            <span class="ms-1">({{ day(segment.departure_at) }})</span>
          </div>
        </div>
      </div>
      <p v-else class="mt-3 flex flex-wrap gap-x-3 text-xs text-(--ui-text-muted)">
        <span>{{ day(slice.departure_at) }}</span>
        <span>{{ [...new Set(slice.segments.map(s => carrierName(s.marketing_carrier)))].join(' / ') }}</span>
      </p>
    </div>
  </div>
</template>
