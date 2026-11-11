import { useSyncExternalStore } from 'react'


let applyWorkerUpdate: (() => Promise<void>) | null = null
let updateReady = false
const listeners = new Set<() => void>()

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

export function markUpdateReady(apply: () => Promise<void>) {
  applyWorkerUpdate = apply
  updateReady = true
  listeners.forEach((listener) => listener())
}

export function useUpdateReady() {
  return useSyncExternalStore(subscribe, () => updateReady)
}

export function applyUpdate() {
  void applyWorkerUpdate?.()
}

export function resetUpdateState() {
  applyWorkerUpdate = null
  updateReady = false
  listeners.forEach((listener) => listener())
}
