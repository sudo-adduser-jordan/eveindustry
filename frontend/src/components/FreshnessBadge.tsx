export interface FreshnessBadgeProps {
  
  asOf: string | null | undefined
  
  stale?: boolean
  label?: string
  
  now?: Date
}

export function formatAge(asOf: string, now: Date = new Date()): string {
  const minutes = Math.max(0, Math.round((now.getTime() - new Date(asOf).getTime()) / 60_000))
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 48) return `${hours} h ago`
  return `${Math.floor(hours / 24)} d ago`
}


export function FreshnessBadge({ asOf, stale = false, label = 'Prices', now }: FreshnessBadgeProps) {
  if (!asOf) {
    return (
      <span className="badge badge-stale" role="status">
        {label}: no data yet
      </span>
    )
  }
  return (
    <span className={stale ? 'badge badge-stale' : 'badge badge-fresh'} role="status">
      {label} as of{' '}
      <time dateTime={asOf} title={new Date(asOf).toUTCString()}>
        {formatAge(asOf, now)}
      </time>
      {stale ? ' (stale)' : null}
    </span>
  )
}
