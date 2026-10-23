/**
 * API areas reachable through the partner portal proxy. Admin routes are deliberately absent:
 * even a hijacked partner session cannot reach /api/v1/admin through this portal.
 */
const ALLOWED: RegExp[] = [
  /^partner\/[\w\-/]+$/,
  /^auth\/(me|logout|sessions|sessions\/\d+|password|forgot-password|reset-password)$/,
  /^notifications(\/read-all|\/[0-9a-f-]{36}\/read)?$/,
  /^reference\/(airports|airlines)$/,
  /^public\/(branding|tenants\/[a-z0-9-]+\/logo)$/,
  /^invitations\/[A-Za-z0-9]{20,128}(\/accept)?$/,
]

export function isAllowedApiPath(path: string): boolean {
  if (!path || path.includes('..') || path.includes('//') || path.includes('\\')) return false

  return ALLOWED.some(rule => rule.test(path))
}
