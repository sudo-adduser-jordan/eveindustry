import { useEffect, useState } from 'react'
import { useSystemSearch } from '../api/corp'

export interface PickedSystem {
  id: number
  name: string
}


export function SystemPicker({
  id,
  label,
  value,
  onChange,
}: {
  id: string
  label: string
  value: PickedSystem | null
  onChange: (system: PickedSystem | null) => void
}) {
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  useEffect(() => {
    const t = setTimeout(() => setTerm(text), 250)
    return () => clearTimeout(t)
  }, [text])
  const results = useSystemSearch(term)

  if (value) {
    return (
      <div className="scan-field">
        <span id={`${id}-label`}>{label}</span>
        <p className="system-picked" aria-labelledby={`${id}-label`}>
          <strong>{value.name}</strong>
          <button type="button" className="button" onClick={() => onChange(null)}>
            Change
          </button>
        </p>
      </div>
    )
  }

  const shown = term.trim().length >= 2 ? (results.data?.data ?? []) : []
  return (
    <div className="scan-field">
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        type="search"
        autoComplete="off"
        placeholder="Search solar system…"
        value={text}
        onChange={(e) => setText(e.target.value)}
      />
      {term.trim().length >= 2 && !results.isPending && shown.length === 0 ? (
        <span className="muted small">No systems match.</span>
      ) : null}
      {shown.length > 0 ? (
        <ul className="system-results" aria-label="Matching systems">
          {shown.map((s) => (
            <li key={s.solar_system_id}>
              <button
                type="button"
                className="system-result"
                onClick={() => {
                  onChange({ id: s.solar_system_id, name: s.solar_system_name })
                  setText('')
                  setTerm('')
                }}
              >
                {s.solar_system_name} <span className="muted small">{s.security.toFixed(1)}</span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
