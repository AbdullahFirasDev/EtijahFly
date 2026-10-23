interface LoginResponse {
  token: string
  expires_at: string | null
  user: unknown
}

export default defineEventHandler(async (event) => {
  assertSameOrigin(event)
  const body = await readBody<{ email?: string, password?: string }>(event)

  const response = await $fetch.raw<LoginResponse>(`${apiBase(event)}/auth/login`, {
    method: 'POST',
    headers: forwardHeaders(event),
    body: { email: body?.email, password: body?.password, portal: 'partner' },
    ignoreResponseError: true,
  })

  if (!response.ok) {
    setResponseStatus(event, response.status)
    return response._data
  }

  // A 2xx without a token did not come from the Laravel API (e.g. a misrouted NUXT_API_BASE).
  if (!response._data?.token) {
    throw createError({ statusCode: 502, statusMessage: 'Bad Gateway', data: { message: 'Bad gateway', code: 'API_UNAVAILABLE' } })
  }

  writeAuthCookie(event, response._data.token, response._data.expires_at)

  // The token itself is never returned to the browser.
  return { user: response._data.user, expires_at: response._data.expires_at }
})
