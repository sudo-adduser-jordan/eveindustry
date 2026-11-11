import { act, renderHook } from '@testing-library/react'
import { vi } from 'vitest'
import {
  INSTALL_PROMPT_INTERVAL_MS, captureInstallPrompt, resetInstallForTests, useInstall,
} from './install'

function fireInstallEvent(outcome: 'accepted' | 'dismissed' = 'accepted') {
  const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
    prompt: vi.fn().mockResolvedValue(undefined),
    userChoice: Promise.resolve({ outcome }),
  })
  act(() => { window.dispatchEvent(event) })
  return event
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-26T12:00:00Z'))
  localStorage.clear()
  resetInstallForTests()
  captureInstallPrompt()
})

afterEach(() => vi.useRealTimers())

it('stays hidden until the browser says the app can be installed', () => {
  const { result } = renderHook(() => useInstall())
  expect(result.current.canInstall).toBe(false)
  expect(result.current.bannerOpen).toBe(false)
})

it('opens the banner when the browser offers installation and keeps the native prompt for the button', () => {
  const { result } = renderHook(() => useInstall())
  const event = fireInstallEvent()
  expect(event.defaultPrevented).toBe(true)
  expect(result.current.canInstall).toBe(true)
  expect(result.current.bannerOpen).toBe(true)
})

it('keeps showing the banner across reloads until it is dismissed, then stays away for 24 hours', () => {
  const first = renderHook(() => useInstall())
  fireInstallEvent()
  expect(first.result.current.bannerOpen).toBe(true)
  first.unmount()

  
  vi.setSystemTime(new Date('2026-10-26T18:00:00Z'))
  resetInstallForTests()
  captureInstallPrompt()
  const second = renderHook(() => useInstall())
  fireInstallEvent()
  expect(second.result.current.bannerOpen).toBe(true)
  act(() => second.result.current.dismiss())
  second.unmount()

  
  vi.setSystemTime(new Date('2026-10-27T10:00:00Z'))
  resetInstallForTests()
  captureInstallPrompt()
  const third = renderHook(() => useInstall())
  fireInstallEvent()
  expect(third.result.current.canInstall).toBe(true)
  expect(third.result.current.bannerOpen).toBe(false)
  third.unmount()

  vi.setSystemTime(new Date(Date.parse('2026-10-26T18:00:00Z') + INSTALL_PROMPT_INTERVAL_MS + 1))
  resetInstallForTests()
  captureInstallPrompt()
  const fourth = renderHook(() => useInstall())
  fireInstallEvent()
  expect(fourth.result.current.bannerOpen).toBe(true)
})

it('closes the banner on dismiss but keeps the install button available', () => {
  const { result } = renderHook(() => useInstall())
  fireInstallEvent()
  act(() => result.current.dismiss())
  expect(result.current.bannerOpen).toBe(false)
  expect(result.current.canInstall).toBe(true)
})

it('runs the native prompt on install and forgets the single-use event', async () => {
  const { result } = renderHook(() => useInstall())
  const event = fireInstallEvent('accepted')
  let outcome: string | undefined
  await act(async () => { outcome = await result.current.install() })
  expect(event.prompt).toHaveBeenCalledOnce()
  expect(outcome).toBe('accepted')
  expect(result.current.canInstall).toBe(false)
  expect(result.current.bannerOpen).toBe(false)
})

it('reports unavailable when there is nothing to install', async () => {
  const { result } = renderHook(() => useInstall())
  await expect(result.current.install()).resolves.toBe('unavailable')
})

it('hides everything once the app is installed', () => {
  const { result } = renderHook(() => useInstall())
  fireInstallEvent()
  act(() => { window.dispatchEvent(new Event('appinstalled')) })
  expect(result.current.canInstall).toBe(false)
  expect(result.current.bannerOpen).toBe(false)
})
