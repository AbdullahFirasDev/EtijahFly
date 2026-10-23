import path from 'node:path'
import type { NextConfig } from 'next'

const securityHeaders = [
  { key: 'X-Frame-Options', value: 'DENY' },
  { key: 'X-Content-Type-Options', value: 'nosniff' },
  { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
  { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=()' },
  { key: 'X-Robots-Tag', value: 'noindex, nofollow' },
]

const nextConfig: NextConfig = {
  // Shared TypeScript contracts are consumed as source from the monorepo.
  transpilePackages: ['@etijahfly/api-contracts'],
  // Self-contained server for the Docker image; Vercel uses its own build output.
  output: process.env.VERCEL ? undefined : 'standalone',
  poweredByHeader: false,
  turbopack: {
    // monorepo root, so the workspace package and hoisted node_modules resolve
    root: path.join(__dirname, '..', '..'),
  },
  async headers() {
    return [{ source: '/:path*', headers: securityHeaders }]
  },
}

export default nextConfig
