<script setup lang="ts">
import type { Role } from '@etijahfly/api-contracts'
import { ApiError } from '~/utils/api-error'

definePageMeta({ permission: 'team.view' })

interface PermissionItem { name: string, group: string, label: string }

const { t } = useI18n()
const { can } = useAuth()
const api = useApi()
const toast = useToast()
useHead({ title: t('nav.roles') })

const { data: roles, refresh } = await useAsyncData('roles', () => api.get<{ data: Role[] }>('/partner/team/roles'))
const { data: catalog } = await useAsyncData('permissions', () => api.get<{ data: PermissionItem[] }>('/partner/team/permissions'))
const grouped = computed(() => {
  const groups: Record<string, PermissionItem[]> = {}
  for (const p of catalog.value?.data ?? []) (groups[p.group] ??= []).push(p)
  return groups
})
const labelOf = (name: string) => catalog.value?.data.find(p => p.name === name)?.label ?? name

const editing = ref<{ id: number | null, name: string, description: string, permissions: string[] } | null>(null)
const error = ref<string | null>(null)

function edit(role?: Role) {
  error.value = null
  editing.value = role
    ? { id: role.id, name: role.label, description: role.description ?? '', permissions: [...(role.permissions ?? [])] }
    : { id: null, name: '', description: '', permissions: [] }
}

function toggle(name: string, on: boolean | 'indeterminate') {
  if (!editing.value) return
  editing.value.permissions = on === true ? [...new Set([...editing.value.permissions, name])] : editing.value.permissions.filter(p => p !== name)
}

async function save() {
  if (!editing.value) return
  const { id, ...body } = editing.value
  try {
    if (id) await api.put(`/partner/team/roles/${id}`, body)
    else await api.post('/partner/team/roles', body)
    editing.value = null
    toast.add({ title: t('common.saved'), color: 'success' })
    await refresh()
  }
  catch (e) {
    error.value = e instanceof ApiError ? e.message : t('common.error')
  }
}

async function remove(role: Role) {
  try {
    await api.del(`/partner/team/roles/${role.id}`)
    await refresh()
  }
  catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('common.error'), color: 'error' })
  }
}
</script>

<template>
  <div>
    <PageHeader :title="t('roles.title')">
      <template #actions>
        <UButton v-if="can('roles.manage')" icon="i-lucide-plus" :label="t('roles.new')" @click="edit()" />
      </template>
    </PageHeader>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <UCard v-for="role in roles?.data ?? []" :key="role.id">
        <template #header>
          <div class="flex items-center justify-between gap-2">
            <div>
              <h3 class="font-semibold">
                {{ role.label }}
              </h3>
              <p class="text-xs text-(--ui-text-muted)">
                {{ role.users_count ?? 0 }} {{ t('roles.users') }}
              </p>
            </div>
            <UBadge :color="role.is_system ? 'neutral' : 'primary'" variant="subtle" :label="role.is_system ? t('roles.system') : t('roles.custom')" />
          </div>
        </template>
        <div class="flex flex-wrap gap-1">
          <UBadge v-for="p in role.permissions" :key="p" color="neutral" variant="outline" size="sm" :label="labelOf(p)" />
        </div>
        <template v-if="!role.is_system && can('roles.manage')" #footer>
          <div class="flex justify-end gap-2">
            <UButton size="sm" variant="ghost" icon="i-lucide-pencil" :label="t('common.edit')" @click="edit(role)" />
            <UButton size="sm" variant="ghost" color="error" icon="i-lucide-trash-2" :label="t('common.delete')" @click="remove(role)" />
          </div>
        </template>
      </UCard>
    </div>

    <USlideover :open="editing !== null" :title="editing?.id ? t('common.edit') : t('roles.new')" @update:open="(v: boolean) => { if (!v) editing = null }">
      <template #body>
        <form v-if="editing" class="space-y-4" @submit.prevent="save">
          <UAlert v-if="error" color="error" variant="subtle" :description="error" />
          <UFormField :label="t('roles.name')" required>
            <UInput v-model="editing.name" class="w-full" />
          </UFormField>
          <UFormField :label="t('roles.description')">
            <UInput v-model="editing.description" class="w-full" />
          </UFormField>
          <div v-for="(items, group) in grouped" :key="group">
            <p class="mb-2 text-sm font-semibold">
              {{ t(`roles.groups.${group}`) }}
            </p>
            <div class="grid gap-2">
              <UCheckbox
                v-for="p in items"
                :key="p.name"
                :model-value="editing.permissions.includes(p.name)"
                :label="p.label"
                @update:model-value="(v) => toggle(p.name, v)"
              />
            </div>
          </div>
          <UButton type="submit" block :label="t('common.save')" :disabled="!editing.name || !editing.permissions.length" />
        </form>
      </template>
    </USlideover>
  </div>
</template>
