import { useEffect, useState, type FormEvent } from 'react'
import {
  useDeleteStructure,
  useSaveStructure,
  useSetDefaultStructure,
  useStructures,
  useSystemSearch,
  type StructureInput,
} from '../api/queries'
import type { components } from '../api/schema'

type Structure = components['schemas']['StructureResource']
type Activity = StructureInput['activity_type']

const ACTIVITIES: Array<[Activity, string]> = [
  ['manufacturing', 'Manufacturing'],
  ['invention', 'Invention'],
  ['reaction', 'Reaction'],
]
const ENGINEERING: Array<[StructureInput['structure_type'], string]> = [
  ['none', 'No structure (NPC station)'],
  ['raitaru', 'Raitaru (Medium)'],
  ['azbel', 'Azbel (Large)'],
  ['sotiyo', 'Sotiyo (X-Large)'],
]
const REFINERIES: Array<[StructureInput['structure_type'], string]> = [
  ['athanor', 'Athanor (Medium)'],
  ['tatara', 'Tatara (Large)'],
]
const RIGS: Array<[StructureInput['rig_me'], string]> = [
  ['none', 'No rig'],
  ['t1', 'T1 rig'],
  ['t2', 'T2 rig'],
]
const SECURITY: Array<[StructureInput['system_security'], string]> = [
  ['highsec', 'Highsec'],
  ['lowsec', 'Lowsec'],
  ['nullsec', 'Nullsec / Wormhole'],
]

interface Draft {
  activity_type: Activity
  name: string
  structure_type: StructureInput['structure_type']
  rig_me: StructureInput['rig_me']
  rig_te: StructureInput['rig_te']
  system_security: StructureInput['system_security']
  system: { id: number; name: string } | null
  tax_rate: string
  is_default: boolean
}

const EMPTY: Draft = {
  activity_type: 'manufacturing',
  name: '',
  structure_type: 'none',
  rig_me: 'none',
  rig_te: 'none',
  system_security: 'highsec',
  system: null,
  tax_rate: '0',
  is_default: false,
}

function toDraft(s: Structure): Draft {
  return {
    activity_type: s.activity_type as Activity,
    name: s.name,
    structure_type: s.structure_type as Draft['structure_type'],
    rig_me: s.rig_me as Draft['rig_me'],
    rig_te: s.rig_te as Draft['rig_te'],
    system_security: s.system_security as Draft['system_security'],
    system: s.solar_system_id !== null && s.solar_system_name ? { id: s.solar_system_id, name: s.solar_system_name } : null,
    tax_rate: String(s.tax_rate ?? 0),
    is_default: s.is_default,
  }
}


function SystemPicker({
  value,
  onChange,
}: {
  value: Draft['system']
  onChange: (system: Draft['system']) => void
}) {
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  useEffect(() => {
    const id = setTimeout(() => setTerm(text.trim()), 300)
    return () => clearTimeout(id)
  }, [text])
  const found = useSystemSearch(term)

  if (value) {
    return (
      <div className="picked">
        <span>{value.name}</span>
        <button type="button" className="button" aria-label="Clear system" onClick={() => onChange(null)}>
          ×
        </button>
      </div>
    )
  }
  return (
    <div className="picker">
      <input
        id="structure-system"
        type="search"
        autoComplete="off"
        placeholder="Search solar system…"
        value={text}
        onChange={(e) => setText(e.target.value)}
      />
      {term.length >= 2 ? (
        <ul className="picker-results" aria-label="Solar systems">
          {found.isPending ? <li className="muted">Searching…</li> : null}
          {found.isError ? <li className="muted">Search failed</li> : null}
          {found.data?.data.length === 0 ? <li className="muted">No systems match “{term}”</li> : null}
          {found.data?.data.map((s) => (
            <li key={s.solar_system_id}>
              <button className="option"
                type="button"
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

function StructureForm({ editing, onDone }: { editing: Structure | null; onDone: () => void }) {
  const [draft, setDraft] = useState<Draft>(editing ? toDraft(editing) : EMPTY)
  const save = useSaveStructure()
  const reaction = draft.activity_type === 'reaction'
  const types = reaction ? REFINERIES : ENGINEERING

  function set<K extends keyof Draft>(key: K, value: Draft[K]) {
    setDraft((d) => ({ ...d, [key]: value }))
  }

  function setActivity(activity: Activity) {
    
    const allowed = (activity === 'reaction' ? REFINERIES : ENGINEERING).map(([v]) => v)
    setDraft((d) => ({
      ...d,
      activity_type: activity,
      structure_type: allowed.includes(d.structure_type) ? d.structure_type : (allowed[0] ?? 'none'),
    }))
  }

  function submit(e: FormEvent) {
    e.preventDefault()
    if (draft.name.trim() === '') return
    save.mutate(
      {
        id: editing?.id ?? null,
        input: {
          activity_type: draft.activity_type,
          name: draft.name.trim(),
          structure_type: draft.structure_type,
          rig_me: draft.rig_me,
          rig_te: draft.rig_te,
          system_security: draft.system_security,
          solar_system_id: draft.system?.id ?? null,
          tax_rate: Number(draft.tax_rate) || 0,
          is_default: draft.is_default,
        },
      },
      { onSuccess: onDone },
    )
  }

  return (
    <form className="panel scan-form" onSubmit={submit}>
      <h2 className="subhead">{editing ? `Edit ${editing.name}` : 'Add structure'}</h2>
      <div className="scan-field">
        <label htmlFor="structure-activity">Activity</label>
        <select id="structure-activity" value={draft.activity_type} onChange={(e) => setActivity(e.target.value as Activity)}>
          {ACTIVITIES.map(([v, label]) => (
            <option key={v} value={v}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <div className="scan-field">
        <label htmlFor="structure-name">Name</label>
        <input
          id="structure-name"
          type="text"
          maxLength={100}
          placeholder="e.g. My Azbel (Lowsec)"
          value={draft.name}
          onChange={(e) => set('name', e.target.value)}
        />
      </div>
      <div className="scan-field">
        <label htmlFor="structure-type">Structure type</label>
        <select
          id="structure-type"
          value={draft.structure_type}
          onChange={(e) => set('structure_type', e.target.value as Draft['structure_type'])}
        >
          {types.map(([v, label]) => (
            <option key={v} value={v}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <div className="scan-field">
        <label htmlFor="structure-rig-me">ME rig</label>
        <select id="structure-rig-me" value={draft.rig_me} onChange={(e) => set('rig_me', e.target.value as Draft['rig_me'])}>
          {RIGS.map(([v, label]) => (
            <option key={v} value={v}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <div className="scan-field">
        <label htmlFor="structure-rig-te">TE rig</label>
        <select id="structure-rig-te" value={draft.rig_te} onChange={(e) => set('rig_te', e.target.value as Draft['rig_te'])}>
          {RIGS.map(([v, label]) => (
            <option key={v} value={v}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <div className="scan-field">
        <label htmlFor="structure-security">System security</label>
        <select
          id="structure-security"
          value={draft.system_security}
          onChange={(e) => set('system_security', e.target.value as Draft['system_security'])}
        >
          {SECURITY.map(([v, label]) => (
            <option key={v} value={v}>
              {label}
            </option>
          ))}
        </select>
      </div>
      <div className="scan-field">
        <label htmlFor="structure-system">
          Solar system <span className="muted">(optional)</span>
        </label>
        <SystemPicker value={draft.system} onChange={(system) => set('system', system)} />
      </div>
      <div className="scan-field">
        <label htmlFor="structure-tax">Structure tax rate %</label>
        <input
          id="structure-tax"
          type="number"
          inputMode="decimal"
          min={0}
          max={50}
          step={0.1}
          value={draft.tax_rate}
          onChange={(e) => set('tax_rate', e.target.value)}
        />
      </div>
      <label>
        <input type="checkbox" checked={draft.is_default} onChange={(e) => set('is_default', e.target.checked)} /> Default for
        this activity
      </label>
      {save.isError ? (
        <p role="alert" className="warn">
          {save.error.message}
        </p>
      ) : null}
      <div className="form-actions">
        <button type="submit" className="button" disabled={save.isPending || draft.name.trim() === ''}>
          {editing ? 'Update structure' : 'Save structure'}
        </button>
        {editing ? (
          <button type="button" className="button" onClick={onDone}>
            Cancel
          </button>
        ) : null}
      </div>
    </form>
  )
}

function StructureCard({ s, onEdit }: { s: Structure; onEdit: () => void }) {
  const remove = useDeleteStructure()
  const makeDefault = useSetDefaultStructure()
  const error = remove.error ?? makeDefault.error
  return (
    <li className="panel structure">
      <div className="structure-head">
        <strong>{s.name}</strong>
        {s.is_default ? <span className="badge badge-fresh">★ Default</span> : null}
        <span className="structure-actions">
          {!s.is_default ? (
            <button type="button" className="button" disabled={makeDefault.isPending} onClick={() => makeDefault.mutate(s.id)}>
              Make default
            </button>
          ) : null}
          <button type="button" className="button" onClick={onEdit}>
            Edit
          </button>
          <button
            type="button"
            className="button"
            disabled={remove.isPending}
            onClick={() => {
              if (window.confirm(`Delete ${s.name}?`)) remove.mutate(s.id)
            }}
          >
            Delete
          </button>
        </span>
      </div>
      <p className="tags">
        <span className="badge">{s.activity_type}</span>
        <span className="badge">{s.structure_type}</span>
        {s.rig_me !== 'none' ? <span className="badge">ME {s.rig_me.toUpperCase()}</span> : null}
        {s.rig_te !== 'none' ? <span className="badge">TE {s.rig_te.toUpperCase()}</span> : null}
        <span className="badge">{s.system_security}</span>
        {s.solar_system_name ? <span className="badge">{s.solar_system_name}</span> : null}
        {s.tax_rate !== null && s.tax_rate > 0 ? <span className="badge">Tax {s.tax_rate.toFixed(2)}%</span> : null}
      </p>
      {error ? (
        <p role="alert" className="warn">
          {error.message}
        </p>
      ) : null}
    </li>
  )
}

export default function Structures() {
  const structures = useStructures()
  
  const [editing, setEditing] = useState<Structure | null>(null)

  const list = structures.data?.data ?? []
  const byActivity = ACTIVITIES.map(([activity, label]) => [label, list.filter((s) => s.activity_type === activity)] as const)

  return (
    <>
      <h1>My structures</h1>
      <p>Structures and station settings the calculators can build in. The default for an activity is preselected.</p>
      <div className="scan-layout">
        <StructureForm key={editing?.id ?? 'new'} editing={editing} onDone={() => setEditing(null)} />
        <div>
          {structures.isPending ? <p>Loading structures…</p> : null}
          {structures.isError ? (
            <p role="alert" className="panel warn">
              {structures.error.message}
            </p>
          ) : null}
          {structures.data && list.length === 0 ? (
            <p className="panel">No structures yet. Add your first one with the form.</p>
          ) : null}
          {byActivity
            .filter(([, rows]) => rows.length > 0)
            .map(([label, rows]) => (
              <section key={label}>
                <h2 className="subhead">{label}</h2>
                <ul className="structure-list">
                  {rows.map((s) => (
                    <StructureCard key={s.id} s={s} onEdit={() => setEditing(s)} />
                  ))}
                </ul>
              </section>
            ))}
        </div>
      </div>
    </>
  )
}
