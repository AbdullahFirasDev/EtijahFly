<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const { t, locale, setLocale } = useI18n()
const { me, can, canAny, logout } = useAuth()
const { displayName, logoUrl } = useBranding()
const api = useApi()
const route = useRoute()
const mobileOpen = ref(false)

const unread = ref(0)
async function refreshUnread() {
  try {
    unread.value = (await api.get<{ unread_count: number }>('/notifications', { page: 1 })).unread_count
  }
  catch {
    unread.value = 0
  }
}
onMounted(refreshUnread)
watch(() => route.fullPath, () => {
  mobileOpen.value = false
  refreshUnread()
})

const navigation = computed<NavigationMenuItem[][]>(() => {
  const operations: NavigationMenuItem[] = [
    { label: t('nav.dashboard'), icon: 'i-lucide-layout-dashboard', to: '/' },
    can('flights.search') && { label: t('nav.search'), icon: 'i-lucide-plane-takeoff', to: '/flights' },
    canAny('bookings.view', 'bookings.view_all') && { label: t('nav.bookings'), icon: 'i-lucide-ticket', to: '/bookings' },
    can('travelers.manage') && { label: t('nav.travelers'), icon: 'i-lucide-users', to: '/travelers' },
  ].filter(Boolean) as NavigationMenuItem[]

  const money: NavigationMenuItem[] = [
    can('finance.view') && { label: t('nav.finance'), icon: 'i-lucide-wallet', to: '/finance' },
    can('finance.view') && { label: t('nav.payments'), icon: 'i-lucide-banknote', to: '/finance/payments' },
    can('invoices.view') && { label: t('nav.invoices'), icon: 'i-lucide-file-text', to: '/finance/invoices' },
    can('commissions.view') && { label: t('nav.commissions'), icon: 'i-lucide-percent', to: '/finance/commissions' },
    can('reports.view') && { label: t('nav.reports'), icon: 'i-lucide-chart-column', to: '/reports' },
  ].filter(Boolean) as NavigationMenuItem[]

  const company: NavigationMenuItem[] = [
    can('team.view') && { label: t('nav.team'), icon: 'i-lucide-user-cog', to: '/team' },
    can('team.view') && { label: t('nav.roles'), icon: 'i-lucide-shield-check', to: '/team/roles' },
    can('branding.manage') && { label: t('nav.branding'), icon: 'i-lucide-palette', to: '/settings/branding' },
  ].filter(Boolean) as NavigationMenuItem[]

  return [
    [{ label: t('nav.sections.operations'), type: 'label' }, ...operations],
    ...(money.length ? [[{ label: t('nav.sections.money'), type: 'label' } as NavigationMenuItem, ...money]] : []),
    ...(company.length ? [[{ label: t('nav.sections.company'), type: 'label' } as NavigationMenuItem, ...company]] : []),
  ]
})

const userMenu = computed(() => [[
  { label: me.value?.email ?? '', type: 'label' as const },
], [
  { label: t('nav.profile'), icon: 'i-lucide-user', to: '/profile' },
  { label: locale.value === 'ar' ? 'English' : 'العربية', icon: 'i-lucide-languages', onSelect: () => setLocale(locale.value === 'ar' ? 'en' : 'ar') },
], [
  { label: t('nav.logout'), icon: 'i-lucide-log-out', color: 'error' as const, onSelect: () => logout() },
]])
</script>

<template>
  <div class="min-h-screen">
    <!-- Sidebar (desktop) -->
    <aside class="fixed inset-y-0 start-0 z-30 hidden w-64 print:hidden! flex-col border-e border-(--ui-border) bg-(--ef-sheet) lg:flex">
      <NuxtLink to="/" class="flex h-16 shrink-0 items-center gap-3 px-5">
        <img v-if="logoUrl" :src="logoUrl" alt="" class="h-9 max-w-28 object-contain">
        <span v-else class="size-3 shrink-0 rotate-45 rounded-[2px] bg-(--ui-primary)" aria-hidden="true" />
        <span class="truncate font-display text-lg font-semibold leading-tight">{{ displayName }}</span>
      </NuxtLink>
      <div class="mx-5 h-0.5 rounded-full bg-(--ui-primary)" aria-hidden="true" />
      <UNavigationMenu :items="navigation" orientation="vertical" class="flex-1 overflow-y-auto p-3" />
    </aside>

    <!-- Sidebar (mobile) -->
    <USlideover v-model:open="mobileOpen" :side="locale === 'ar' ? 'right' : 'left'" :title="displayName" class="lg:hidden">
      <template #body>
        <UNavigationMenu :items="navigation" orientation="vertical" />
      </template>
    </USlideover>

    <div class="lg:ps-64 print:ps-0!">
      <header class="sticky top-0 z-20 flex h-16 print:hidden items-center gap-3 border-b border-(--ui-border) bg-(--ef-ground)/85 px-4 backdrop-blur sm:px-6">
        <UButton icon="i-lucide-menu" color="neutral" variant="ghost" class="lg:hidden" :aria-label="t('nav.dashboard')" @click="mobileOpen = true" />
        <span class="truncate font-display font-semibold lg:hidden">{{ displayName }}</span>
        <div class="ms-auto flex items-center gap-2">
          <UButton
            v-if="can('flights.search') && route.path !== '/'"
            to="/flights"
            icon="i-lucide-search"
            :label="t('dashboard.newSearch')"
            class="hidden sm:inline-flex"
          />
          <UChip :show="unread > 0" :text="unread > 9 ? '9+' : String(unread)" size="3xl" color="error">
            <UButton to="/notifications" icon="i-lucide-bell" color="neutral" variant="ghost" :aria-label="t('nav.notifications')" />
          </UChip>
          <UDropdownMenu :items="userMenu">
            <UButton color="neutral" variant="ghost" trailing-icon="i-lucide-chevron-down">
              <UAvatar :alt="me?.name" size="xs" />
              <span class="hidden max-w-40 truncate sm:inline">{{ me?.name }}</span>
            </UButton>
          </UDropdownMenu>
        </div>
      </header>

      <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-8 print:max-w-none print:p-0!">
        <slot />
      </main>
    </div>
  </div>
</template>
