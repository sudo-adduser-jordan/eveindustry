import { vi } from 'vitest'
import { ApiError, authMiddleware, LOGIN_URL, unwrap } from './client'
import { leaveSession } from './persistence'

vi.mock('./persistence', () => ({ leaveSession: vi.fn() }))

beforeEach(() => {
  vi.clearAllMocks()
  window.history.pushState({}, '', '/')
})

async function respond401() {
  const param = {
    request: new Request('http://localhost/x'),
    response: new Response(null, { status: 401 }),
  } as never
  await authMiddleware.onResponse!(param)
}

describe('authMiddleware 401 handling', () => {
  it('sends app pages to the login page', async () => {
    window.history.pushState({}, '', '/app/dashboard')
    await respond401()
    expect(leaveSession).toHaveBeenCalledWith(LOGIN_URL)
  })

  it('stays put on the login page instead of reloading it', async () => {
    window.history.pushState({}, '', LOGIN_URL)
    await respond401()
    expect(leaveSession).not.toHaveBeenCalled()
  })
})

describe('unwrap', () => {
  it('returns data on success', () => {
    expect(unwrap({ data: { ok: 1 }, response: new Response() })).toEqual({ ok: 1 })
  })

  it('throws ApiError carrying the code and validation errors', () => {
    const response = new Response(null, { status: 422 })
    const error = { message: 'Invalid', code: 'validation_failed', errors: { q: ['required'] } }
    try {
      unwrap({ error, response })
      expect.unreachable()
    } catch (e) {
      expect(e).toBeInstanceOf(ApiError)
      expect((e as ApiError).status).toBe(422)
      expect((e as ApiError).code).toBe('validation_failed')
      expect((e as ApiError).errors).toEqual({ q: ['required'] })
    }
  })
})
