import { act, fireEvent, render, screen } from '@testing-library/react'
import { vi } from 'vitest'
import { captureInstallPrompt, resetInstallForTests } from '../pwa/install'
import { InstallPrompt } from './InstallPrompt'

function offerInstall() {
  const prompt = vi.fn().mockResolvedValue(undefined)
  const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
    prompt, userChoice: Promise.resolve({ outcome: 'accepted' as const }),
  })
  act(() => { window.dispatchEvent(event) })
  return prompt
}

beforeEach(() => {
  localStorage.clear()
  resetInstallForTests()
  captureInstallPrompt()
})

it('renders nothing until installation is offered', () => {
  const { container } = render(<InstallPrompt />)
  expect(container).toBeEmptyDOMElement()
})

it('offers install and closes when dismissed with Not now or the close button', () => {
  render(<InstallPrompt />)
  offerInstall()
  expect(screen.getByRole('dialog', { name: 'Install Eve Industry' })).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Not now' }))
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('closes with the x button', () => {
  render(<InstallPrompt />)
  offerInstall()
  fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }))
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('starts the native install prompt from the Install button', async () => {
  render(<InstallPrompt />)
  const prompt = offerInstall()
  await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Install' })) })
  expect(prompt).toHaveBeenCalledOnce()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})
