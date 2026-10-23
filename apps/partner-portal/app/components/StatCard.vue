<script setup lang="ts">
defineProps<{ label: string, icon?: string, tone?: 'primary' | 'success' | 'warning' | 'error' | 'neutral' }>()
</script>

<template>
  <UCard :ui="{ body: 'p-4 sm:p-5' }">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm text-(--ui-text-muted)">
          {{ label }}
        </p>
        <p class="mt-1 text-2xl font-semibold">
          <slot />
        </p>
      </div>
      <div
        v-if="icon"
        class="flex size-10 shrink-0 items-center justify-center rounded-lg"
        :class="{
          'bg-(--ui-primary)/10 text-(--ui-primary)': !tone || tone === 'primary',
          'bg-(--ui-success)/10 text-(--ui-success)': tone === 'success',
          'bg-(--ui-warning)/10 text-(--ui-warning)': tone === 'warning',
          'bg-(--ui-error)/10 text-(--ui-error)': tone === 'error',
          'bg-(--ui-bg-accented) text-(--ui-text-muted)': tone === 'neutral',
        }"
      >
        <UIcon :name="icon" class="size-5" />
      </div>
    </div>
    <slot name="footer" />
  </UCard>
</template>
