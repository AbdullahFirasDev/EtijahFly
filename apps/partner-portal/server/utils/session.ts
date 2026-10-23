import type { H3Event } from 'h3'

/**
 * Backend-for-frontend session handling.
 *
 * The Laravel API token never reaches browser JavaScript: it lives in an httpOnly,
 * SameSite=Strict cookie on the portal's own origin and is attached server-side when the
 * request is proxied to the API. State-changing requests must come from this origin
 * (Origin header check) - together with SameSite=Strict this blocks CSRF.
 */
export const AUTH_COOKIE = 'ef_partner_session'

const LOCAL_API = /^https?:\/\/(127\.0\.0\.1|localhost)(:|\/|$)/

export function apiBase(event: H3Event): string {
  const base = String(useRuntimeConfig(event).apiBase).replace(/\/+$/, '')
  // A deployed portal must be pointed at the real API (NUXT_API_BASE); fail clearly instead of
  // trying a local address that does not exist in the hosting environment.
  if (process.env.VERCEL && (!base || LOCAL_API.test(base))) {
    console.error('[portal] NUXT_API_BASE is not configured for this deployment')
    throw createError({ statusCode: 503, statusMessage: 'API not configured' })
  }
  // Pointing NUXT_API_BASE at the portal itself makes every API call return the SPA's HTML.
  if (originHost(base) === getRequestHost(event, { xForwardedHost: true })) {
    console.error('[portal] NUXT_API_BASE points at this portal instead of the Laravel API')
    throw createError({ statusCode: 503, statusMessage: 'API not configured' })
  }
  return base
}

export function readAuthToken(event: H3Event): string | undefined {
  return getCookie(event, AUTH_COOKIE)
}

export function writeAuthCookie(event: H3Event, token: string, expiresAt?: string | null): void {
  setCookie(event, AUTH_COOKIE, token, {
    httpOnly: true,
    sameSite: 'strict',
    secure: Boolean(useRuntimeConfig(event).cookieSecure),
    path: '/',
    expires: expiresAt ? new Date(expiresAt) : undefined,
  })
}

export function clearAuthCookie(event: H3Event): void {
  deleteCookie(event, AUTH_COOKIE, { path: '/' })
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

function originHost(value: string): string | null {
  try {
    return new URL(value).host
  }
  catch {
    return null
  }
}

export function assertSameOrigin(event: H3Event): void {
  if (SAFE_METHODS.has(event.method)) return

  const origin = getRequestHeader(event, 'origin') ?? getRequestHeader(event, 'referer')
  const host = getRequestHost(event, { xForwardedHost: true })

  if (!origin || originHost(origin) !== host) {
    throw createError({ statusCode: 403, statusMessage: 'Forbidden', data: { message: 'Cross-origin request blocked', code: 'CSRF_ORIGIN_MISMATCH' } })
  }
}

/** Headers forwarded to the API: locale, client IP (for rate limiting/audit), request id. */
export function forwardHeaders(event: H3Event): Record<string, string> {
  const headers: Record<string, string> = { accept: 'application/json' }
  const lang = getRequestHeader(event, 'accept-language')
  const ua = getRequestHeader(event, 'user-agent')
  const requestId = getRequestHeader(event, 'x-request-id')
  const ip = getRequestIP(event, { xForwardedFor: true })

  if (lang) headers['accept-language'] = lang
  if (ua) headers['user-agent'] = ua
  if (requestId) headers['x-request-id'] = requestId
  if (ip) headers['x-forwarded-for'] = ip
  headers['x-forwarded-host'] = getRequestHost(event, { xForwardedHost: true })

  const secret = String(useRuntimeConfig(event).portalProxySecret ?? '')
  if (secret && ip) {
    headers['x-portal-secret'] = secret
    headers['x-portal-client-ip'] = ip
  }

  return headers
}
