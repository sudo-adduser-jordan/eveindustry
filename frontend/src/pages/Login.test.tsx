import { fireEvent, screen, waitFor } from '@testing-library/react'
import { vi } from 'vitest'
import Login from './Login'
import { me, renderWithData } from '../test/render'

afterEach(() => vi.unstubAllGlobals())

it('matches the public login panel: SSO link, demo button and helper copy', () => {
  renderWithData(<Login />)
  expect(screen.getByRole('heading', { name: 'Login' })).toBeInTheDocument()
  const sso = screen.getByRole('link', { name: 'Login with EVE Online' })
  expect(sso).toHaveAttribute('href', '/auth/eve/scopes')
  expect(sso).toHaveClass('button', 'primary')
  expect(screen.getByText('Review your permissions before continuing to the official EVE SSO page.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Try the demo' })).toBeEnabled()
  expect(screen.getByText('Explore with three generated characters. No EVE login needed; data resets daily.')).toBeInTheDocument()
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
