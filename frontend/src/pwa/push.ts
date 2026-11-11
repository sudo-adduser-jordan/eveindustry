import { useCallback, useEffect, useState } from 'react'

export type DeviceState = 'loading' | 'unsupported' | 'denied' | 'off' | 'on'

const SCOPE = '/app/'

export function pushSupported(): boolean {
  return typeof navigator !== 'undefined' && 'serviceWorker' in navigator
    && typeof window !== 'undefined' && 'PushManager' in window && 'Notification' in window
}


async function registration() {
  return pushSupported() ? navigator.serviceWorker.getRegistration(SCOPE) : undefined
}

export async function currentSubscription(): Promise<PushSubscription | null> {
  const reg = await registration()
  return reg ? reg.pushManager.getSubscription() : null
}

function keyBytes(base64Url: string): Uint8Array {
  const padded = base64Url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64Url.length / 4) * 4, '=')
  return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0))
}


export async function subscribeBrowser(vapidPublicKey: string): Promise<PushSubscription> {
  const reg = await registration()
  if (!reg) throw new Error('Notifications are only available in the installed or production build of the app.')

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('Notification permission was not granted. Allow notifications for this site in your browser settings.')

  return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(vapidPublicKey) })
}

export function subscriptionBody(subscription: PushSubscription) {
  const json = subscription.toJSON()
  return { endpoint: subscription.endpoint, keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' } }
}


export function useDeviceState() {
  const [state, setState] = useState<DeviceState>('loading')

  const refresh = useCallback(async () => {
    if (!pushSupported() || !(await registration())) return setState('unsupported')
    if (Notification.permission === 'denied') return setState('denied')
    setState((await currentSubscription()) ? 'on' : 'off')
  }, [])

  useEffect(() => { void refresh() }, [refresh])

  return { state, refresh }
}
