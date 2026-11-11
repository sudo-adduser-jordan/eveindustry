import { useState } from 'react'

export interface FilterLocation {
  location_id: number
  name: string
  solar_system_name: string | null
}


export function LocationFilter({
  label,
  hint,
  locations,
  selected,
  disabled,
  onApply,
}: {
  
  label: string
  hint: string
  locations: FilterLocation[]
  selected: number[]
  disabled: boolean
  onApply: (ids: number[]) => void
}) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [draft, setDraft] = useState<number[]>(selected)

  const q = search.trim().toLowerCase()
  const shown = q === '' ? locations : locations.filter((l) => `${l.name} ${l.solar_system_name ?? ''}`.toLowerCase().includes(q))
  const summary =
    selected.length === 0
      ? `${label}: All`
      : selected.length === 1
        ? `${label}: ${locations.find((l) => l.location_id === selected[0])?.name ?? '1 location'}`
        : `${label}: ${selected.length} locations`

  return (
    <div className="loc-filter">
      <button
        type="button"
        className="button"
        disabled={disabled}
        aria-expanded={open}
        title={disabled ? 'No asset locations found' : hint}
        onClick={() => {
          setDraft(selected)
          setOpen(!open)
        }}
      >
        {summary}
      </button>
      {open ? (
        <div className="loc-menu panel" role="group" aria-label={`${label} locations`}>
          <p className="muted small">{hint}</p>
          <label className="visually-hidden" htmlFor={`${label}-search`}>
            Search {label.toLowerCase()} locations
          </label>
          <input id={`${label}-search`} type="search" placeholder="Search location…" autoComplete="off" value={search} onChange={(e) => setSearch(e.target.value)} />
          <ul className="loc-list">
            {shown.length === 0 ? <li className="muted small">No locations match.</li> : null}
            {shown.map((l) => (
              <li key={l.location_id}>
                <label>
                  <input
                    type="checkbox"
                    checked={draft.includes(l.location_id)}
                    onChange={(e) => setDraft(e.target.checked ? [...draft, l.location_id] : draft.filter((id) => id !== l.location_id))}
                  />{' '}
                  {l.name}
                  {l.solar_system_name ? <span className="muted small"> · {l.solar_system_name}</span> : null}
                </label>
              </li>
            ))}
          </ul>
          <div className="page-actions">
            <button type="button" className="button" onClick={() => setDraft([])}>
              Clear
            </button>
            <button
              type="button"
              className="button"
              onClick={() => {
                onApply(draft)
                setOpen(false)
              }}
            >
              Apply
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
