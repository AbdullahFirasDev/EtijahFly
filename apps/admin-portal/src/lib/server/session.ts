import 'server-only'
import type { NextRequest, NextResponse } from 'next/server'

/**
 * Backend-for-frontend session: the Laravel API token is kept in an httpOnly, SameSite=Strict
 * cookie on the admin portal's own origin and attached server-side when proxying.
 */
export const AUTH_COOKIE = 'ef_admin_session'

const LOCAL_API = /^https?:\/\/(127\.0\.0\.1|localhost)(:|\/|$)/

export function apiBase(): string {
  const base = (process.env.API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1').replace(/\/+$/, '')
  // A deployed portal must be pointed at the real API (API_BASE_URL); fail clearly instead of
  // trying a local address that does not exist in the hosting environment.
  if (process.env.VERCEL && LOCAL_API.test(base)) {
    console.error('[admin] API_BASE_URL is not configured for this deployment')
    throw new Error('API_BASE_URL is not configured')
  }
  return base
}

export function writeAuthCookie(response: NextResponse, token: string, expiresAt?: string | null): void {
  response.cookies.set(AUTH_COOKIE, token, {
    httpOnly: true,
    sameSite: 'strict',
    secure: process.env.NODE_ENV === 'production',
    path: '/',
    expires: expiresAt ? new Date(expiresAt) : undefined,
  })
}

export function clearAuthCookie(response: NextResponse): void {
  response.cookies.delete(AUTH_COOKIE)
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

/** CSRF defence for state-changing requests: the Origin must be this portal. */
export function isSameOrigin(request: NextRequest): boolean {
  if (SAFE_METHODS.has(request.method)) return true

  const origin = request.headers.get('origin') ?? request.headers.get('referer')
  const host = request.headers.get('x-forwarded-host') ?? request.headers.get('host')
  if (!origin || !host) return false

  try {
    return new URL(origin).host === host
  }
  catch {
    return false
  }
}

export function forwardHeaders(request: NextRequest): Record<string, string> {
  const headers: Record<string, string> = { accept: 'application/json' }
  for (const name of ['accept-language', 'user-agent', 'x-request-id', 'content-type', 'idempotency-key']) {
    const value = request.headers.get(name)
    if (value) headers[name] = value
  }

  const forwardedFor = request.headers.get('x-forwarded-for')?.split(',')[0]?.trim()
  headers['x-forwarded-for'] = forwardedFor || '127.0.0.1'
  // Lets the API trust the user's IP when it runs on a separate host (see TrustPortalClientIp).
  const secret = process.env.PORTAL_PROXY_SECRET
  if (secret && forwardedFor) {
    headers['x-portal-secret'] = secret
    headers['x-portal-client-ip'] = forwardedFor
  }
  const host = request.headers.get('host')
  if (host) headers['x-forwarded-host'] = host

  return headers
}
