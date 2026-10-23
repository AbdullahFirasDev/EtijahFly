<script setup lang="ts">
const { t, locale, setLocale } = useI18n()
const { displayName, logoUrl, loadPublic } = useBranding()
const config = useRuntimeConfig()

onMounted(loadPublic)
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center px-4 py-12">
    <UButton
      class="absolute end-4 top-4"
      color="neutral"
      variant="ghost"
      icon="i-lucide-languages"
      :label="locale === 'ar' ? 'English' : 'العربية'"
      @click="setLocale(locale === 'ar' ? 'en' : 'ar')"
    />
    <div class="w-full max-w-sm">
      <div class="mb-8 text-center">
        <img v-if="logoUrl" :src="logoUrl" alt="" class="mx-auto mb-5 h-14 max-w-48 object-contain">
        <h1 class="font-display text-4xl font-semibold leading-tight">
          {{ displayName }}
        </h1>
        <div class="mx-auto mt-4 h-0.5 w-12 rounded-full bg-(--ui-primary)" aria-hidden="true" />
        <p class="mt-4 text-(--ui-text-muted)">
          {{ t('auth.subtitle') }}
        </p>
      </div>
      <div class="ef-sheet p-6 sm:p-7">
        <slot />
      </div>
      <p class="mt-6 text-center text-xs text-(--ui-text-dimmed)">
        {{ t('auth.poweredBy', { platform: config.public.platformName }) }}
      </p>
    </div>
  </div>
</template>
