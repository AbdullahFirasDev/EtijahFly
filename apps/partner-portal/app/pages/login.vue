<script setup lang="ts">
import { ApiError } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

const { t } = useI18n()
const { login } = useAuth()
const route = useRoute()

const form = reactive({ email: '', password: '' })
const error = ref<string | null>(null)
const loading = ref(false)

useHead({ title: t('auth.title') })

async function submit() {
  error.value = null
  loading.value = true
  try {
    await login(form.email, form.password)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') && !route.query.redirect.startsWith('//')
      ? route.query.redirect
      : '/'
    await navigateTo(redirect)
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
  <form class="space-y-5" data-testid="login-form" @submit.prevent="submit">
    <h2 class="text-lg font-semibold">
      {{ t('auth.title') }}
    </h2>
    <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :description="error" data-testid="login-error" />
    <UFormField :label="t('auth.email')" required>
      <UInput v-model="form.email" type="email" autocomplete="username" dir="ltr" class="w-full" required data-testid="email" />
    </UFormField>
    <UFormField :label="t('auth.password')" required>
      <UInput v-model="form.password" type="password" autocomplete="current-password" dir="ltr" class="w-full" required data-testid="password" />
    </UFormField>
    <UButton type="submit" block :loading="loading" :label="t('auth.submit')" data-testid="login-submit" />
    <div class="text-center">
      <ULink to="/forgot-password" class="text-sm text-(--ui-primary)">
        {{ t('auth.forgot') }}
      </ULink>
    </div>
  </form>
</template>
