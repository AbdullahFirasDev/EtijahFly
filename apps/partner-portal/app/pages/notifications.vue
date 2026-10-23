<script setup lang="ts">
import { formatDate } from '@etijahfly/api-contracts'

interface Notification {
  id: string
  kind: string
  severity: 'info' | 'success' | 'warning' | 'error'
  title: string
  body: string
  data: { booking_id?: number }
  read_at: string | null
  created_at: string
}

const { t, locale } = useI18n()
const api = useApi()
useHead({ title: t('nav.notifications') })

const page = ref(1)
const { data, refresh } = await useAsyncData('notifications', () => api.get<{ data: Notification[], unread_count: number, meta: { total: number, last_page: number } }>('/notifications', { page: page.value }), { watch: [page] })

const icons = { info: 'i-lucide-info', success: 'i-lucide-circle-check', warning: 'i-lucide-triangle-alert', error: 'i-lucide-circle-x' }

async function open(n: Notification) {
  if (!n.read_at) await api.post(`/notifications/${n.id}/read`)
  if (n.data.booking_id) await navigateTo(`/bookings/${n.data.booking_id}`)
  else await refresh()
}

async function markAll() {
  await api.post('/notifications/read-all')
  await refresh()
}
</script>

<template>
  <div>
    <PageHeader :title="t('notifications.title')">
      <template #actions>
        <UButton v-if="data?.unread_count" variant="soft" icon="i-lucide-check-check" :label="t('notifications.markAll')" @click="markAll" />
      </template>
    </PageHeader>

    <UEmpty v-if="!data?.data.length" icon="i-lucide-bell-off" :title="t('common.empty')" />
    <div class="space-y-2">
      <button
        v-for="n in data?.data ?? []"
        :key="n.id"
        type="button"
        class="flex w-full items-start gap-3 rounded-lg border border-(--ui-border) bg-(--ui-bg) p-4 text-start transition hover:bg-(--ui-bg-elevated)"
        :class="{ 'border-s-4 border-s-(--ui-primary)': !n.read_at }"
        @click="open(n)"
      >
        <UIcon :name="icons[n.severity] ?? icons.info" class="mt-0.5 size-5 shrink-0" :class="`text-(--ui-${n.severity === 'info' ? 'info' : n.severity})`" />
        <div class="min-w-0 flex-1">
          <p class="font-medium">
            {{ n.title }}
          </p>
          <p class="text-sm text-(--ui-text-muted)">
            {{ n.body }}
          </p>
        </div>
        <span class="shrink-0 text-xs text-(--ui-text-dimmed)">{{ formatDate(n.created_at, locale, true) }}</span>
      </button>
    </div>
    <div v-if="(data?.meta.last_page ?? 1) > 1" class="mt-4 flex justify-center">
      <UPagination v-model:page="page" :total="data?.meta.total ?? 0" :items-per-page="20" />
    </div>
  </div>
</template>
