/** jsdom in this repo's toolchain does not always provide Web Storage
 *  (e.g. newer Node runtimes); install a minimal in-memory stand-in so
 *  persistence tests run anywhere. A no-op when the real one exists. */
export function ensureLocalStorage(): void {
  if (typeof localStorage !== 'undefined') return
  const store = new Map<string, string>()
  Object.defineProperty(globalThis, 'localStorage', {
    value: {
      get length() {
        return store.size
      },
      key: (index: number) => [...store.keys()][index] ?? null,
      getItem: (key: string) => (store.has(key) ? (store.get(key) as string) : null),
      setItem: (key: string, value: string) => {
        store.set(key, String(value))
      },
      removeItem: (key: string) => {
        store.delete(key)
      },
      clear: () => {
        store.clear()
      },
    },
    configurable: true,
    writable: true,
  })
}
