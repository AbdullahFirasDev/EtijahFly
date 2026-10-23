import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'
import { apiBase, AUTH_COOKIE, clearAuthCookie, forwardHeaders, isSameOrigin } from '@/lib/server/session'

export async function POST(request: NextRequest) {
  if (!isSameOrigin(request)) {
    return NextResponse.json({ message: 'Cross-origin request blocked', code: 'CSRF_ORIGIN_MISMATCH' }, { status: 403 })
  }

  const token = request.cookies.get(AUTH_COOKIE)?.value
  if (token) {
    await fetch(`${apiBase()}/auth/logout`, {
      method: 'POST',
      headers: { ...forwardHeaders(request), authorization: `Bearer ${token}` },
      cache: 'no-store',
    }).catch(() => undefined)
  }

  const response = NextResponse.json({ message: 'ok' })
  clearAuthCookie(response)
  return response
}
