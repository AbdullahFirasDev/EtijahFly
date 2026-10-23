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

  if (!response.ok || !response._data?.token) {
    setResponseStatus(event, response.status)
    return response._data
  }

  writeAuthCookie(event, response._data.token, response._data.expires_at)

  // The token itself is never returned to the browser.
  return { user: response._data.user, expires_at: response._data.expires_at }
})
