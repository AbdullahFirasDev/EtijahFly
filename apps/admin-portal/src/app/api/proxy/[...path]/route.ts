import { NextResponse } from 'next/server'
import type { NextRequest } from 'next/server'
import { isAllowedApiPath } from '@/lib/allowlist'
import { apiBase, AUTH_COOKIE, clearAuthCookie, forwardHeaders, isSameOrigin } from '@/lib/server/session'

type Context = { params: Promise<{ path: string[] }> }

const PASS_HEADERS = ['content-type', 'content-disposition', 'idempotent-replayed', 'x-request-id', 'content-language', 'retry-after']

async function handle(request: NextRequest, { params }: Context) {
  const path = (await params).path.join('/')
  if (!isAllowedApiPath(path)) {
    return NextResponse.json({ message: 'Not found', code: 'NOT_FOUND' }, { status: 404 })
  }
  if (!isSameOrigin(request)) {
    return NextResponse.json({ message: 'Cross-origin request blocked', code: 'CSRF_ORIGIN_MISMATCH' }, { status: 403 })
  }

  const headers = forwardHeaders(request)
  const token = request.cookies.get(AUTH_COOKIE)?.value
  if (token) headers.authorization = `Bearer ${token}`

  const hasBody = !['GET', 'HEAD'].includes(request.method)
  const upstream = await fetch(`${apiBase()}/${path}${request.nextUrl.search}`, {
    method: request.method,
    headers,
    body: hasBody ? await request.arrayBuffer() : undefined,
    cache: 'no-store',
    redirect: 'manual',
  })

  const responseHeaders = new Headers()
  for (const name of PASS_HEADERS) {
    const value = upstream.headers.get(name)
    if (value) responseHeaders.set(name, value)
  }
  responseHeaders.set('cache-control', 'no-store')

  const response = new NextResponse(upstream.body, { status: upstream.status, headers: responseHeaders })
  if (upstream.status === 401) clearAuthCookie(response)
  return response
}

export const GET = handle
export const POST = handle
export const PUT = handle
export const PATCH = handle
export const DELETE = handle
