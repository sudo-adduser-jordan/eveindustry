import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  useBuildProgramProgress,
  useBuildProgramsOverview,
  useDeleteBuildProgram,
  useDisableBuildPrograms,
  useEnableBuildPrograms,
  useMergeBuildPrograms,
} from '../api/corp'
import { formatAge } from '../components/FreshnessBadge'
import { WriteError } from '../components/CorpCommon'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

type Overview = NonNullable<ReturnType<typeof useBuildProgramsOverview>['data']>['data']
type Program = Overview['programs'][number]


export function progressClass(pct: number): string {
  return pct >= 100 ? 'bar-good' : pct >= 50 ? 'bar-manufacturing' : 'bar-research'
}

function EnablePanel({ overview }: { overview: Overview }) {
  const enable = useEnableBuildPrograms()
  const [picked, setPicked] = useState('')
  return (
    <section className="panel" aria-labelledby="enable-heading">
      <h2 id="enable-heading">Corporate build programs not enabled</h2>
      <p className="muted">
        A character with the <strong>Director</strong> or <strong>CEO</strong> role must enable this feature. That
        character&apos;s token is used for corporation asset lookups, so every member sees the same stock.
      </p>
      {overview.eligible_characters.length > 0 ? (
        <form
          className="scan-form"
          onSubmit={(e) => {
            e.preventDefault()
            enable.mutate(picked === '' ? undefined : Number(picked))
          }}
        >
          <div className="scan-field">
            <label htmlFor="director">Director/CEO character</label>
            <select id="director" value={picked} onChange={(e) => setPicked(e.target.value)}>
              {overview.eligible_characters.map((c) => (
                <option key={c.character_id} value={c.character_id}>
                  {c.name} ({c.corporation_name})
                </option>
              ))}
            </select>
          </div>
          <button type="submit" className="button" disabled={enable.isPending}>
            Enable corporate build programs
          </button>
          <WriteError error={enable.error} fallback="Could not enable corporate build programs." />
        </form>
      ) : (
        <p>
          No eligible characters found. You need a character with the <strong>Director</strong> or <strong>CEO</strong>{' '}
          role and the <code>esi-assets.read_corporation_assets.v1</code> scope. <Link to="/characters">Manage characters</Link>
        </p>
      )}
    </section>
  )
}

function ProgramCard({
  program,
  canManage,
  merging,
  selected,
  onSelect,
}: {
  program: Program
  canManage: boolean
  merging: boolean
  selected: boolean
  onSelect: (checked: boolean) => void
}) {
  const progress = useBuildProgramProgress(program.id, program.input_location_ids, program.output_location_ids)
  const remove = useDeleteBuildProgram()
  const filtered = program.input_location_ids.length > 0 || program.output_location_ids.length > 0
  const pct = progress.data?.data.overall_progress

  return (
    <li className={selected ? 'panel program-card selected' : 'panel program-card'}>
      <div className="slot-head">
        <div className="program-title">
          {merging ? (
            <input type="checkbox" checked={selected} onChange={(e) => onSelect(e.target.checked)} aria-label={`Select ${program.name} to merge`} />
          ) : null}
          {program.t2_type_id ? <img src={typeIconUrl(program.t2_type_id)} alt="" width={32} height={32} loading="lazy" onError={iconFallback} /> : null}
          <div>
            <Link to={`/corporate/build-programs/${program.id}`}>
              <strong>{program.name}</strong>
            </Link>
            <div className="muted small">
              {program.program_type === 'reaction' ? <span className="badge text-reactions">Reaction</span> : null}
              {program.t2_type_name ?? (program.program_type === 'reaction' ? 'Reaction plan' : '')}
            </div>
          </div>
        </div>
        {canManage ? (
          <button
            type="button"
            className="button"
            disabled={remove.isPending}
            aria-label={`Delete ${program.name}`}
            onClick={() => {
              if (window.confirm(`Delete the program "${program.name}"?`)) remove.mutate(program.id)
            }}
          >
            Delete
          </button>
        ) : null}
      </div>
      <div className="muted small">
        {program.step_count} production {program.step_count === 1 ? 'step' : 'steps'}
        {filtered ? <span className="badge">Filtered by saved locations</span> : null}
      </div>
      <div
        className={`bar ${pct === undefined ? '' : progressClass(pct)}`}
        role="progressbar"
        aria-valuenow={pct ?? 0}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={`${program.name} progress`}
      >
        <span style={{ width: `${pct ?? 0}%` }} />
      </div>
      <div className="slot-head small muted">
        <span>{progress.isPending ? 'Loading…' : progress.isError ? 'Progress unavailable' : `${pct}% of the stock is in place`}</span>
        {program.updated_at ? <span>{formatAge(program.updated_at)}</span> : null}
      </div>
      <WriteError error={remove.error} fallback="Could not delete the program." />
    </li>
  )
}

export default function BuildPrograms() {
  const overview = useBuildProgramsOverview()
  const disable = useDisableBuildPrograms()
  const merge = useMergeBuildPrograms()
  const navigate = useNavigate()
  const [merging, setMerging] = useState(false)
  const [picked, setPicked] = useState<number[]>([])
  const [mergeName, setMergeName] = useState('')

  if (overview.isPending) return <p>Loading corporate build programs…</p>
  if (overview.isError) return <p role="alert">Could not load corporate build programs.</p>

  const o = overview.data.data
  return (
    <>
      <div className="page-head">
        <div>
          <h1>Corporate build programs</h1>
          <p className="muted">Corporation-wide production tracking, enabled by a Director or CEO using corporation asset access.</p>
        </div>
        {o.enabled && o.can_manage && o.programs.length >= 2 ? (
          <button type="button" className="button" aria-pressed={merging} onClick={() => { setMerging(!merging); setPicked([]) }}>
            Merge
          </button>
        ) : null}
      </div>

      {!o.enabled ? (
        <EnablePanel overview={o} />
      ) : !o.can_view ? (
        <section className="panel warn">
          <h2>Access restricted</h2>
          <p className="muted">
            Your characters are not in <strong>{o.corporation_name}</strong>. Only corporation members can view corporate build programs.
          </p>
        </section>
      ) : (
        <>
          <p className="panel slot-head">
            <span className="muted">
              Assets provided by <strong>{o.provider_character_name ?? 'an unknown character'}</strong> · <strong>{o.corporation_name}</strong>
            </span>
            {o.enabled_by_me ? (
              <button
                type="button"
                className="button"
                disabled={disable.isPending}
                onClick={() => {
                  if (window.confirm('Disable corporate build programs? Existing programs will be preserved.')) disable.mutate()
                }}
              >
                Disable
              </button>
            ) : null}
          </p>
          <WriteError error={disable.error} fallback="Could not disable corporate build programs." />

          {merging ? (
            <form
              className="panel filters"
              onSubmit={(e) => {
                e.preventDefault()
                merge.mutate(
                  { program_ids: picked, name: mergeName.trim() },
                  { onSuccess: (res) => navigate(`/corporate/build-programs/${res.data.id}`) },
                )
              }}
            >
              <div className="scan-field">
                <label htmlFor="merge-name">Name of the merged program</label>
                <input id="merge-name" type="text" maxLength={255} required value={mergeName} onChange={(e) => setMergeName(e.target.value)} />
              </div>
              <button type="submit" className="button" disabled={picked.length < 2 || mergeName.trim() === '' || merge.isPending}>
                Merge {picked.length} selected
              </button>
              <span className="muted small">Pick at least two programs. The originals are kept.</span>
              <WriteError error={merge.error} fallback="Could not merge the programs." />
            </form>
          ) : null}

          {o.programs.length === 0 ? (
            <section className="panel">
              <h2>{o.can_manage ? 'No corporate build programs yet' : 'No corporate build programs'}</h2>
              <p className="muted">
                {o.can_manage ? (
                  <>
                    Create one from the <a href="/calculators/invention-planner">Invention Planner</a> or the{' '}
                    <a href="/calculators/reaction-planner">Reaction Planner</a>.
                  </>
                ) : (
                  'No programs have been created yet. Directors, CEOs and Factory Managers can add programs.'
                )}
              </p>
            </section>
          ) : (
            <ul className="cards program-cards">
              {o.programs.map((p) => (
                <ProgramCard
                  key={p.id}
                  program={p}
                  canManage={o.can_manage}
                  merging={merging}
                  selected={picked.includes(p.id)}
                  onSelect={(checked) => setPicked(checked ? [...picked, p.id] : picked.filter((id) => id !== p.id))}
                />
              ))}
            </ul>
          )}
        </>
      )}
    </>
  )
}
