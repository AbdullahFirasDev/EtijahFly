<script setup lang="ts">
import { ApiError } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

interface InvitationInfo {
  email: string
  name: string | null
  organisation: string
  roles: { name: string, label: string }[]
  expires_at: string
}

const { t } = useI18n()
const api = useApi()
const route = useRoute()
const token = String(route.params.token)

const { data: invitation, error: loadError } = await useAsyncData(`invite-${token}`, () => api.get<InvitationInfo>(`/invitations/${token}`))

const form = reactive({ name: '', phone: '', password: '', password_confirmation: '' })
watch(invitation, (v) => {
  if (v?.name) form.name = v.name
}, { immediate: true })

const done = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = null
  try {
    await api.post(`/invitations/${token}/accept`, { ...form })
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
      {{ t('auth.inviteTitle') }}
    </h2>
    <UAlert v-if="loadError" color="error" variant="subtle" :description="t('auth.inviteInvalid')" />
    <template v-else-if="done">
      <UAlert color="success" variant="subtle" :description="t('auth.inviteDone')" />
      <UButton to="/login" block :label="t('auth.backToLogin')" />
    </template>
    <form v-else-if="invitation" class="space-y-4" @submit.prevent="submit">
      <p class="text-sm">
        {{ t('auth.inviteFor', { organisation: invitation.organisation }) }}
      </p>
      <div class="flex flex-wrap gap-2">
        <UBadge v-for="role in invitation.roles" :key="role.name" color="primary" variant="subtle" :label="role.label" />
      </div>
      <UAlert v-if="error" color="error" variant="subtle" :description="error" />
      <UFormField :label="t('common.email')">
        <UInput :model-value="invitation.email" disabled dir="ltr" class="w-full" />
      </UFormField>
      <UFormField :label="t('common.name')" :error="fieldErrors.name" required>
        <UInput v-model="form.name" class="w-full" required />
      </UFormField>
      <UFormField :label="t('common.phone')" :error="fieldErrors.phone">
        <UInput v-model="form.phone" dir="ltr" class="w-full" />
      </UFormField>
      <UFormField :label="t('auth.newPassword')" :help="t('auth.passwordRules')" :error="fieldErrors.password" required>
        <UInput v-model="form.password" type="password" autocomplete="new-password" dir="ltr" class="w-full" required />
      </UFormField>
      <UFormField :label="t('auth.confirmPassword')" required>
        <UInput v-model="form.password_confirmation" type="password" autocomplete="new-password" dir="ltr" class="w-full" required />
      </UFormField>
      <UButton type="submit" block :loading="loading" :label="t('auth.inviteAccept')" />
    </form>
  </div>
</template>
