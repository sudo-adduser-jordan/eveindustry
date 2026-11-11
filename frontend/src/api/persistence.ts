import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister'
import { onlineManager, type Query, type QueryClient } from '@tanstack/react-query'
import { createStore, del, get, set, type UseStore } from 'idb-keyval'

declare const __APP_BUILD__: string

/** Persisted data is discarded after a day and whenever a new build ships. */
export const PERSIST_MAX_AGE = 24 * 60 * 60_000
export const PERSIST_BUSTER = __APP_BUILD__

const CACHE_KEY = 'query-cache'
const ROOTS = new Set(['me', 'reference', 'plans', 'industry'])

/**
 * Allowlist of query keys saved for the next visit: the signed-in user and
 * characters, reference data, plans and the small per-user overviews. Searches,
 * live market data, paginated assets and location feeds are deliberately left out.
 */
export function shouldPersist(key: readonly unknown[]): boolean {
  const [root, sub, third] = key
  if (typeof root === 'string' && ROOTS.has(root)) return true
  switch (root) {
    case 'assets': return sub === 'materials' || sub === 'ships' || sub === 'abyssal'
    case 'calculators': return sub === 'reactions' && third === 'skills'
    case 'moon-scans': return sub === 'systems'
    default: return false
  }
}

export function shouldDehydrate(query: Query): boolean {
  return query.state.status === 'success' && shouldPersist(query.queryKey)
}

let store: UseStore | undefined
function idb() {
  store ??= createStore('eve-industrialist', 'cache')
  return store
}

// Set while signing out so a throttled write cannot resurrect the cache.
let suspended = false

// IndexedDB can be missing or throw (private windows, quota); the app then
// simply runs without persistence.
const storage = {
  getItem: async (key: string) => {
    try { return (await get<string>(key, idb())) ?? null } catch { return null }
  },
  setItem: async (key: string, value: string) => {
    if (suspended) return
    try { await set(key, value, idb()) } catch { /* persistence is best effort */ }
  },
  removeItem: async (key: string) => {
    try { await del(key, idb()) } catch { /* persistence is best effort */ }
  },
}

export const persister = createAsyncStoragePersister({ storage, key: CACHE_KEY, throttleTime: 1000 })

export async function clearPersistedCache() {
  suspended = true
  await storage.removeItem(CACHE_KEY)
}

/** Wipe stored data, then leave the SPA for a server-rendered page. */
export async function leaveSession(url: string) {
  await Promise.race([clearPersistedCache(), new Promise((resolve) => setTimeout(resolve, 1000))])
  window.location.assign(url)
}

function userIdOf(query: Query): number | undefined {
  const id = (query.state.data as { data?: { id?: unknown } } | undefined)?.data?.id
  return typeof id === 'number' ? id : undefined
}

/**
 * The stored cache belongs to whoever was signed in when it was written. If the
 * session now resolves to a different user (re-login), drop
 * every other query rather than show that user's data to the wrong person.
 */
export function watchSignedInUser(client: QueryClient) {
  let userId: number | undefined
  return client.getQueryCache().subscribe((event) => {
    if (event.query.queryKey[0] !== 'me') return
    if (event.type !== 'added' && !(event.type === 'updated' && event.action.type === 'success')) return
    const id = userIdOf(event.query)
    if (id === undefined) return
    if (userId !== undefined && id !== userId) {
      void client.resetQueries({ predicate: (query) => query.queryKey[0] !== 'me' })
    }
    userId = id
  })
}

/** Linked characters and token state can change while offline: recheck on reconnect. */
export function refreshMeOnReconnect(client: QueryClient) {
  return onlineManager.subscribe((online) => {
    if (online) void client.invalidateQueries({ queryKey: ['me'] })
  })
}
