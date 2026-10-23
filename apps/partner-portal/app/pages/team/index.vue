<script setup lang="ts">
import { formatDate } from '@etijahfly/api-contracts'
import type { Invitation, Role, User } from '@etijahfly/api-contracts'
import type { TableColumn } from '@nuxt/ui'
import { ApiError } from '~/utils/api-error'

definePageMeta({ permission: 'team.view' })

const { t, locale } = useI18n()
const { me, can } = useAuth()
const api = useApi()
const toast = useToast()
useHead({ title: t('nav.team') })

const { data: users, refresh: refreshUsers, pending } = await useAsyncData('team-users', () => api.get<{ data: User[] }>('/partner/team/users'))
const { data: invitations, refresh: refreshInvites } = await useAsyncData('team-invites', () => api.get<{ data: Invitation[] }>('/partner/team/invitations'))
const { data: roles } = await useAsyncData('team-roles', () => api.get<{ data: Role[] }>('/partner/team/roles'))
const roleItems = computed(() => (roles.value?.data ?? []).map(r => ({ label: r.label, value: r.name })))

const inviteOpen = ref(false)
const invite = reactive({ email: '', name: '', roles: ['booking_agent'] as string[] })
const inviteErrors = ref<Record<string, string>>({})
const inviteError = ref<string | null>(null)

async function sendInvite() {
  inviteErrors.value = {}
  inviteError.value = null
  try {
    await api.post('/partner/team/invitations', { ...invite })
    inviteOpen.value = false
    Object.assign(invite, { email: '', name: '', roles: ['booking_agent'] })
    toast.add({ title: t('team.invited'), color: 'success' })
    await refreshInvites()
  }
  catch (e) {
    if (e instanceof ApiError) {
      inviteErrors.value = e.fieldErrors
      inviteError.value = e.message
    }
  }
}

const rolesFor = ref<User | null>(null)
const selectedRoles = ref<string[]>([])
function editRoles(user: User) {
  rolesFor.value = user
  selectedRoles.value = (user.roles ?? []).map(r => r.name)
}
async function saveRoles() {
  if (!rolesFor.value) return
  try {
    await api.put(`/partner/team/users/${rolesFor.value.id}/roles`, { roles: selectedRoles.value })
    rolesFor.value = null
    await refreshUsers()
  }
  catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('common.error'), color: 'error' })
  }
}

async function toggle(user: User) {
  try {
    await api.put(`/partner/team/users/${user.id}/status`, { status: user.status === 'active' ? 'disabled' : 'active' })
    await refreshUsers()
  }
  catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('common.error'), color: 'error' })
  }
}

async function revoke(inv: Invitation) {
  await api.del(`/partner/team/invitations/${inv.id}`)
  await refreshInvites()
}

const columns: TableColumn<User>[] = [
  { accessorKey: 'name', header: () => t('common.name') },
  { accessorKey: 'roles', header: () => t('team.roles') },
  { accessorKey: 'status', header: () => t('common.status') },
  { accessorKey: 'last_login_at', header: () => t('team.lastLogin') },
  { accessorKey: 'actions', header: '' },
]
</script>

<template>
  <div>
    <PageHeader :title="t('team.title')">
      <template #actions>
        <UButton v-if="can('team.manage')" icon="i-lucide-user-plus" :label="t('team.invite')" @click="inviteOpen = true" />
      </template>
    </PageHeader>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <UTable :data="users?.data ?? []" :columns="columns" :loading="pending">
        <template #name-cell="{ row }">
          <div>
            <p class="font-medium">
              {{ row.original.name }}
            </p>
            <p class="ltr text-xs text-(--ui-text-muted)">
              {{ row.original.email }}
            </p>
          </div>
        </template>
        <template #roles-cell="{ row }">
          <div class="flex flex-wrap gap-1">
            <UBadge v-for="r in row.original.roles" :key="r.name" color="primary" variant="subtle" :label="r.label" />
          </div>
        </template>
        <template #status-cell="{ row }">
          <StatusBadge :status="row.original.status" />
        </template>
        <template #last_login_at-cell="{ row }">
          {{ formatDate(row.original.last_login_at, locale, true) }}
        </template>
        <template #actions-cell="{ row }">
          <div v-if="can('team.manage') && row.original.id !== me?.id" class="flex justify-end gap-1">
            <UButton size="sm" variant="ghost" icon="i-lucide-shield" :label="t('team.changeRoles')" @click="editRoles(row.original)" />
            <UButton size="sm" variant="ghost" :color="row.original.status === 'active' ? 'error' : 'success'" :label="row.original.status === 'active' ? t('team.disable') : t('team.enable')" @click="toggle(row.original)" />
          </div>
        </template>
      </UTable>
    </UCard>

    <UCard v-if="invitations?.data.length" class="mt-6">
      <template #header>
        <h3 class="font-semibold">
          {{ t('team.invitations') }}
        </h3>
      </template>
      <ul class="divide-y divide-(--ui-border)">
        <li v-for="inv in invitations.data" :key="inv.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <span class="ltr">{{ inv.email }}</span>
          <span class="text-xs text-(--ui-text-muted)">{{ inv.roles.join(', ') }}</span>
          <StatusBadge :status="inv.status" />
          <span class="text-xs">{{ t('team.expires') }}: {{ formatDate(inv.expires_at, locale, true) }}</span>
          <UButton v-if="inv.status === 'pending' && can('team.manage')" size="sm" variant="ghost" color="error" :label="t('team.revoke')" @click="revoke(inv)" />
        </li>
      </ul>
    </UCard>

    <UModal v-model:open="inviteOpen" :title="t('team.invite')">
      <template #body>
        <form class="space-y-4" @submit.prevent="sendInvite">
          <UAlert v-if="inviteError" color="error" variant="subtle" :description="inviteError" />
          <UFormField :label="t('common.email')" :error="inviteErrors.email" required>
            <UInput v-model="invite.email" type="email" dir="ltr" class="w-full" />
          </UFormField>
          <UFormField :label="t('common.name')">
            <UInput v-model="invite.name" class="w-full" />
          </UFormField>
          <UFormField :label="t('team.roles')" :error="inviteErrors.roles" required>
            <USelectMenu v-model="invite.roles" :items="roleItems" value-key="value" multiple class="w-full" />
          </UFormField>
          <UButton type="submit" block :label="t('team.invite')" />
        </form>
      </template>
    </UModal>

    <UModal :open="rolesFor !== null" :title="t('team.changeRoles')" @update:open="(v: boolean) => { if (!v) rolesFor = null }">
      <template #body>
        <div class="space-y-4">
          <p class="font-medium">
            {{ rolesFor?.name }}
          </p>
          <USelectMenu v-model="selectedRoles" :items="roleItems" value-key="value" multiple class="w-full" />
          <UButton block :label="t('common.save')" @click="saveRoles" />
        </div>
      </template>
    </UModal>
  </div>
</template>
