import { IDEMPOTENCY_HEADER, newIdempotencyKey } from '@etijahfly/api-contracts'
import type { ApiErrorBody } from '@etijahfly/api-contracts'

export const UNAUTHORIZED_EVENT = 'etijahfly:unauthorized'

export class ApiError extends Error {
  constructor(public readonly status: number, public readonly body: Partial<ApiErrorBody> = {}) {
    super(body.message ?? `Request failed (${status})`)
  }

  get code(): string {
    return this.body.code ?? 'UNKNOWN'
  }

  get fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [field, messages] of Object.entries(this.body.errors ?? {})) {
      if (messages[0]) out[field] = messages[0]
    }
    return out
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Query
  idempotent?: boolean | string
}

function buildQuery(query?: Query): string {
  if (!query) return ''
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const s = params.toString()
  return s ? `?${s}` : ''
}

/** Browser client for the Laravel API via the admin portal's own server (/api/proxy/*). */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { accept: 'application/json' }
  const locale = typeof document !== 'undefined' ? document.documentElement.lang : 'ar'
  headers['accept-language'] = locale || 'ar'
  if (options.idempotent) {
    headers[IDEMPOTENCY_HEADER] = typeof options.idempotent === 'string' ? options.idempotent : newIdempotencyKey()
  }

  let body: BodyInit | undefined
  if (options.body instanceof FormData) {
    body = options.body
  }
  else if (options.body !== undefined) {
    headers['content-type'] = 'application/json'
    body = JSON.stringify(options.body)
  }

  const response = await fetch(`/api/proxy${path}${buildQuery(options.query)}`, {
    method: options.method ?? 'GET',
    headers,
    body,
    credentials: 'same-origin',
  })

  const isJson = response.headers.get('content-type')?.includes('application/json')
  const data = isJson ? await response.json() : undefined

  if (!response.ok) {
    if (response.status === 401 && typeof window !== 'undefined' && !path.startsWith('/auth/me')) {
      // The session ended (expired, revoked, user disabled): AuthProvider sends the user to /login.
      window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
    }
    throw new ApiError(response.status, (data ?? {}) as Partial<ApiErrorBody>)
  }

  return data as T
}

export const http = {
  get: <T>(path: string, query?: Query) => api<T>(path, { query }),
  post: <T>(path: string, body?: unknown, extra: Omit<RequestOptions, 'body' | 'method'> = {}) => api<T>(path, { ...extra, method: 'POST', body }),
  put: <T>(path: string, body?: unknown) => api<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => api<T>(path, { method: 'PATCH', body }),
  del: <T>(path: string) => api<T>(path, { method: 'DELETE' }),
}
