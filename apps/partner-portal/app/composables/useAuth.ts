import type { Me } from '@etijahfly/api-contracts'
import { ApiError } from '~/utils/api-error'

export function useAuth() {
  const me = useState<Me | null>('me', () => null)
  const loaded = useState<boolean>('me-loaded', () => false)

  async function fetchMe(): Promise<Me | null> {
    try {
      const res = await $fetch<Me>('/api/proxy/auth/me')
      // Only a real profile is a session: a misrouted proxy can answer 200 with an HTML page.
      me.value = res && typeof res === 'object' && Array.isArray(res.permissions) ? res : null
    }
    catch {
      me.value = null
    }
    loaded.value = true
    return me.value
  }

  async function login(email: string, password: string): Promise<Me> {
    try {
      const res = await $fetch<{ user: Me }>('/api/auth/login', { method: 'POST', body: { email, password } })
      me.value = res.user
      loaded.value = true
      return res.user
    }
    catch (e) {
      const err = e as { statusCode?: number, data?: Record<string, never> }
      throw new ApiError(err.statusCode ?? 0, err.data ?? {})
    }
  }

  async function logout(): Promise<void> {
    await $fetch('/api/auth/logout', { method: 'POST' }).catch(() => undefined)
    me.value = null
    await navigateTo('/login')
  }

  function can(permission: string): boolean {
    return me.value?.permissions.includes(permission) ?? false
  }

  function canAny(...permissions: string[]): boolean {
    return permissions.some(can)
  }

  return { me, loaded, fetchMe, login, logout, can, canAny }
}
