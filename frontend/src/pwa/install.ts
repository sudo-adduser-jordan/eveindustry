import { useSyncExternalStore } from 'react'

interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const DISMISSED_KEY = 'pwa-install-prompt-dismissed-at'
export const INSTALL_PROMPT_INTERVAL_MS = 24 * 60 * 60_000


let deferred: BeforeInstallPromptEvent | null = null
let bannerOpen = false
let captured = false
let snapshot = { canInstall: false, bannerOpen: false }
const listeners = new Set<() => void>()

function emit() {
  snapshot = { canInstall: deferred !== null, bannerOpen }
  listeners.forEach((listener) => listener())
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

export function isStandalone(): boolean {
  return window.matchMedia?.('(display-mode: standalone)').matches === true
    || (navigator as Navigator & { standalone?: boolean }).standalone === true
}


export function isIosInstallable(): boolean {
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  return ios && !isStandalone()
}

function lastDismissedAt(): number {
  try { return Number(localStorage.getItem(DISMISSED_KEY)) || 0 } catch { return 0 }
}

function markDismissed() {
  try { localStorage.setItem(DISMISSED_KEY, String(Date.now())) } catch {  }
}


function maybeOpenBanner() {
  if (bannerOpen || isStandalone() || (deferred === null && !isIosInstallable())) return
  if (Date.now() - lastDismissedAt() < INSTALL_PROMPT_INTERVAL_MS) return
  bannerOpen = true
  emit()
}

export function captureInstallPrompt() {
  if (captured || typeof window === 'undefined') return
  captured = true

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferred = event as BeforeInstallPromptEvent
    emit()
    maybeOpenBanner()
  })
  window.addEventListener('appinstalled', () => {
    deferred = null
    bannerOpen = false
    emit()
  })

  maybeOpenBanner()
}

export function dismissInstallBanner() {
  markDismissed()
  bannerOpen = false
  emit()
}

export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
  const event = deferred
  if (!event) return 'unavailable'

  await event.prompt()
  const { outcome } = await event.userChoice
  
  if (outcome === 'dismissed') markDismissed()
  deferred = null
  bannerOpen = false
  emit()
  return outcome
}

export function useInstall() {
  const state = useSyncExternalStore(subscribe, () => snapshot)
  return {
    canInstall: state.canInstall,
    bannerOpen: state.bannerOpen,
    ios: isIosInstallable(),
    install: promptInstall,
    dismiss: dismissInstallBanner,
  }
}

export function resetInstallForTests() {
  deferred = null
  bannerOpen = false
  captured = false
  emit()
}
