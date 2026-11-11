import { useEffect } from 'react'
import { useCostIndices, useSystemSearch } from '../../api/calculators'
import type { components } from '../../api/schema'
import { SearchPicker } from './kit'

export type SystemHit = components['schemas']['SolarSystemResource']

function useSystemHits(q: string) {
  const r = useSystemSearch(q)
  return { data: r.data?.data, isFetching: r.isFetching, isError: r.isError }
}

const secClass = (s: number) => (s >= 0.5 ? 'text-profit' : s > 0 ? 'text-warn' : 'text-loss')


export function SystemPicker({
  system,
  onChange,
  activity,
  onIndex,
}: {
  system: SystemHit | null
  onChange: (system: SystemHit | null) => void
  activity: 'manufacturing' | 'reaction' | 'invention'
  onIndex: (percent: number) => void
}) {
  const indices = useCostIndices(system?.solar_system_id ?? null)
  const index = indices.data?.data.cost_indices[activity]

  useEffect(() => {
    if (index !== undefined) onIndex(Math.round(index * 10000) / 100)
    
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [index])

  if (system) {
    return (
      <div className="calc-picked">
        <div style={{ flex: 1 }}>
          <strong>{system.solar_system_name}</strong>
          <small className="muted">
            {indices.isPending ? 'loading cost index…' : index !== undefined ? `Cost index ${(index * 100).toFixed(2)}%` : ''}
          </small>
        </div>
        <button type="button" className="button" aria-label="Clear system" onClick={() => onChange(null)}>
          ✕
        </button>
      </div>
    )
  }
  return (
    <SearchPicker<SystemHit>
      label="Solar system"
      placeholder="Search solar system…"
      useResults={useSystemHits}
      getKey={(s) => s.solar_system_id}
      renderItem={(s) => (
        <>
          <span style={{ flex: 1 }}>{s.solar_system_name}</span>
          <span className={`small ${secClass(s.security)}`}>{s.security.toFixed(1)}</span>
        </>
      )}
      onSelect={onChange}
      emptyText="No systems found"
    />
  )
}
