import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useBuildProgramMutations, useBuildPrograms, useBuildProgramProgress } from '../api/queries'
import type { components } from '../api/schema'
import { formatAge } from '../components/FreshnessBadge'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

type Program = components['schemas']['BuildProgramSummaryResource']

const icon = (typeId: number) => typeIconUrl(typeId)


export function progressTone(pct: number): 'done' | 'mid' | 'low' {
  return pct >= 100 ? 'done' : pct >= 50 ? 'mid' : 'low'
}

function ProgressLine({ program }: { program: Program }) {
  const progress = useBuildProgramProgress(program.id, program.location_ids)
  if (progress.isPending) return <span className="muted small">Loading…</span>
  if (progress.isError) return <span className="muted small">{progress.error.message}</span>
  const pct = progress.data.data.overall_progress
  return (
    <>
      <div className={`bar bar-progress-${progressTone(pct)}`} role="progressbar" aria-label={`${program.name} progress`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct}>
        <span style={{ width: `${pct}%` }} />
      </div>
      <span className="muted small">
        {pct}% complete{program.location_ids.length > 0 ? ' · filtered' : ''}
      </span>
    </>
  )
}

function ProgramCard({
  program,
  mergeMode,
  selected,
  onToggle,
  onDelete,
}: {
  program: Program
  mergeMode: boolean
  selected: boolean
  onToggle: () => void
  onDelete: () => void
}) {
  return (
    <li className={selected ? 'panel program selected' : 'panel program'}>
      <div className="program-head">
        {mergeMode ? (
          <input type="checkbox" checked={selected} onChange={onToggle} aria-label={`Select ${program.name} to merge`} />
        ) : null}
        {program.t2_type_id ? <img src={icon(program.t2_type_id)} alt="" width={32} height={32} loading="lazy" onError={iconFallback} /> : null}
        <div className="program-title">
          <Link to={`/build-programs/${program.id}`}>
            <strong>{program.name}</strong>
          </Link>
          <div className="muted small">
            {program.program_type === 'reaction' ? <span className="badge">Reaction</span> : null}
            {program.t2_type_name ?? (program.program_type === 'reaction' ? 'Reaction plan' : '')}
          </div>
        </div>
        <button type="button" className="button" onClick={onDelete} aria-label={`Delete ${program.name}`}>
          Delete
        </button>
      </div>
      <div className="small muted">
        {program.step_count} production {program.step_count === 1 ? 'step' : 'steps'}
        {program.location_ids.length > 0 ? <span className="badge">Filtered by location</span> : null}
      </div>
      <ProgressLine program={program} />
      {program.updated_at ? <div className="muted small">Updated {formatAge(program.updated_at)}</div> : null}
    </li>
  )
}

export default function BuildPrograms() {
  const programs = useBuildPrograms()
  const { remove, merge } = useBuildProgramMutations()
  const navigate = useNavigate()
  const [mergeMode, setMergeMode] = useState(false)
  const [selected, setSelected] = useState<number[]>([])

  if (programs.isPending) return <p>Loading build programs…</p>
  if (programs.isError) return <p role="alert">Could not load your build programs.</p>

  const list = programs.data.data
  const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))
  const leaveMerge = () => {
    setMergeMode(false)
    setSelected([])
  }

  function doMerge() {
    const names = list.filter((p) => selected.includes(p.id)).map((p) => p.name)
    const name = window.prompt('Name for the merged program:', names.join(' + '))
    if (!name?.trim()) return
    merge.mutate(
      { program_ids: selected, name: name.trim() },
      { onSuccess: (res) => navigate(`/build-programs/${res.data.id}`) },
    )
  }

  return (
    <>
      <div className="page-head">
        <h1>Build programs</h1>
        {list.length >= 2 && !mergeMode ? (
          <button type="button" className="button" onClick={() => setMergeMode(true)}>
            Merge
          </button>
        ) : null}
      </div>
      <p>
        Track production progress for manufacturing and reaction chains. Create a program from the{' '}
        <a href="/calculators/invention-planner">Invention planner</a> or the <a href="/calculators/reaction-planner">Reaction planner</a>.
      </p>
      {remove.isError ? (
        <p role="alert" className="panel warn">
          Could not delete the program.
        </p>
      ) : null}
      {merge.isError ? (
        <p role="alert" className="panel warn">
          {merge.error.message}
        </p>
      ) : null}

      {list.length === 0 ? (
        <div className="panel">
          <h2>No build programs yet</h2>
          <p>Calculate a build in a planner and choose “Export to Build Program”.</p>
        </div>
      ) : (
        <ul className="programs">
          {list.map((p) => (
            <ProgramCard
              key={p.id}
              program={p}
              mergeMode={mergeMode}
              selected={selected.includes(p.id)}
              onToggle={() => toggle(p.id)}
              onDelete={() => {
                if (window.confirm(`Delete build program "${p.name}"?`)) remove.mutate(p.id)
              }}
            />
          ))}
        </ul>
      )}

      {mergeMode ? (
        <div className="panel merge-bar">
          <span>{selected.length} selected</span>
          <button type="button" className="button" onClick={leaveMerge}>
            Cancel
          </button>
          <button type="button" className="button" disabled={selected.length < 2 || merge.isPending} onClick={doMerge}>
            {merge.isPending ? 'Merging…' : 'Merge selected'}
          </button>
        </div>
      ) : null}
    </>
  )
}
