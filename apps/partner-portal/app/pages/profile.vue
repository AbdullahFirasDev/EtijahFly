<script setup lang="ts">
import { formatDate } from '@etijahfly/api-contracts'
import { ApiError } from '~/utils/api-error'

interface Session { id: number, device: string, ip_address: string | null, last_used_at: string | null, created_at: string, current: boolean }

const { t, locale } = useI18n()
const { me } = useAuth()
const api = useApi()
const toast = useToast()
useHead({ title: t('nav.profile') })

const { data: sessions, refresh } = await useAsyncData('sessions', () => api.get<{ data: Session[] }>('/auth/sessions'))

const pw = reactive({ current_password: '', password: '', password_confirmation: '' })
const errors = ref<Record<string, string>>({})
const error = ref<string | null>(null)

async function changePassword() {
  errors.value = {}
  error.value = null
  try {
    await api.put('/auth/password', { ...pw })
    Object.assign(pw, { current_password: '', password: '', password_confirmation: '' })
    toast.add({ title: t('profile.passwordChanged'), color: 'success' })
    await refresh()
  }
  catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.fieldErrors
      error.value = e.message
    }
  }
}

async function revoke(s: Session) {
  await api.del(`/auth/sessions/${s.id}`)
  await refresh()
}
</script>

<template>
  <div>
    <PageHeader :title="t('profile.title')" :description="me?.email" />
    <div class="grid gap-6 lg:grid-cols-2">
      <UCard>
        <template #header>
          <h3 class="font-semibold">
            {{ t('profile.changePassword') }}
          </h3>
        </template>
        <form class="space-y-4" @submit.prevent="changePassword">
          <UAlert v-if="error" color="error" variant="subtle" :description="error" />
          <UFormField :label="t('profile.currentPassword')" :error="errors.current_password" required>
            <UInput v-model="pw.current_password" type="password" autocomplete="current-password" dir="ltr" class="w-full" />
          </UFormField>
          <UFormField :label="t('auth.newPassword')" :help="t('auth.passwordRules')" :error="errors.password" required>
            <UInput v-model="pw.password" type="password" autocomplete="new-password" dir="ltr" class="w-full" />
          </UFormField>
          <UFormField :label="t('auth.confirmPassword')" required>
            <UInput v-model="pw.password_confirmation" type="password" autocomplete="new-password" dir="ltr" class="w-full" />
          </UFormField>
          <UButton type="submit" :label="t('common.save')" />
        </form>
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">
            {{ t('profile.sessions') }}
          </h3>
        </template>
        <ul class="divide-y divide-(--ui-border)">
          <li v-for="s in sessions?.data ?? []" :key="s.id" class="flex items-center justify-between gap-3 py-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium" :title="s.device">
                {{ s.device }}
              </p>
              <p class="text-xs text-(--ui-text-muted)">
                {{ t('profile.ip') }}: <span class="ltr">{{ s.ip_address ?? '—' }}</span> · {{ t('profile.lastUsed') }}: {{ formatDate(s.last_used_at, locale, true) }}
              </p>
            </div>
            <UBadge v-if="s.current" color="success" variant="subtle" :label="t('profile.current')" />
            <UButton v-else size="sm" variant="ghost" color="error" :label="t('profile.revoke')" @click="revoke(s)" />
          </li>
        </ul>
      </UCard>
    </div>
  </div>
</template>
