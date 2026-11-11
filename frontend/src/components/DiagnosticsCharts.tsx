import type { components } from '../api/schema'

type Trends = components['schemas']['EsiDiagnosticsTrendsResource']
export type Zone = 'utc' | 'local'


export const PAUSE_THRESHOLD = 20


export function formatTime(iso: string | null | undefined, zone: Zone): string {
  if (!iso) return '—'
  const date = new Date(iso)
  if (zone === 'utc') return `${date.toISOString().slice(0, 16).replace('T', ' ')} UTC`
  return `${date.toLocaleString()} (local)`
}

const SEGMENTS = [
  { key: 'status_401', label: '401', cls: 'seg-401' },
  { key: 'status_403', label: '403', cls: 'seg-403' },
  { key: 'status_404', label: '404', cls: 'seg-404' },
  { key: 'throttled_420', label: '420 error limit', cls: 'seg-420' },
  { key: 'throttled_429', label: '429 rate limit', cls: 'seg-429' },
  { key: 'other', label: 'Other / no response', cls: 'seg-other' },
] as const

const WIDTH = 600
const HEIGHT = 140

function xFor(iso: string, from: number, to: number): number {
  return ((new Date(iso).getTime() - from) / Math.max(1, to - from)) * WIDTH
}


export function ErrorTrendChart({ buckets, from, to, releases, zone }: {
  buckets: Trends['buckets']
  from: string
  to: string
  releases: Array<{ release: string; first_seen: string }>
  zone: Zone
}) {
  const t0 = new Date(from).getTime()
  const t1 = new Date(to).getTime()
  const rows = buckets.map((b) => ({ ...b, other: Math.max(0, b.errors - b.status_401 - b.status_403 - b.status_404 - b.throttled_420 - b.throttled_429) }))
  const peak = Math.max(1, ...rows.map((r) => r.errors))
  const total = rows.reduce((sum, r) => sum + r.errors, 0)
  const slot = Math.max(2, (3_600_000 / Math.max(1, t1 - t0)) * WIDTH)
  return (
    <figure className="chart panel wide">
      <figcaption>
        <strong>Errors per hour</strong> <span className="muted small">{total} errors, peak {peak} an hour</span>
      </figcaption>
      <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} role="img" aria-label={`Errors per hour by status: ${total} errors in the range, peak ${peak} an hour`} preserveAspectRatio="none">
        {rows.map((row) => {
          let y = HEIGHT
          const x = xFor(row.at, t0, t1)
          return (
            <g key={row.at}>
              <title>{`${formatTime(row.at, zone)}: ${row.requests} requests, ${row.errors} errors; ${row.local_deferrals} deferred locally, ${row.cache_hits} cache hits`}</title>
              {SEGMENTS.map((s) => {
                const value = row[s.key]
                if (!value) return null
                const h = (value / peak) * (HEIGHT - 6)
                y -= h
                return <rect key={s.key} className={s.cls} x={x} y={y} width={Math.max(1, slot - 1)} height={h} />
              })}
            </g>
          )
        })}
        {releases.map((r) => {
          const x = xFor(r.first_seen, t0, t1)
          return (
            <line key={r.release} className="release-marker" x1={x} x2={x} y1={0} y2={HEIGHT}>
              <title>{`Release ${r.release} first seen ${formatTime(r.first_seen, zone)}`}</title>
            </line>
          )
        })}
      </svg>
      <ul className="legend small">
        {SEGMENTS.map((s) => (
          <li key={s.key}><span className={`swatch ${s.cls}`} aria-hidden="true" /> {s.label}</li>
        ))}
        {releases.length > 0 ? <li><span className="swatch release" aria-hidden="true" /> Release first seen on a failure</li> : null}
      </ul>
    </figure>
  )
}


export function BudgetChart({ title, points, from, to, zone, threshold }: {
  title: string
  points: Array<{ at: string; remaining_min: number | null }>
  from: string
  to: string
  zone: Zone
  threshold?: number
}) {
  const t0 = new Date(from).getTime()
  const t1 = new Date(to).getTime()
  const known = points.filter((p): p is { at: string; remaining_min: number } => p.remaining_min !== null)
  const max = Math.max(threshold ?? 0, 1, ...known.map((p) => p.remaining_min))
  const y = (v: number) => HEIGHT - 6 - (v / max) * (HEIGHT - 12)
  const lowest = known.length ? Math.min(...known.map((p) => p.remaining_min)) : null
  
  const runs: Array<Array<{ at: string; remaining_min: number }>> = []
  let run: Array<{ at: string; remaining_min: number }> = []
  for (const p of points) {
    if (p.remaining_min === null) {
      if (run.length) runs.push(run)
      run = []
    } else run.push({ at: p.at, remaining_min: p.remaining_min })
  }
  if (run.length) runs.push(run)
  return (
    <figure className="chart panel">
      <figcaption>
        <strong>{title}</strong> <span className="muted small">{lowest === null ? 'no observations' : `lowest observed ${lowest}`}</span>
      </figcaption>
      <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} role="img" aria-label={`${title}: ${lowest === null ? 'no observations' : `lowest observed ${lowest}`}`} preserveAspectRatio="none">
        {threshold !== undefined ? (
          <line className="threshold" x1={0} x2={WIDTH} y1={y(threshold)} y2={y(threshold)}>
            <title>{`Local pause threshold: ${threshold} remaining`}</title>
          </line>
        ) : null}
        {runs.map((r) => (
          <polyline key={r[0]?.at} className="budget-line" fill="none" points={r.map((p) => `${xFor(p.at, t0, t1)},${y(p.remaining_min)}`).join(' ')} />
        ))}
        {known.map((p) => (
          <circle key={p.at} className="budget-dot" cx={xFor(p.at, t0, t1)} cy={y(p.remaining_min)} r={2}>
            <title>{`${formatTime(p.at, zone)}: ${p.remaining_min} remaining`}</title>
          </circle>
        ))}
      </svg>
      {threshold !== undefined ? <p className="muted small">Dashed line: local pause threshold ({threshold}). A pause is not proof the upstream budget reached zero.</p> : null}
    </figure>
  )
}
