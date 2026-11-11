import { useState, type FormEvent } from 'react'
import {
  useAddStructureWindow,
  useRemoveStructureWindow,
  useStructureWindows,
  useUpdateStructureWindow,
  type StructureWindowRequest,
} from '../api/corp'
import { ErrorNotice, formatUtc, WriteError } from '../components/CorpCommon'
import { SystemPicker, type PickedSystem } from '../components/SystemPicker'

export const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

type Win = NonNullable<ReturnType<typeof useStructureWindows>['data']>['data'][number]

interface FormState {
  system: PickedSystem | null
  type: 'upwell' | 'skyhook'
  name: string
  day: string
  start: string
  duration: string
  exit: string
  notes: string
}

const EMPTY: FormState = { system: null, type: 'upwell', name: '', day: '0', start: '18:00', duration: '60', exit: '', notes: '' }


function toLocalInput(iso: string | null): string {
  return iso ? new Date(iso).toISOString().slice(0, 16) : ''
}

export function toRequest(f: FormState): StructureWindowRequest | null {
  if (!f.system) return null
  return {
    solar_system_id: f.system.id,
    solar_system_name: f.system.name,
    structure_type: f.type,
    structure_name: f.name.trim(),
    day_of_week: f.type === 'skyhook' ? null : Number(f.day),
    start_time_utc: f.start,
    duration_minutes: Number(f.duration),
    reinforce_exit_at: f.exit ? `${f.exit}:00Z` : null,
    notes: f.notes.trim() || null,
  }
}

function fromWindow(w: Win): FormState {
  return {
    system: { id: w.solar_system_id, name: w.solar_system_name },
    type: w.structure_type === 'skyhook' ? 'skyhook' : 'upwell',
    name: w.structure_name,
    day: String(w.day_of_week ?? 0),
    start: w.start_time_utc,
    duration: String(w.duration_minutes),
    exit: toLocalInput(w.reinforce_exit_at),
    notes: w.notes ?? '',
  }
}

function WindowForm({ editing, onDone }: { editing: Win | null; onDone: () => void }) {
  const add = useAddStructureWindow()
  const update = useUpdateStructureWindow()
  const [f, setF] = useState<FormState>(editing ? fromWindow(editing) : EMPTY)
  const mutation = editing ? update : add

  function submit(e: FormEvent) {
    e.preventDefault()
    const body = toRequest(f)
    if (!body) return
    const done = { onSuccess: () => { setF(EMPTY); onDone() } }
    if (editing) update.mutate({ id: editing.id, body }, done)
    else add.mutate(body, done)
  }

  return (
    <form className="panel scan-form" onSubmit={submit}>
      <h2>{editing ? `Edit ${editing.structure_name}` : 'Add vulnerability window'}</h2>
      <SystemPicker id="window-system" label="Solar system" value={f.system} onChange={(system) => setF({ ...f, system })} />
      <div className="scan-field">
        <label htmlFor="window-type">Structure type</label>
        <select id="window-type" value={f.type} onChange={(e) => setF({ ...f, type: e.target.value as FormState['type'] })}>
          <option value="upwell">Upwell structure</option>
          <option value="skyhook">Skyhook (vulnerable daily)</option>
        </select>
      </div>
      <div className="scan-field">
        <label htmlFor="window-name">Structure name</label>
        <input id="window-name" type="text" maxLength={150} required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
      </div>
      {f.type === 'upwell' ? (
        <div className="scan-field">
          <label htmlFor="window-day">Day of week</label>
          <select id="window-day" value={f.day} onChange={(e) => setF({ ...f, day: e.target.value })}>
            {DAYS.map((d, i) => (
              <option key={d} value={i}>
                {d}
              </option>
            ))}
          </select>
        </div>
      ) : null}
      <div className="scan-field">
        <label htmlFor="window-start">Start time (UTC)</label>
        <input id="window-start" type="time" required value={f.start} onChange={(e) => setF({ ...f, start: e.target.value })} />
      </div>
      <div className="scan-field">
        <label htmlFor="window-duration">Duration (minutes)</label>
        <input id="window-duration" type="number" min={5} max={1440} required value={f.duration} onChange={(e) => setF({ ...f, duration: e.target.value })} />
      </div>
      <div className="scan-field">
        <label htmlFor="window-exit">Reinforced until (UTC, optional)</label>
        <input id="window-exit" type="datetime-local" value={f.exit} onChange={(e) => setF({ ...f, exit: e.target.value })} />
      </div>
      <div className="scan-field">
        <label htmlFor="window-notes">Notes</label>
        <textarea id="window-notes" rows={2} maxLength={500} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} />
      </div>
      <div className="page-actions">
        <button type="submit" className="button" disabled={!f.system || mutation.isPending}>
          {editing ? 'Save changes' : 'Add window'}
        </button>
        {editing ? (
          <button type="button" className="button" onClick={onDone}>
            Cancel
          </button>
        ) : null}
      </div>
      <WriteError error={mutation.error} fallback="Could not save the window." />
    </form>
  )
}

export function windowLabel(w: Win): string {
  return w.structure_type === 'skyhook' ? 'Daily' : (DAYS[w.day_of_week ?? -1] ?? '—')
}

export default function StructureTimers() {
  const windows = useStructureWindows()
  const remove = useRemoveStructureWindow()
  const [editing, setEditing] = useState<Win | null>(null)

  if (windows.isPending) return <p>Loading structure timers…</p>
  if (windows.isError) {
    return (
      <>
        <h1>Structure vulnerability timers</h1>
        <ErrorNotice error={windows.error} what="the structure timers" unavailable="Structure timers unavailable:" />
      </>
    )
  }

  const bySystem = new Map<string, Win[]>()
  for (const w of windows.data.data) bySystem.set(w.solar_system_name, [...(bySystem.get(w.solar_system_name) ?? []), w])

  return (
    <>
      <h1>Structure vulnerability timers</h1>
      <div className="split">
        <WindowForm key={editing?.id ?? 'new'} editing={editing} onDone={() => setEditing(null)} />
        <section aria-labelledby="windows-heading">
          <h2 id="windows-heading">Windows</h2>
          <WriteError error={remove.error} fallback="Could not remove the window." />
          {bySystem.size === 0 ? (
            <p className="panel muted">No vulnerability windows yet. Add a structure to track when it can be attacked.</p>
          ) : (
            [...bySystem.entries()].map(([system, list]) => (
              <section key={system} className="panel" aria-label={system}>
                <h3>{system}</h3>
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Type</th>
                        <th>Structure</th>
                        <th>Window (UTC)</th>
                        <th>Reinforced until</th>
                        <th>Notes</th>
                        <th>
                          <span className="visually-hidden">Actions</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {list.map((w) => (
                        <tr key={w.id}>
                          <td>
                            <span className="badge">{w.structure_type === 'skyhook' ? 'Skyhook' : 'Upwell'}</span>
                          </td>
                          <td>{w.structure_name}</td>
                          <td>
                            {windowLabel(w)} @ {w.start_time_utc} · {w.duration_minutes} min
                          </td>
                          <td>{w.reinforce_exit_at ? <span className="text-research">{formatUtc(w.reinforce_exit_at)}</span> : '—'}</td>
                          <td className="small muted">{w.notes}</td>
                          <td>
                            <div className="page-actions">
                              <button type="button" className="button" onClick={() => setEditing(w)} aria-label={`Edit ${w.structure_name}`}>
                                Edit
                              </button>
                              <button
                                type="button"
                                className="button"
                                disabled={remove.isPending}
                                aria-label={`Remove ${w.structure_name}`}
                                onClick={() => {
                                  if (window.confirm('Remove this vulnerability window?')) remove.mutate(w.id)
                                }}
                              >
                                Remove
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ))
          )}
        </section>
      </div>
    </>
  )
}
