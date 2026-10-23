import { IDEMPOTENCY_HEADER, newIdempotencyKey } from '@etijahfly/api-contracts'
import type { FetchError } from 'ofetch'
import { ApiError } from '~/utils/api-error'

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface RequestOptions {
  method?: Method
  body?: Record<string, unknown> | FormData
  query?: Record<string, unknown>
  /** true = generate a key; string = reuse a key (e.g. retry after a network error). */
  idempotent?: boolean | string
  responseType?: 'json' | 'blob'
}

/**
 * Client for the Laravel API through the portal's own server (/api/proxy/*).
 * Errors are normalised to ApiError; an expired session sends the user to /login.
 */
export function useApi() {
  const { locale } = useI18n()

  async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const headers: Record<string, string> = { 'Accept-Language': locale.value }
    if (options.idempotent) {
      headers[IDEMPOTENCY_HEADER] = typeof options.idempotent === 'string' ? options.idempotent : newIdempotencyKey()
    }

    try {
      return await $fetch<T>(`/api/proxy${path}`, {
        method: options.method ?? 'GET',
        body: options.body,
        query: options.query,
        headers,
        responseType: options.responseType,
      }) as T
    }
    catch (e) {
      const error = e as FetchError
      const apiError = new ApiError(error.statusCode ?? 0, (error.data ?? { message: error.message }) as Record<string, never>)
      if (apiError.status === 401 && !path.startsWith('/auth/me')) {
        useState('me').value = null
        await navigateTo({ path: '/login', query: { redirect: useRoute().fullPath } })
      }
      throw apiError
    }
  }

  return {
    request,
    get: <T>(path: string, query?: Record<string, unknown>) => request<T>(path, { query }),
    post: <T>(path: string, body?: RequestOptions['body'], extra: Omit<RequestOptions, 'body' | 'method'> = {}) => request<T>(path, { ...extra, method: 'POST', body }),
    put: <T>(path: string, body?: RequestOptions['body']) => request<T>(path, { method: 'PUT', body }),
    patch: <T>(path: string, body?: RequestOptions['body']) => request<T>(path, { method: 'PATCH', body }),
    del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  }
}
