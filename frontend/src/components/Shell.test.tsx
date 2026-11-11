import { fireEvent, screen } from '@testing-library/react'
import { renderWithData } from '../test/render'
import { ensureLocalStorage } from '../test/storage'
import { Shell } from './Shell'

ensureLocalStorage()

beforeEach(() => localStorage.clear())

it('hides stock-manager navigation for accounts without the feature', () => {
  renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot', stock_manager: false, admin: false } }]])
  fireEvent.click(screen.getByRole('button', { name: /^Industry$/ }))
  expect(screen.queryByRole('link', { name: 'Stock Manager' })).not.toBeInTheDocument()
})

it('shows stock-manager navigation for enabled accounts', () => {
  renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot', stock_manager: true, admin: false } }]])
  fireEvent.click(screen.getByRole('button', { name: /^Industry$/ }))
  expect(screen.getByRole('link', { name: 'Stock Manager' })).toHaveAttribute('href', '/industry/stock-manager')
})

it('opens one original submenu at a time', () => {
  renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot', admin: false } }]], ['/dashboard'])
  fireEvent.click(screen.getByRole('button', { name: 'Assets' }))
  expect(screen.getByRole('link', { name: 'Character Assets' })).toBeVisible()
  fireEvent.click(screen.getByRole('button', { name: 'Industry Planning' }))
  expect(screen.queryByRole('link', { name: 'Character Assets' })).not.toBeInTheDocument()
})

it('toggles the slide-out menu from the burger and closes it with the overlay, close button and Escape', () => {
  const { container } = renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot', admin: false } }]], ['/dashboard'])
  const burger = screen.getByRole('button', { name: 'Toggle navigation' })
  const sidebar = container.querySelector('.sidebar')
  expect(burger).toHaveAttribute('aria-expanded', 'false')
  fireEvent.click(burger)
  expect(burger).toHaveAttribute('aria-expanded', 'true')
  expect(sidebar).toHaveClass('open')
  fireEvent.click(screen.getByRole('button', { name: 'Close navigation' }))
  expect(sidebar).not.toHaveClass('open')
  fireEvent.click(burger)
  fireEvent.click(container.querySelector('.sidebar-overlay') as Element)
  expect(sidebar).not.toHaveClass('open')
  fireEvent.click(burger)
  fireEvent.keyDown(document, { key: 'Escape' })
  expect(sidebar).not.toHaveClass('open')
})

it('opens the group containing a deep link and keeps admin navigation restricted', () => {
  renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot', admin: false } }]], ['/corporate/build-programs/42'])
  expect(screen.getByRole('button', { name: 'Corporate' })).toHaveAttribute('aria-expanded', 'true')
  expect(screen.getByRole('link', { name: 'Corp Build Programs' })).toHaveClass('active')
  expect(screen.queryByRole('button', { name: 'Admin' })).not.toBeInTheDocument()
})

it('remembers the open group across reloads', () => {
  const view = renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot' } }]], ['/dashboard'])
  fireEvent.click(screen.getByRole('button', { name: 'Planetary' }))
  view.unmount()
  renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot' } }]], ['/dashboard'])
  expect(screen.getByRole('button', { name: 'Planetary' })).toHaveAttribute('aria-expanded', 'true')
})

it('hides the work-in-progress Discord bot from admins until its feature switch is on', () => {
  const view = renderWithData(<Shell />, [[['me'], { data: { display_name: 'Admin', admin: true, features: { discord_bot: false } } }]], ['/admin/dashboard'])
  expect(screen.getByRole('link', { name: 'ESI Diagnostics' })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /Discord Bot/ })).not.toBeInTheDocument()
  view.unmount()
  renderWithData(<Shell />, [[['me'], { data: { display_name: 'Admin', admin: true, features: { discord_bot: true } } }]], ['/admin/dashboard'])
  expect(screen.getByRole('link', { name: 'Discord Bot (WIP)' })).toHaveAttribute('href', '/admin/discord-bot')
})

it('switches themes from the sidebar and remembers the choice', () => {
  renderWithData(<Shell />, [[['me'], { data: { display_name: 'Pilot', admin: false } }]], ['/dashboard'])
  const select = screen.getByLabelText('Theme')
  expect(select).toHaveValue('industrialist')
  expect(screen.getByRole('option', { name: 'EVE Online' })).toBeInTheDocument()
  expect(screen.getByRole('option', { name: 'Light' })).toBeInTheDocument()
  fireEvent.change(select, { target: { value: 'eve-online' } })
  expect(select).toHaveValue('eve-online')
  expect(document.documentElement.dataset.theme).toBe('eve-online')
  expect(localStorage.getItem('eve-industry-theme')).toBe('eve-online')
})
