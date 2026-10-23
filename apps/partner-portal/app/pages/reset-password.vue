<script setup lang="ts">
import { ApiError } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const api = useApi()
const route = useRoute()

const form = reactive({ password: '', password_confirmation: '' })
const done = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    await api.post('/auth/reset-password', {
      token: String(route.query.token ?? ''),
      email: String(route.query.email ?? ''),
      ...form,
    })
    done.value = true
  }
  catch (e) {
    if (e instanceof ApiError) {
      error.value = e.message
      fieldErrors.value = e.fieldErrors
    }
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-5">
    <h2 class="text-lg font-semibold">
      {{ t('auth.resetTitle') }}
    </h2>
    <template v-if="done">
      <UAlert color="success" variant="subtle" :description="t('auth.resetDone')" />
      <UButton to="/login" block :label="t('auth.backToLogin')" />
    </template>
    <form v-else class="space-y-5" @submit.prevent="submit">
      <UAlert v-if="error" color="error" variant="subtle" :description="error" />
      <UFormField :label="t('auth.newPassword')" :help="t('auth.passwordRules')" :error="fieldErrors.password" required>
        <UInput v-model="form.password" type="password" autocomplete="new-password" dir="ltr" class="w-full" required />
      </UFormField>
      <UFormField :label="t('auth.confirmPassword')" required>
        <UInput v-model="form.password_confirmation" type="password" autocomplete="new-password" dir="ltr" class="w-full" required />
      </UFormField>
      <UButton type="submit" block :loading="loading" :label="t('auth.resetSubmit')" />
    </form>
  </div>
</template>
