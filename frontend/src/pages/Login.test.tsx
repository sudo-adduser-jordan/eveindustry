import { fireEvent, screen, waitFor } from '@testing-library/react'
import { vi } from 'vitest'
import Login from './Login'
import { me, renderWithData } from '../test/render'

afterEach(() => vi.unstubAllGlobals())

it('shows a disabled SSO button and an enabled demo button', () => {
  renderWithData(<Login />)
  expect(screen.getByRole('button', { name: 'Log in with EVE Online' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Try the demo' })).toBeEnabled()
})

it('sends signed-in visitors straight to the dashboard', () => {
  renderWithData(<Login />, [[['me'], me([])]])
  expect(screen.queryByRole('button', { name: 'Try the demo' })).not.toBeInTheDocument()
})

it('reports when demo mode is off instead of leaving the page', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 404 })))
  renderWithData(<Login />)
  fireEvent.click(screen.getByRole('button', { name: 'Try the demo' }))
  await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Demo mode is off'))
})

it('navigates to the app after demo sign-in', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: { redirect: '/app/dashboard' } }), { status: 200 }),
    ),
  )
  const assign = vi.fn()
  vi.stubGlobal('location', { assign })
  renderWithData(<Login />)
  fireEvent.click(screen.getByRole('button', { name: 'Try the demo' }))
  await waitFor(() => expect(assign).toHaveBeenCalledWith('/app/dashboard'))
})
