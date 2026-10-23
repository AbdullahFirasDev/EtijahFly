export default defineEventHandler(async (event) => {
  assertSameOrigin(event)
  const token = readAuthToken(event)

  if (token) {
    await $fetch(`${apiBase(event)}/auth/logout`, {
      method: 'POST',
      headers: { ...forwardHeaders(event), authorization: `Bearer ${token}` },
      ignoreResponseError: true,
    }).catch(() => undefined)
  }

  clearAuthCookie(event)
  return { message: 'ok' }
})
