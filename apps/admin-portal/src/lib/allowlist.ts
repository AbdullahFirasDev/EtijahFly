/**
 * API areas reachable through the admin portal proxy. Partner routes are deliberately absent.
 */
const ALLOWED: RegExp[] = [
  /^admin\/[\w\-/:]+$/,
  /^auth\/(me|logout|sessions|sessions\/\d+|password|forgot-password|reset-password)$/,
  /^notifications(\/read-all|\/[0-9a-f-]{36}\/read)?$/,
  /^reference\/(airports|airlines)$/,
  /^invitations\/[A-Za-z0-9]{20,128}(\/accept)?$/,
]

export function isAllowedApiPath(path: string): boolean {
  if (!path || path.includes('..') || path.includes('//') || path.includes('\\')) return false

  return ALLOWED.some(rule => rule.test(path))
}
