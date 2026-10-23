<script setup lang="ts">
const { t } = useI18n()
</script>

<template>
  <UAlert
    color="warning"
    variant="subtle"
    icon="i-lucide-flask-conical"
    :title="t('testData.title')"
    :description="t('testData.body')"
    data-testid="test-data-banner"
  />
</template>
