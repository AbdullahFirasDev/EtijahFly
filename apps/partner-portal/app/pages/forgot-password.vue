<script setup lang="ts">
import { ApiError } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const api = useApi()
const email = ref('')
const sent = ref<string | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = null
  try {
    sent.value = (await api.post<{ message: string }>('/auth/forgot-password', { email: email.value })).message
  }
  catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.error')
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="space-y-5" @submit.prevent="submit">
    <div>
      <h2 class="text-lg font-semibold">
        {{ t('auth.forgotTitle') }}
      </h2>
      <p class="mt-1 text-sm text-(--ui-text-muted)">
        {{ t('auth.forgotHint') }}
      </p>
    </div>
    <UAlert v-if="sent" color="success" variant="subtle" icon="i-lucide-mail-check" :description="sent" />
    <UAlert v-if="error" color="error" variant="subtle" :description="error" />
    <UFormField :label="t('auth.email')" required>
      <UInput v-model="email" type="email" dir="ltr" class="w-full" required />
    </UFormField>
    <UButton type="submit" block :loading="loading" :label="t('auth.sendLink')" />
    <div class="text-center">
      <ULink to="/login" class="text-sm text-(--ui-primary)">
        {{ t('auth.backToLogin') }}
      </ULink>
    </div>
  </form>
</template>
