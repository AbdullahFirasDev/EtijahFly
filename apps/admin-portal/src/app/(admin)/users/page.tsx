'use client'

import { formatDate } from '@etijahfly/api-contracts'
import type { Invitation, Role, User } from '@etijahfly/api-contracts'
import { UserPlus } from 'lucide-react'
import { useState } from 'react'
import { RequirePermission } from '@/components/shell'
import { Alert, Badge, Button, Card, DataTable, Dialog, Field, Input, PageHeader, StatusBadge } from '@/components/ui'
import { ApiError, http } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useI18n } from '@/lib/i18n'
import { useQuery } from '@/lib/use-query'

export default function UsersPage() {
  const { t, locale } = useI18n()
  const { me } = useAuth()
  const { data: users, refresh } = useQuery('platform-users', () => http.get<{ data: User[] }>('/admin/users'))
  const { data: roles } = useQuery('platform-roles', () => http.get<{ data: Role[] }>('/admin/roles'))
  const { data: invitations, refresh: refreshInvites } = useQuery('platform-invites', () => http.get<{ data: Invitation[] }>('/admin/users/invitations'))
  const platformRoles = (roles?.data ?? []).filter(r => r.scope === 'platform')

  const [inviteOpen, setInviteOpen] = useState(false)
  const [invite, setInvite] = useState({ email: '', name: '', roles: ['platform_staff'] })
  const [rolesFor, setRolesFor] = useState<User | null>(null)
  const [selected, setSelected] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)

  function toggleRole(list: string[], name: string) {
    return list.includes(name) ? list.filter(r => r !== name) : [...list, name]
  }

  async function sendInvite() {
    setError(null)
    try {
      await http.post('/admin/users/invitations', invite)
      setInviteOpen(false)
      await refreshInvites()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  async function saveRoles() {
    if (!rolesFor) return
    setError(null)
    try {
      await http.put(`/admin/users/${rolesFor.id}/roles`, { roles: selected })
      setRolesFor(null)
      await refresh()
    }
    catch (e) {
      setError(e instanceof ApiError ? e.message : t('common.error'))
    }
  }

  async function toggleStatus(user: User) {
    await http.put(`/admin/users/${user.id}/status`, { status: user.status === 'active' ? 'disabled' : 'active' })
    await refresh()
  }

  return (
    <RequirePermission permission="platform.users.manage">
      <PageHeader title={t('users.title')} actions={<Button onClick={() => { setInviteOpen(true); setError(null) }}><UserPlus className="size-4" />{t('users.invite')}</Button>} />
      <Card padded={false}>
        <DataTable
          rows={users?.data}
          rowKey={u => u.id}
          columns={[
            { key: 'name', header: t('common.name'), cell: u => <div><p className="font-medium">{u.name}</p><p className="ltr text-xs text-slate-500">{u.email}</p></div> },
            { key: 'roles', header: t('users.roles'), cell: u => <div className="flex flex-wrap gap-1">{u.roles?.map(r => <Badge key={r.name} tone="info">{r.label}</Badge>)}</div> },
            { key: 'status', header: t('common.status'), cell: u => <StatusBadge status={u.status} /> },
            { key: 'login', header: t('users.lastLogin'), cell: u => formatDate(u.last_login_at, locale, true) },
            { key: 'actions', header: '', cell: u => u.id !== me?.id && (
              <div className="flex gap-1">
                <Button size="sm" variant="ghost" onClick={() => { setRolesFor(u); setSelected((u.roles ?? []).map(r => r.name)); setError(null) }}>{t('users.roles')}</Button>
                <Button size="sm" variant="ghost" onClick={() => toggleStatus(u)}>{u.status === 'active' ? t('users.disable') : t('users.enable')}</Button>
              </div>
            ) },
          ]}
        />
      </Card>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card title={t('users.rolesTitle')}>
          <ul className="space-y-3">
            {platformRoles.map(r => (
              <li key={r.id}>
                <p className="flex items-center gap-2 font-medium">{r.label}<Badge>{r.is_system ? t('users.system') : t('users.custom')}</Badge><span className="text-xs text-slate-500">({r.users_count ?? 0})</span></p>
                <p className="ltr mt-1 text-xs text-slate-500">{(r.permissions ?? []).join(' · ')}</p>
              </li>
            ))}
          </ul>
        </Card>
        <Card title={t('tenants.invitations')} padded={false}>
          <DataTable
            rows={invitations?.data}
            rowKey={i => i.id}
            columns={[
              { key: 'email', header: t('common.email'), cell: i => <span className="ltr">{i.email}</span> },
              { key: 'roles', header: t('users.roles'), cell: i => i.roles.join(', ') },
              { key: 'status', header: t('common.status'), cell: i => <StatusBadge status={i.status} /> },
            ]}
          />
        </Card>
      </div>

      <Dialog open={inviteOpen} title={t('users.invite')} onClose={() => setInviteOpen(false)} footer={<Button onClick={sendInvite} disabled={!invite.email || !invite.roles.length}>{t('users.invite')}</Button>}>
        <div className="space-y-3">
          {error && <Alert tone="error">{error}</Alert>}
          <Field label={t('common.email')} required>{id => <Input id={id} type="email" dir="ltr" value={invite.email} onChange={e => setInvite(f => ({ ...f, email: e.target.value }))} />}</Field>
          <Field label={t('common.name')}>{id => <Input id={id} value={invite.name} onChange={e => setInvite(f => ({ ...f, name: e.target.value }))} />}</Field>
          <fieldset className="space-y-1">
            <legend className="text-sm font-medium">{t('users.roles')}</legend>
            {platformRoles.map(r => (
              <label key={r.name} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={invite.roles.includes(r.name)} onChange={() => setInvite(f => ({ ...f, roles: toggleRole(f.roles, r.name) }))} />
                {r.label}
              </label>
            ))}
          </fieldset>
        </div>
      </Dialog>

      <Dialog open={rolesFor !== null} title={`${t('users.roles')} · ${rolesFor?.name ?? ''}`} onClose={() => setRolesFor(null)} footer={<Button onClick={saveRoles} disabled={!selected.length}>{t('common.save')}</Button>}>
        <div className="space-y-2">
          {error && <Alert tone="error">{error}</Alert>}
          {platformRoles.map(r => (
            <label key={r.name} className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={selected.includes(r.name)} onChange={() => setSelected(s => toggleRole(s, r.name))} />
              {r.label}
            </label>
          ))}
        </div>
      </Dialog>
    </RequirePermission>
  )
}
