<script setup lang="ts">
import { ar, en } from '@nuxt/ui/locale'

const { locale } = useI18n()
const uiLocale = computed(() => (locale.value === 'ar' ? ar : en))

const { me } = useAuth()
const { apply, displayName } = useBranding()

watch(me, (value) => {
  if (value) apply(value.branding, value.tenant?.slug ?? null)
}, { immediate: true })

useHead({
  htmlAttrs: { lang: () => locale.value, dir: () => uiLocale.value.dir },
  titleTemplate: (title?: string) => (title ? `${title} · ${displayName.value}` : displayName.value),
})
</script>

<template>
  <UApp :locale="uiLocale">
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
