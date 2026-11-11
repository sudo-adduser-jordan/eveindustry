import { useState } from 'react'
import { ApiError } from '../api/client'
import { useRefreshAssets } from '../api/queries'
import { formatAge } from './FreshnessBadge'


export const POLL_MS = 15_000
const POLL_FOR_MS = 10 * 60_000


export function isRefreshPending(lastUpdated: string | null | undefined, queuedAt: number | null, now: number): boolean {
  if (queuedAt === null || now - queuedAt > POLL_FOR_MS) return false
  
  return !lastUpdated || new Date(lastUpdated).getTime() < queuedAt - 1000
}

type WithLastUpdated = { state: { data?: unknown } }


export function useAssetRefresh() {
  const [queuedAt, setQueuedAt] = useState<number | null>(null)
  const mutation = useRefreshAssets()
  return {
    mutation,
    queuedAt,
    queue: () => mutation.mutate(undefined, { onSuccess: () => setQueuedAt(Date.now()) }),
    refetchInterval: (query: WithLastUpdated) => {
      const meta = (query.state.data as { meta?: { last_updated?: string | null } } | undefined)?.meta
      return isRefreshPending(meta?.last_updated, queuedAt, Date.now()) ? POLL_MS : (false as const)
    },
  }
}


export function RefreshBar({
  refresh,
  lastUpdated,
  dataUpdatedAt,
}: {
  refresh: ReturnType<typeof useAssetRefresh>
  lastUpdated: string | null | undefined
  
  dataUpdatedAt: number
}) {
  void dataUpdatedAt
  const pending = isRefreshPending(lastUpdated, refresh.queuedAt, Date.now())
  const { mutation } = refresh
  return (
    <>
      <div className="page-actions">
        <span className="muted" role="status">
          {pending ? 'Refresh queued, updating…' : lastUpdated ? `Updated ${formatAge(lastUpdated)}` : 'Never updated'}
        </span>
        <button type="button" className="button" disabled={mutation.isPending || pending} onClick={refresh.queue}>
          Refresh
        </button>
      </div>
      {mutation.isError ? (
        <p role="alert" className="panel warn">
          {mutation.error instanceof ApiError && mutation.error.status === 429
            ? 'Refresh was requested too often; try again in a minute.'
            : 'Could not queue a refresh.'}
        </p>
      ) : null}
    </>
  )
}
