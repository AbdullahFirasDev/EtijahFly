<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{ error: NuxtError }>()
const { t } = useI18n()
</script>

<template>
  <UApp>
    <div class="flex min-h-screen items-center justify-center p-6">
      <UEmpty
        :icon="props.error.statusCode === 403 ? 'i-lucide-shield-x' : 'i-lucide-compass'"
        :title="String(props.error.statusCode)"
        :description="props.error.statusCode === 403 ? t('common.forbidden') : props.error.statusMessage"
        :actions="[{ label: t('nav.dashboard'), icon: 'i-lucide-home', onClick: () => clearError({ redirect: '/' }) }]"
      />
    </div>
  </UApp>
</template>
