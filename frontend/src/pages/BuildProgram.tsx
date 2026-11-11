import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../api/client'
import {
  useBuildProgram,
  useBuildProgramLocations,
  useBuildProgramMutations,
  useBuildProgramProgress,
} from '../api/queries'
import type { components } from '../api/schema'
import { formatQty } from '../components/format'
import { progressTone } from './BuildPrograms'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

type Detail = components['schemas']['BuildProgramDetailResource']
type Step = Omit<components['schemas']['BuildProgramStepResource'], 'children'> & { children: Step[] }
type ProgressStep = Omit<components['schemas']['ProgressStepResource'], 'children'> & { children: ProgressStep[] }
type Supply = components['schemas']['ProgressSupplyResource']
type Reaction = components['schemas']['ProgressReactionResource']
type Location = components['schemas']['AssetLocationResource']

const icon = (typeId: number) => typeIconUrl(typeId)
const volume = (n: number, digits = 1) => n.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits })


export function multibuyText(items: Array<{ name: string; quantity: number; available: number }>, missingOnly: boolean): string {
  return items
    .filter((m) => !missingOnly || m.quantity > m.available)
    .map((m) => `${m.name} ${missingOnly ? m.quantity - m.available : m.quantity}`)
    .join('\n')
}

function Bar({ pct, label, thin }: { pct: number; label: string; thin?: boolean }) {
  return (
    <div
      className={`bar bar-progress-${progressTone(pct)}${thin ? '' : ' bar-lg'}`}
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={pct}
    >
      <span style={{ width: `${pct}%` }} />
    </div>
  )
}

function StepCard({ step, progress, volumes, depth, expandAll }: { step: Step; progress?: ProgressStep; volumes: Record<string, number>; depth: number; expandAll: boolean }) {
  const [children, setChildren] = useState(false)
  const [materials, setMaterials] = useState(false)
  const showChildren = children || expandAll
  const showMaterials = materials || expandAll
  const done = progress?.has_enough
  return (
    <div className="step" style={{ marginLeft: Math.min(depth, 3) * 16 }}>
      <div className="step-head">
        {step.children.length > 0 ? (
          <button type="button" className="toggle" aria-expanded={showChildren} aria-label={`Sub-steps of ${step.name}`} onClick={() => setChildren(!children)}>
            {showChildren ? '▾' : '▸'}
          </button>
        ) : null}
        <img src={icon(step.type_id)} alt="" width={22} height={22} loading="lazy" onError={iconFallback} />
        <div className="step-title">
          <strong>{step.name}</strong>
          {step.activity_type ? <span className="badge">{step.activity_type === 'reaction' ? 'Reaction' : 'Mfg'}</span> : null}
          <span className="muted small">
            {' '}
            × {formatQty(step.quantity_required)}
            {step.runs_needed ? ` · ${formatQty(step.runs_needed)}r` : ''}
          </span>
        </div>
        {progress ? (
          <span className={done ? 'text-profit' : progress.available > 0 ? 'text-warn' : 'text-loss'}>
            {done ? '✓' : `${formatQty(progress.available)}/${formatQty(progress.quantity_required)}`}
          </span>
        ) : null}
        {step.materials.length > 0 ? (
          <button type="button" className="toggle" aria-expanded={showMaterials} aria-label={`Materials of ${step.name}`} onClick={() => setMaterials(!materials)}>
            {showMaterials ? '▾' : '▸'}
          </button>
        ) : null}
      </div>
      {progress ? <Bar thin pct={progress.progress} label={`${step.name} progress`} /> : null}
      {showMaterials ? (
        <ul className="mat-list small">
          {step.materials.map((m, i) => {
            const p = progress?.materials.find((x) => x.type_id === m.type_id)
            return (
              <li key={`${m.type_id}-${i}`}>
                <img src={icon(m.type_id)} alt="" width={18} height={18} loading="lazy" onError={iconFallback} />
                <span className="grow">{m.name}</span>
                <span className="muted">{volume((volumes[String(m.type_id)] ?? 0) * m.quantity)} m³</span>
                {p ? <span className={p.has_enough ? 'text-profit' : 'text-loss'}>{formatQty(p.available)}</span> : null}
                <strong>{p ? `/ ${formatQty(p.required)}` : formatQty(m.quantity)}</strong>
              </li>
            )
          })}
        </ul>
      ) : null}
      {showChildren
        ? step.children.map((c, i) => (
            <StepCard key={`${c.type_id}-${i}`} step={c} progress={progress?.children[i]} volumes={volumes} depth={depth + 1} expandAll={expandAll} />
          ))
        : null}
    </div>
  )
}

function SupplyPanel({ title, empty, items }: { title: string; empty: string; items: Supply[] }) {
  const [copied, setCopied] = useState<string | null>(null)
  const grouped = new Map<string, Supply[]>()
  for (const m of items) grouped.set(m.group_name, [...(grouped.get(m.group_name) ?? []), m])
  const groups = [...grouped.entries()].sort(([a], [b]) => (a === 'Other' ? 1 : b === 'Other' ? -1 : a.localeCompare(b)))
  const total = items.reduce((sum, m) => sum + m.volume * m.quantity, 0)
  const anyMissing = items.some((m) => m.quantity > m.available)

  async function copy(kind: 'all' | 'missing') {
    await navigator.clipboard.writeText(multibuyText(items, kind === 'missing'))
    setCopied(kind)
    setTimeout(() => setCopied(null), 1500)
  }

  return (
    <section className="panel">
      <div className="page-head">
        <h2>{title}</h2>
        {items.length > 0 ? (
          <span className="page-actions">
            <button type="button" className="button" onClick={() => copy('all')}>
              {copied === 'all' ? 'Copied!' : 'Buy all'}
            </button>
            {anyMissing ? (
              <button type="button" className="button" onClick={() => copy('missing')}>
                {copied === 'missing' ? 'Copied!' : 'Buy missing'}
              </button>
            ) : null}
          </span>
        ) : null}
      </div>
      {items.length === 0 ? (
        <p className="muted small">{empty}</p>
      ) : (
        <>
          {groups.map(([group, mats]) => (
            <div key={group}>
              {groups.length > 1 || group !== 'Fuel Blocks' ? <h3 className="group-head">{group}</h3> : null}
              <ul className="mat-list small">
                {mats.map((m) => {
                  const enough = m.available >= m.quantity
                  return (
                    <li key={m.type_id}>
                      <img src={icon(m.type_id)} alt="" width={20} height={20} loading="lazy" onError={iconFallback} />
                      <span className="grow">{m.name}</span>
                      <span className="muted">{volume(m.volume * m.quantity)} m³</span>
                      <span className={enough ? 'text-profit' : 'text-loss'}>{formatQty(m.available)}</span>
                      <span className="muted">/ {formatQty(m.quantity)}</span>
                      <span className={enough ? 'text-profit' : 'text-loss'}>{enough ? '✓' : `Need ${formatQty(m.quantity - m.available)}`}</span>
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}
          <p className="num small">
            <span className="muted">Total volume:</span> <strong>{volume(total, 2)} m³</strong>
          </p>
        </>
      )}
    </section>
  )
}

function ReactionsPanel({ reactions }: { reactions: Reaction[] }) {
  const pending = reactions.filter((r) => r.net_runs > 0).length
  return (
    <section className="panel">
      <h2>
        Reactions summary{' '}
        {reactions.length > 0 ? <span className={pending > 0 ? 'badge badge-stale' : 'badge badge-fresh'}>{pending > 0 ? `${pending} pending` : 'All done'}</span> : null}
      </h2>
      {reactions.length === 0 ? <p className="muted small">No reactions required.</p> : null}
      {reactions.map((r) => (
        <div key={r.blueprint_type_id} className="step">
          <div className="step-head">
            <img src={icon(r.type_id)} alt="" width={24} height={24} loading="lazy" onError={iconFallback} />
            <strong className="grow">{r.name}</strong>
            {r.net_runs <= 0 ? (
              <span className="badge badge-fresh">✓ Done</span>
            ) : (
              <span className="badge badge-stale">
                {formatQty(r.net_runs)} {r.net_runs === 1 ? 'run' : 'runs'} needed
              </span>
            )}
          </div>
          <div className="muted small">
            Runs {formatQty(r.total_runs)} · Output {formatQty(r.available)} / {formatQty(r.output_qty)} · {volume(r.input_volume)} m³ in →{' '}
            {volume(r.output_volume)} m³ out
          </div>
          <ul className="mat-list small">
            {r.inputs.map((i) => (
              <li key={i.type_id}>
                <img src={icon(i.type_id)} alt="" width={18} height={18} loading="lazy" onError={iconFallback} />
                <span className="grow">{i.name}</span>
                <span className={i.available >= i.required_qty ? 'text-profit' : 'text-loss'}>{formatQty(i.available)}</span>
                <span className="muted">/ {formatQty(i.required_qty)}</span>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </section>
  )
}

function LocationFilter({ programId, saved, onApply }: { programId: number; saved: number[]; onApply: (ids: number[]) => void }) {
  const locations = useBuildProgramLocations(programId)
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState<number[]>(saved)
  const [search, setSearch] = useState('')
  const all: Location[] = locations.data?.data ?? []
  const q = search.trim().toLowerCase()
  const shown = all.filter((l) => q === '' || `${l.name} ${l.solar_system_name ?? ''}`.toLowerCase().includes(q))
  const bySystem = new Map<string, Location[]>()
  for (const l of shown) bySystem.set(l.solar_system_name ?? 'Unknown system', [...(bySystem.get(l.solar_system_name ?? 'Unknown system') ?? []), l])
  const label = saved.length === 0 ? 'All locations' : saved.length === 1 ? (all.find((l) => l.location_id === saved[0])?.name ?? '1 location') : `${saved.length} locations`

  return (
    <div className="loc-filter">
      <button
        type="button"
        className="button"
        aria-expanded={open}
        disabled={locations.isSuccess && all.length === 0}
        onClick={() => {
          setDraft(saved)
          setOpen(!open)
        }}
      >
        {label} ▾
      </button>
      {open ? (
        <div className="panel loc-menu">
          <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search location…" aria-label="Search location" />
          <div className="loc-list">
            {[...bySystem.entries()]
              .sort(([a], [b]) => a.localeCompare(b))
              .map(([system, locs]) => (
                <fieldset key={system}>
                  <legend className="muted small">{system}</legend>
                  {locs.map((l) => (
                    <label key={l.location_id} className="loc-item">
                      <input
                        type="checkbox"
                        checked={draft.includes(l.location_id)}
                        onChange={() => setDraft((d) => (d.includes(l.location_id) ? d.filter((x) => x !== l.location_id) : [...d, l.location_id]))}
                      />
                      <span>{l.name}</span>
                    </label>
                  ))}
                </fieldset>
              ))}
            {shown.length === 0 ? <p className="muted small">No locations found.</p> : null}
          </div>
          <div className="page-actions">
            <button type="button" className="button" onClick={() => setDraft([])}>
              Clear
            </button>
            <button
              type="button"
              className="button"
              onClick={() => {
                setOpen(false)
                onApply(draft)
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

function ProgramBody({ program, volumes }: { program: Detail; volumes: Record<string, number> }) {
  const navigate = useNavigate()
  const { rename, remove, setLocations } = useBuildProgramMutations(program.id)
  const [locationIds, setLocationIds] = useState<number[]>(program.location_ids)
  const [name, setName] = useState(program.name)
  const [expandAll, setExpandAll] = useState(false)
  const progress = useBuildProgramProgress(program.id, locationIds)
  const steps = program.steps as Step[]
  const data = progress.data?.data
  const progressSteps = data?.steps as ProgressStep[] | undefined

  function saveName() {
    const next = name.trim()
    if (next === '' || next === program.name) return setName(program.name)
    rename.mutate(next, { onError: () => setName(program.name) })
  }

  function applyLocations(ids: number[]) {
    setLocationIds(ids)
    setLocations.mutate(ids)
  }

  
  const sections = program.sections.length > 0 ? program.sections : [{ name: '', step_count: steps.length }]
  let offset = 0
  const blocks = sections.map((s, si) => {
    const start = offset
    offset += s.step_count
    return { section: s, index: si, start, steps: steps.slice(start, start + s.step_count) }
  })

  const blueprints = data?.required_blueprints ?? []
  const missing = blueprints.filter((b) => !b.owned).length

  return (
    <>
      <nav aria-label="Breadcrumb" className="small">
        <Link to="/build-programs">Build programs</Link> / {program.name}
      </nav>
      <div className="page-head">
        <div className="program-head">
          {program.t2_type_id ? <img src={icon(program.t2_type_id)} alt="" width={32} height={32} onError={iconFallback} /> : null}
          <div>
            <input
              className="name-input"
              value={name}
              aria-label="Program name"
              title="Click to rename"
              onChange={(e) => setName(e.target.value)}
              onBlur={saveName}
              onKeyDown={(e) => {
                if (e.key === 'Enter') e.currentTarget.blur()
                if (e.key === 'Escape') {
                  setName(program.name)
                  e.currentTarget.blur()
                }
              }}
            />
            <div className="muted small">
              {program.program_type === 'reaction' ? <span className="badge">Reaction plan</span> : null}
              {program.t2_type_name ?? ''}
            </div>
          </div>
        </div>
        <div className="page-actions">
          <LocationFilter programId={program.id} saved={locationIds} onApply={applyLocations} />
          <button type="button" className="button" onClick={() => progress.refetch()} disabled={progress.isFetching}>
            {progress.isFetching ? 'Refreshing…' : 'Refresh'}
          </button>
          <button
            type="button"
            className="button"
            disabled={remove.isPending}
            onClick={() => {
              if (window.confirm('Delete this build program?')) remove.mutate(program.id, { onSuccess: () => navigate('/build-programs') })
            }}
          >
            Delete
          </button>
        </div>
      </div>
      {rename.isError || setLocations.isError || remove.isError ? (
        <p role="alert" className="panel warn">
          That change did not go through.
        </p>
      ) : null}

      <section className="panel">
        {progress.isError ? (
          <p role="alert">
            {progress.error instanceof ApiError && progress.error.status === 400
              ? 'No characters are linked, so there is no stock to count.'
              : 'Could not load progress.'}
          </p>
        ) : (
          <>
            <div className="page-head">
              <span className="muted small">
                {data
                  ? data.character_names.length > 0
                    ? `Tracked across: ${data.character_names.join(', ')}${locationIds.length > 0 ? ' (filtered by location)' : ''}`
                    : 'No characters linked'
                  : 'Loading progress across all characters…'}
              </span>
              {data ? <strong>{data.overall_progress}%</strong> : null}
            </div>
            <Bar pct={data?.overall_progress ?? 0} label="Overall progress" />
          </>
        )}
      </section>

      <div className="program-layout">
        <section className="panel">
          <div className="page-head">
            <h2>Production steps</h2>
            <button type="button" className="button" onClick={() => setExpandAll(!expandAll)}>
              {expandAll ? 'Collapse all' : 'Expand all'}
            </button>
          </div>
          {blocks.map((b) => {
            const sectionProgress = data?.sections[b.index]
            return (
              <div key={b.index}>
                {program.sections.length > 0 ? (
                  <div className="section-head">
                    <strong>{b.section.name}</strong>{' '}
                    <span className="muted small">
                      {b.section.step_count} {b.section.step_count === 1 ? 'step' : 'steps'}
                    </span>
                    {sectionProgress ? <span className="small"> · {sectionProgress.overall_progress}%</span> : null}
                  </div>
                ) : null}
                {b.steps.map((s, i) => (
                  <StepCard
                    key={`${s.type_id}-${b.start + i}`}
                    step={s}
                    progress={progressSteps?.[b.start + i]}
                    volumes={volumes}
                    depth={0}
                    expandAll={expandAll}
                  />
                ))}
              </div>
            )
          })}
        </section>

        <div className="program-side">
          <section className="panel">
            <h2>
              Required blueprints &amp; formulas{' '}
              {data && blueprints.length > 0 ? (
                <span className={missing > 0 ? 'badge badge-stale' : 'badge badge-fresh'}>{missing > 0 ? `${missing} missing` : 'All owned'}</span>
              ) : null}
            </h2>
            {data && blueprints.length === 0 ? <p className="muted small">No blueprints or formulas required.</p> : null}
            <ul className="mat-list small">
              {blueprints.map((b) => (
                <li key={b.type_id}>
                  <img src={icon(b.type_id)} alt="" width={20} height={20} loading="lazy" onError={iconFallback} />
                  <span className="grow">{b.name}</span>
                  <span className="badge">{b.activity_type === 'reaction' ? 'Reaction' : 'BPC/BPO'}</span>
                  <span className={b.owned ? 'badge badge-fresh' : 'badge badge-stale'}>{b.owned ? 'Owned' : 'Missing'}</span>
                </li>
              ))}
            </ul>
          </section>
          <SupplyPanel title="Base materials to acquire" empty="No base materials to acquire." items={data?.base_materials ?? []} />
          <SupplyPanel title="Fuel blocks" empty="No fuel blocks required." items={data?.fuel_blocks ?? []} />
        </div>
      </div>

      <ReactionsPanel reactions={data?.reactions_summary ?? []} />
    </>
  )
}

export default function BuildProgram() {
  const id = Number(useParams().id)
  const program = useBuildProgram(id)

  if (program.isPending) return <p>Loading build program…</p>
  if (program.isError) {
    return program.error instanceof ApiError && program.error.status === 404 ? (
      <p role="alert">
        Build program not found. <Link to="/build-programs">Back to your programs</Link>
      </p>
    ) : (
      <p role="alert">Could not load the build program.</p>
    )
  }
  return <ProgramBody key={program.data.data.id} program={program.data.data} volumes={program.data.meta.volumes} />
}
