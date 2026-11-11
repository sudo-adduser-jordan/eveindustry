
export function BarChart({ title, series, days = 30, now = new Date() }: {
  title: string
  series: Array<{ date: string; count: number }>
  days?: number
  now?: Date
}) {
  const counts = new Map(series.map((s) => [s.date, s.count]))
  const points = Array.from({ length: days }, (_, i) => {
    const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - (days - 1 - i)))
    const date = d.toISOString().slice(0, 10)
    return { date, count: counts.get(date) ?? 0 }
  })
  const peak = Math.max(0, ...points.map((p) => p.count))
  const max = Math.max(1, peak)
  const total = points.reduce((sum, p) => sum + p.count, 0)
  const width = 300
  const height = 100
  const slot = width / days
  return (
    <figure className="chart panel">
      <figcaption>
        <strong>{title}</strong> <span className="muted small">{total} in {days} days, peak {peak} a day</span>
      </figcaption>
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`${title}: ${total} in the last ${days} days`} preserveAspectRatio="none">
        {points.map((p, i) => {
          const h = (p.count / max) * (height - 4)
          return (
            <rect key={p.date} x={i * slot + 1} y={height - h} width={Math.max(1, slot - 2)} height={h} rx={1}>
              <title>{`${p.date}: ${p.count}`}</title>
            </rect>
          )
        })}
      </svg>
    </figure>
  )
}
