import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from './schema'
import { leaveSession } from './persistence'

/** Laravel's `{message, errors?, code}` error body. */
export interface ApiErrorBody {
  message?: string
  errors?: Record<string, string[]>
  code?: string
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string | undefined
  readonly errors: Record<string, string[]> | undefined

  constructor(status: number, body: ApiErrorBody | undefined) {
    super(body?.message ?? `Request failed (${status})`)
    this.name = 'ApiError'
    this.status = status
    this.code = body?.code
    this.errors = body?.errors
  }
}

const WRITE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

let csrfReady: Promise<void> | null = null

/** Sanctum SPA flow: GET /sanctum/csrf-cookie once, then echo XSRF-TOKEN. */
function ensureCsrfCookie(): Promise<void> {
  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined)
  return csrfReady
}

/** Where a signed-out visitor goes: the in-app login page. */
export const LOGIN_URL = '/app/login'

export const authMiddleware: Middleware = {
  async onRequest({ request }) {
    request.headers.set('Accept', 'application/json')
    request.headers.set('X-Requested-With', 'XMLHttpRequest')
    if (WRITE_METHODS.has(request.method)) {
      await ensureCsrfCookie()
      const token = readCookie('XSRF-TOKEN')
      if (token) request.headers.set('X-XSRF-TOKEN', token)
    }
    return request
  },
  async onResponse({ response }) {
    // 401 never renders as a page error: the session is gone, sign in again.
    // Except on the login page itself, which renders from the 401 state —
    // without this guard it would reload itself forever.
    if (response.status === 401 && window.location.pathname !== LOGIN_URL) {
      await leaveSession(LOGIN_URL)
    }
    return response
  },
}

export const api = createClient<paths>({ baseUrl: '/api/v1', credentials: 'same-origin' })
api.use(authMiddleware)

/** Unwraps an openapi-fetch result: returns `data`, throws ApiError otherwise. */
export function unwrap<T>(result: { data?: T; error?: unknown; response: Response }): T {
  if (result.data === undefined) {
    throw new ApiError(result.response.status, result.error as ApiErrorBody | undefined)
  }
  return result.data
}
