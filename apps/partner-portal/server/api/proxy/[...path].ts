/**
 * Authenticated pass-through to the Laravel API (/api/v1/...).
 * Only the API areas the partner portal needs are reachable (see utils/allowlist.ts).
 */
export default defineEventHandler(async (event) => {
  const path = getRouterParam(event, 'path') ?? ''
  if (!isAllowedApiPath(path)) {
    throw createError({ statusCode: 404, data: { message: 'Not found', code: 'NOT_FOUND' } })
  }

  assertSameOrigin(event)

  const token = readAuthToken(event)
  const { search } = getRequestURL(event)
  const headers: Record<string, string> = { ...forwardHeaders(event), cookie: '' }
  if (token) headers.authorization = `Bearer ${token}`

  const idempotencyKey = getRequestHeader(event, 'idempotency-key')
  if (idempotencyKey) headers['idempotency-key'] = idempotencyKey

  return proxyRequest(event, `${apiBase(event)}/${path}${search}`, {
    headers,
    onResponse(responseEvent, response) {
      if (response.status === 401) clearAuthCookie(responseEvent)
    },
  })
})
