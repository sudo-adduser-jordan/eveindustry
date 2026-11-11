import 'fake-indexeddb/auto'
import { QueryClient, onlineManager } from '@tanstack/react-query'
import { persistQueryClientRestore, persistQueryClientSave } from '@tanstack/react-query-persist-client'
import { get } from 'idb-keyval'
import {
  PERSIST_BUSTER, PERSIST_MAX_AGE, clearPersistedCache, persister, refreshMeOnReconnect, shouldDehydrate, shouldPersist,
  watchSignedInUser,
} from './persistence'

const dehydrateOptions = { shouldDehydrateQuery: shouldDehydrate }

describe('shouldPersist', () => {
  it('keeps the user, reference data, plans and small overviews', () => {
    for (const key of [
      ['me'], ['reference', 'market-groups'], ['plans', 'reaction'], ['industry', 'availability'],
      ['assets', 'ships'], ['assets', 'materials'], ['calculators', 'reactions', 'skills', 5],
      ['moon-scans', 'systems'],
    ]) expect(shouldPersist(key), JSON.stringify(key)).toBe(true)
  })

  it('leaves out searches, live market data, paginated assets and feeds', () => {
    for (const key of [
      ['systems', 'search', 'jita'], ['market', 'station-trading', {}], ['market', 'freshness'],
      ['assets', 'personal', 'items', {}, 1], ['calculators', 'reaction-stock', [1]],
    ]) expect(shouldPersist(key), JSON.stringify(key)).toBe(false)
  })
})

describe('persisted cache', () => {
  const make = () => new QueryClient({ defaultOptions: { queries: { gcTime: PERSIST_MAX_AGE } } })

  it('restores allowed queries into a fresh client and skips the rest', async () => {
    const source = make()
    source.setQueryData(['me'], { data: { id: 1 } })
    source.setQueryData(['systems', 'search', 'a'], ['x'])
    await persistQueryClientSave({ queryClient: source, persister, buster: PERSIST_BUSTER, dehydrateOptions })

    const target = make()
    await persistQueryClientRestore({ queryClient: target, persister, buster: PERSIST_BUSTER, maxAge: PERSIST_MAX_AGE })
    expect(target.getQueryData(['me'])).toEqual({ data: { id: 1 } })
    expect(target.getQueryData(['systems', 'search', 'a'])).toBeUndefined()
  })

  it('discards the stored cache when the build changes', async () => {
    const source = make()
    source.setQueryData(['me'], { data: { id: 1 } })
    await persistQueryClientSave({ queryClient: source, persister, buster: 'old-build', dehydrateOptions })

    const target = make()
    await persistQueryClientRestore({ queryClient: target, persister, buster: 'new-build', maxAge: PERSIST_MAX_AGE })
    expect(target.getQueryData(['me'])).toBeUndefined()
  })

  it('clears storage on sign-out and refuses later writes', async () => {
    const source = make()
    source.setQueryData(['me'], { data: { id: 1 } })
    await persistQueryClientSave({ queryClient: source, persister, buster: PERSIST_BUSTER, dehydrateOptions })
    await clearPersistedCache()
    expect(await get('query-cache', undefined)).toBeUndefined()
    await persister.persistClient({ timestamp: Date.now(), buster: PERSIST_BUSTER, clientState: { mutations: [], queries: [] } })
    expect(await get('query-cache', undefined)).toBeUndefined()
  })
})

describe('watchSignedInUser', () => {
  it('drops other queries when a different user signs in but keeps them for the same user', async () => {
    const client = new QueryClient()
    const stop = watchSignedInUser(client)
    client.setQueryData(['me'], { data: { id: 1 } })
    client.setQueryData(['plans', 'reaction'], ['mine'])

    client.setQueryData(['me'], { data: { id: 1, display_name: 'renamed' } })
    expect(client.getQueryData(['plans', 'reaction'])).toEqual(['mine'])

    client.setQueryData(['me'], { data: { id: 2 } })
    await Promise.resolve()
    expect(client.getQueryData(['plans', 'reaction'])).toBeUndefined()
    expect(client.getQueryData(['me'])).toEqual({ data: { id: 2 } })
    stop()
  })
})

describe('refreshMeOnReconnect', () => {
  it('invalidates the user when the connection returns', () => {
    const client = new QueryClient()
    client.setQueryData(['me'], { data: { id: 1 } })
    const stop = refreshMeOnReconnect(client)
    onlineManager.setOnline(false)
    onlineManager.setOnline(true)
    expect(client.getQueryState(['me'])?.isInvalidated).toBe(true)
    stop()
  })
})
