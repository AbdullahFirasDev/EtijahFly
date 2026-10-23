import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'
import { apiBase, forwardHeaders, isSameOrigin, writeAuthCookie } from '@/lib/server/session'

export async function POST(request: NextRequest) {
  if (!isSameOrigin(request)) {
    return NextResponse.json({ message: 'Cross-origin request blocked', code: 'CSRF_ORIGIN_MISMATCH' }, { status: 403 })
  }

  const body = (await request.json().catch(() => ({}))) as { email?: string, password?: string }
  const response = await fetch(`${apiBase()}/auth/login`, {
    method: 'POST',
    headers: { ...forwardHeaders(request), 'content-type': 'application/json' },
    body: JSON.stringify({ email: body.email, password: body.password, portal: 'admin' }),
    cache: 'no-store',
  })
  const data = await response.json().catch(() => ({ message: 'Login failed', code: 'UPSTREAM_ERROR' }))

  if (!response.ok || !data.token) {
    return NextResponse.json(data, { status: response.status })
  }

  // The token stays server-side; the browser only gets the httpOnly cookie.
  const result = NextResponse.json({ user: data.user, expires_at: data.expires_at })
  writeAuthCookie(result, data.token, data.expires_at)
  return result
}
