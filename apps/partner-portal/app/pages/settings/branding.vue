<script setup lang="ts">
import type { Branding } from '@etijahfly/api-contracts'
import { ApiError } from '~/utils/api-error'

definePageMeta({ permission: 'branding.manage' })

const { t } = useI18n()
const api = useApi()
const toast = useToast()
const { apply, logoUrl, tenantSlug } = useBranding()
useHead({ title: t('nav.branding') })

const { data } = await useAsyncData('branding', async () => (await api.get<{ data: Branding }>('/partner/settings/branding')).data)
const form = reactive({
  display_name: '', primary_color: '#0F4C81', secondary_color: '#1B998B', accent_color: '#F4A259',
  support_email: '', support_phone: '', website: '', address: '', document_footer: '',
})
watch(data, (v) => {
  if (v) Object.assign(form, Object.fromEntries(Object.entries(v).filter(([k]) => k in form).map(([k, val]) => [k, val ?? ''])))
}, { immediate: true })

const errors = ref<Record<string, string>>({})
const saving = ref(false)
const uploading = ref(false)

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    const { data: saved } = await api.put<{ data: Branding }>('/partner/settings/branding', body)
    data.value = saved
    apply(saved, tenantSlug.value)
    toast.add({ title: t('common.saved'), color: 'success' })
  }
  catch (e) {
    if (e instanceof ApiError) errors.value = e.fieldErrors
  }
  finally {
    saving.value = false
  }
}

async function upload(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  uploading.value = true
  errors.value = {}
  const body = new FormData()
  body.append('logo', file)
  try {
    const { data: saved } = await api.post<{ data: Branding }>('/partner/settings/branding/logo', body)
    data.value = saved
    apply(saved, tenantSlug.value)
  }
  catch (err) {
    if (err instanceof ApiError) errors.value = { logo: err.fieldErrors.logo ?? err.message }
  }
  finally {
    uploading.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('branding.title')" :description="t('branding.hint')" />

    <div class="grid gap-6 lg:grid-cols-3">
      <UCard class="lg:col-span-2">
        <form class="space-y-4" @submit.prevent="save">
          <UFormField :label="t('branding.displayName')" :error="errors.display_name" required>
            <UInput v-model="form.display_name" class="w-full" />
          </UFormField>
          <div class="grid gap-4 sm:grid-cols-3">
            <UFormField v-for="key in (['primary_color', 'secondary_color', 'accent_color'] as const)" :key="key" :label="t(`branding.${key.split('_')[0]}`)" :error="errors[key]">
              <div class="flex items-center gap-2">
                <input v-model="form[key]" type="color" class="h-9 w-12 cursor-pointer rounded border border-(--ui-border)">
                <UInput v-model="form[key]" dir="ltr" class="w-full" />
              </div>
            </UFormField>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField :label="t('branding.supportEmail')" :error="errors.support_email">
              <UInput v-model="form.support_email" type="email" dir="ltr" class="w-full" />
            </UFormField>
            <UFormField :label="t('branding.supportPhone')" :error="errors.support_phone">
              <UInput v-model="form.support_phone" dir="ltr" class="w-full" />
            </UFormField>
            <UFormField :label="t('branding.website')" :error="errors.website">
              <UInput v-model="form.website" dir="ltr" placeholder="https://" class="w-full" />
            </UFormField>
            <UFormField :label="t('branding.address')" :error="errors.address">
              <UInput v-model="form.address" class="w-full" />
            </UFormField>
          </div>
          <UFormField :label="t('branding.footer')" :error="errors.document_footer">
            <UTextarea v-model="form.document_footer" class="w-full" :rows="2" />
          </UFormField>
          <UButton type="submit" :loading="saving" :label="t('common.save')" />
        </form>
      </UCard>

      <div class="space-y-6">
        <UCard>
          <template #header>
            <h3 class="font-semibold">
              {{ t('branding.logo') }}
            </h3>
          </template>
          <div class="flex h-28 items-center justify-center rounded-lg bg-(--ui-bg-muted)">
            <img v-if="logoUrl" :src="logoUrl" alt="" class="max-h-24 max-w-full object-contain">
            <UIcon v-else name="i-lucide-image" class="size-10 text-(--ui-text-dimmed)" />
          </div>
          <p class="mt-3 text-xs text-(--ui-text-muted)">
            {{ t('branding.logoHint') }}
          </p>
          <label class="mt-3 block">
            <span class="sr-only">{{ t('branding.upload') }}</span>
            <input type="file" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm" :disabled="uploading" @change="upload">
          </label>
          <p v-if="errors.logo" class="mt-2 text-sm text-(--ui-error)">
            {{ errors.logo }}
          </p>
        </UCard>

        <UCard>
          <template #header>
            <h3 class="font-semibold">
              {{ t('branding.preview') }}
            </h3>
          </template>
          <div class="overflow-hidden rounded-lg border border-(--ui-border)">
            <div class="p-3 text-white" :style="{ background: form.primary_color }">
              <p class="font-semibold">
                {{ form.display_name }}
              </p>
            </div>
            <div class="space-y-2 p-3 text-sm">
              <div class="h-2 w-2/3 rounded" :style="{ background: form.secondary_color }" />
              <div class="h-2 w-1/2 rounded" :style="{ background: form.accent_color }" />
              <p class="text-xs text-(--ui-text-muted)">
                {{ form.document_footer }}
              </p>
            </div>
          </div>
        </UCard>
      </div>
    </div>
  </div>
</template>
