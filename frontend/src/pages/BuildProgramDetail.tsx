import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  useBuildProgram,
  useBuildProgramLocations,
  useBuildProgramProgress,
  useDeleteBuildProgram,
  useRenameBuildProgram,
  useSaveLocationFilter,
} from '../api/corp'
import type { components } from '../api/schema'
import { ErrorNotice, WriteError } from '../components/CorpCommon'
import { formatQty } from '../components/format'
import { LocationFilter } from '../components/LocationFilter'
import { BlueprintsPanel, ReactionsPanel, StockPanel } from '../components/ProgramSummaries'
import { progressClass } from './CorporateBuildPrograms'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

type Detail = components['schemas']['CorporateBuildProgramDetailResource']
type Progress = components['schemas']['BuildProgramProgressResource']


export interface Step {
  type_id: number
  name: string
  quantity_required: number
  runs_needed: number | null
  activity_type: string | null
  materials: Array<{ type_id: number; name: string; quantity: number }>
  children: Step[]
}


export interface ProgressStep {
  available: number
  progress: number
  has_enough: boolean
  quantity_required: number
  materials: Array<{ type_id: number; required: number; available: number; has_enough: boolean }>
  children: ProgressStep[]
}


export const asSteps = (steps: Detail['steps']) => steps as unknown as Step[]
export const asProgressSteps = (steps: Progress['steps']) => steps as unknown as ProgressStep[]


export function groupSteps(steps: Step[], sections: Detail['sections']): Array<{ name: string | null; start: number; steps: Step[] }> {
  if (sections.length === 0) return [{ name: null, start: 0, steps }]
  let at = 0
  return sections.map((s) => {
    const group = { name: s.name, start: at, steps: steps.slice(at, at + s.step_count) }
    at += s.step_count
    return group
  })
}

function StepCard({
  step,
  progress,
  depth,
  expandAll,
  volumes,
}: {
  step: Step
  progress: ProgressStep | undefined
  depth: number
  expandAll: boolean
  volumes: Record<string, number>
}) {
  const [childrenOpen, setChildrenOpen] = useState<boolean | null>(null)
  const [materialsOpen, setMaterialsOpen] = useState<boolean | null>(null)
  const showChildren = childrenOpen ?? expandAll
  const showMaterials = materialsOpen ?? expandAll
  const enough = progress?.has_enough
  const label = progress ? (enough ? '✓' : `${formatQty(progress.available)}/${formatQty(progress.quantity_required)}`) : ''

  return (
    <div className="step-card" style={{ marginLeft: `${Math.min(depth * 1.25, 3.75)}rem` }}>
      <div className="slot-head">
        <div className="program-title">
          {step.children.length > 0 ? (
            <button type="button" className="toggle" aria-expanded={showChildren} aria-label={`${showChildren ? 'Hide' : 'Show'} sub-steps of ${step.name}`} onClick={() => setChildrenOpen(!showChildren)}>
              {showChildren ? '▼' : '▶'}
            </button>
          ) : null}
          <img src={typeIconUrl(step.type_id)} alt="" width={22} height={22} loading="lazy" onError={iconFallback} />
          <div>
            <strong>{step.name}</strong>{' '}
            {step.activity_type ? <span className={step.activity_type === 'reaction' ? 'badge text-reactions' : 'badge text-manufacturing'}>{step.activity_type === 'reaction' ? 'Reaction' : 'Mfg'}</span> : null}{' '}
            <span className="muted small">
              × {formatQty(step.quantity_required)}
              {step.runs_needed ? ` · ${formatQty(step.runs_needed)}r` : ''}
            </span>
          </div>
        </div>
        <div className="page-actions">
          {progress ? <span className={enough ? 'badge score-best' : 'badge score-good'}>{enough ? '✓' : '…'}</span> : null}
          <span className={enough ? 'small text-profit' : progress && progress.available > 0 ? 'small text-research' : 'small text-loss'}>{label}</span>
          {step.materials.length > 0 ? (
            <button type="button" className="toggle" aria-expanded={showMaterials} aria-label={`${showMaterials ? 'Hide' : 'Show'} materials of ${step.name}`} onClick={() => setMaterialsOpen(!showMaterials)}>
              {showMaterials ? '▼' : '▶'}
            </button>
          ) : null}
        </div>
      </div>
      <div className={`bar ${progress ? (enough ? 'bar-good' : progress.progress >= 50 ? 'bar-manufacturing' : 'bar-research') : ''}`}>
        <span style={{ width: `${progress?.progress ?? 0}%` }} />
      </div>
      {showMaterials ? (
        <ul className="summary-list">
          {step.materials.map((m) => {
            const found = progress?.materials.find((p) => p.type_id === m.type_id)
            return (
              <li key={m.type_id}>
                <span className="grow">{m.name}</span>
                <span className="muted small">{((volumes[String(m.type_id)] ?? 0) * m.quantity).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} m³</span>
                {found ? <span className={found.has_enough ? 'small text-profit' : 'small text-loss'}>{formatQty(found.available)}</span> : null}
                <strong className="small">{found ? `/ ${formatQty(found.required)}` : formatQty(m.quantity)}</strong>
              </li>
            )
          })}
        </ul>
      ) : null}
      {showChildren
        ? step.children.map((c, i) => (
            <StepCard key={`${c.type_id}-${i}`} step={c} progress={progress?.children[i]} depth={depth + 1} expandAll={expandAll} volumes={volumes} />
          ))
        : null}
    </div>
  )
}

export default function BuildProgramDetail() {
  const id = Number(useParams().program)
  const program = useBuildProgram(id)
  const navigate = useNavigate()
  const rename = useRenameBuildProgram()
  const remove = useDeleteBuildProgram()
  const saveFilter = useSaveLocationFilter()
  const [name, setName] = useState<string | null>(null)
  const [expandAll, setExpandAll] = useState(false)
  
  const [applied, setApplied] = useState<{ input: number[]; output: number[] } | null>(null)

  const saved = program.data?.data.program
  const input = applied?.input ?? saved?.input_location_ids ?? []
  const output = applied?.output ?? saved?.output_location_ids ?? []
  const progress = useBuildProgramProgress(id, input, output)
  const locations = useBuildProgramLocations(id)

  if (program.isPending) return <p>Loading the program…</p>
  if (program.isError) {
    return (
      <>
        <Breadcrumb name="Not available" />
        <ErrorNotice error={program.error} what="the program" unavailable="Program unavailable:" />
      </>
    )
  }

  const { program: p, corporation_name, can_manage, volumes } = program.data.data
  const steps = asSteps(p.steps)
  const progressSteps = progress.data ? asProgressSteps(progress.data.data.steps) : undefined
  const shownName = name ?? p.name
  const filterNote = [input.length > 0 ? 'input filtered' : '', output.length > 0 ? 'output filtered' : ''].filter(Boolean).join(', ')
  const overall = progress.data?.data.overall_progress

  function apply(next: { input: number[]; output: number[] }) {
    setApplied(next)
    if (can_manage) saveFilter.mutate({ id, input: next.input, output: next.output })
  }

  return (
    <>
      <Breadcrumb name={p.name} />
      <div className="page-head">
        <div className="program-title">
          {p.t2_type_id ? <img src={typeIconUrl(p.t2_type_id)} alt="" width={28} height={28} onError={iconFallback} /> : null}
          <div>
            {can_manage ? (
              <>
                <label className="visually-hidden" htmlFor="program-name">
                  Program name
                </label>
                <input
                  id="program-name"
                  className="program-name"
                  type="text"
                  maxLength={255}
                  title="Click to rename"
                  value={shownName}
                  onChange={(e) => setName(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') e.currentTarget.blur()
                    if (e.key === 'Escape') {
                      setName(null)
                      e.currentTarget.blur()
                    }
                  }}
                  onBlur={() => {
                    const next = shownName.trim()
                    if (next === '' || next === p.name) return setName(null)
                    rename.mutate({ id, name: next }, { onSuccess: () => setName(null), onError: () => setName(null) })
                  }}
                />
              </>
            ) : (
              <h1>{p.name}</h1>
            )}
            <div className="muted small">
              <span className="badge">{corporation_name}</span>
              {p.program_type === 'reaction' ? <span className="badge text-reactions">Reaction plan</span> : null} {p.t2_type_name}
            </div>
          </div>
        </div>
        <div className="page-actions">
          <LocationFilter
            key={`in-${input.join(',')}`}
            label="Input"
            hint="Input hangars: where raw materials are stocked."
            locations={locations.data?.data ?? []}
            selected={input}
            disabled={!locations.data || locations.data.data.length === 0}
            onApply={(ids) => apply({ input: ids, output })}
          />
          <LocationFilter
            key={`out-${output.join(',')}`}
            label="Output"
            hint="Output hangars: where finished products accumulate."
            locations={locations.data?.data ?? []}
            selected={output}
            disabled={!locations.data || locations.data.data.length === 0}
            onApply={(ids) => apply({ input, output: ids })}
          />
          <button type="button" className="button" disabled={progress.isFetching} onClick={() => void progress.refetch()}>
            {progress.isFetching ? 'Refreshing…' : 'Refresh progress'}
          </button>
          {can_manage ? (
            <button
              type="button"
              className="button"
              disabled={remove.isPending}
              onClick={() => {
                if (window.confirm('Delete this corporate build program?')) remove.mutate(id, { onSuccess: () => navigate('/corporate/build-programs') })
              }}
            >
              Delete
            </button>
          ) : null}
        </div>
      </div>
      <WriteError error={rename.error} fallback="Could not rename the program." />
      <WriteError error={remove.error} fallback="Could not delete the program." />
      <WriteError error={saveFilter.error} fallback="Could not save the location filter." />

      <div className="panel">
        <div className="slot-head">
          <span className="muted" role="status">
            {progress.isPending
              ? 'Loading corporation asset progress…'
              : progress.isError
                ? progress.error.message
                : `Assets via ${progress.data.data.character_names[0] ?? 'the director character'}${filterNote ? ` (${filterNote})` : ''}`}
          </span>
          <strong>{overall === undefined ? '' : `${overall}%`}</strong>
        </div>
        <div className={`bar ${overall === undefined ? '' : progressClass(overall)}`} role="progressbar" aria-valuenow={overall ?? 0} aria-valuemin={0} aria-valuemax={100} aria-label="Overall progress">
          <span style={{ width: `${overall ?? 0}%` }} />
        </div>
      </div>

      <div className="split program-split">
        <section className="panel" aria-labelledby="steps-heading">
          <div className="slot-head">
            <h2 id="steps-heading">Production steps</h2>
            <button type="button" className="button" aria-pressed={expandAll} onClick={() => setExpandAll(!expandAll)}>
              {expandAll ? 'Collapse all' : 'Expand all'}
            </button>
          </div>
          {groupSteps(steps, p.sections).map((g, gi) => {
            const section = progress.data?.data.sections[gi]
            return (
              <div key={g.name ?? 'all'}>
                {g.name !== null ? (
                  <div className="section-header">
                    <div className="slot-head">
                      <strong>
                        {g.name} <span className="muted small">{g.steps.length} {g.steps.length === 1 ? 'step' : 'steps'}</span>
                      </strong>
                      <span className={section && section.overall_progress >= 100 ? 'small text-profit' : 'small muted'}>{section ? `${section.overall_progress}%` : '—'}</span>
                    </div>
                    <div className={`bar ${section ? progressClass(section.overall_progress) : ''}`}>
                      <span style={{ width: `${section?.overall_progress ?? 0}%` }} />
                    </div>
                  </div>
                ) : null}
                {g.steps.map((s, i) => (
                  <StepCard key={`${s.type_id}-${g.start + i}`} step={s} progress={progressSteps?.[g.start + i]} depth={0} expandAll={expandAll} volumes={volumes} />
                ))}
              </div>
            )
          })}
        </section>

        <div className="summaries">
          {progress.data ? (
            <>
              <BlueprintsPanel blueprints={progress.data.data.required_blueprints} />
              <StockPanel title="Base materials to acquire" items={progress.data.data.base_materials} empty="No base materials to acquire." />
              <StockPanel title="Fuel blocks" items={progress.data.data.fuel_blocks} empty="No fuel blocks required." />
            </>
          ) : (
            <p className="panel muted small">{progress.isError ? 'Unable to load.' : 'Loading…'}</p>
          )}
        </div>
      </div>
      {progress.data ? <ReactionsPanel reactions={progress.data.data.reactions_summary} /> : null}
    </>
  )
}

function Breadcrumb({ name }: { name: string }) {
  return (
    <nav aria-label="Breadcrumb" className="small">
      <Link to="/corporate/build-programs">Corporate build programs</Link> <span className="muted">/ {name}</span>
    </nav>
  )
}
