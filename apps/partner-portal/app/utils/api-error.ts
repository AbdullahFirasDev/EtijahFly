import type { ApiErrorBody } from '@etijahfly/api-contracts'

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: Partial<ApiErrorBody> = {},
  ) {
    super(body.message ?? `Request failed (${status})`)
  }

  get code(): string {
    return this.body.code ?? 'UNKNOWN'
  }

  get details(): Record<string, unknown> {
    return this.body.details ?? {}
  }

  /** Laravel validation errors flattened to the first message per field. */
  get fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [field, messages] of Object.entries(this.body.errors ?? {})) {
      if (messages[0]) out[field] = messages[0]
    }
    const nested = this.details.errors
    if (nested && typeof nested === 'object') {
      for (const [field, message] of Object.entries(nested as Record<string, string>)) out[field] ??= message
    }
    return out
  }
}

export function isApiError(e: unknown): e is ApiError {
  return e instanceof ApiError
}
