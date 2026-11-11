import { ApiError } from '../api/client'
import { useClearIndustryCaches, useIndustryAvailability, useRefreshAvailability } from '../api/queries'
import type { components } from '../api/schema'
import { formatAge } from '../components/FreshnessBadge'
import { ScopeWarning } from '../components/ScopeWarning'

type CharacterSlots = components['schemas']['CharacterSlotsResource']
type SlotGroup = components['schemas']['SlotGroupResource']
type Job = components['schemas']['IndustryJobResource']
type ReadyJob = components['schemas']['ReadyJobResource']
type Kind = 'manufacturing' | 'research' | 'reactions'

const KINDS: Array<{ kind: Kind; label: string; jobs: string }> = [
  { kind: 'manufacturing', label: 'Manufacturing', jobs: 'Manufacturing jobs' },
  { kind: 'research', label: 'Research / Copy / Invention', jobs: 'Research, copy and invention jobs' },
  { kind: 'reactions', label: 'Reactions', jobs: 'Reaction jobs' },
]

const SCOPES = [
  { scope: 'esi-skills.read_skills.v1', description: 'Read Character Skills' },
  { scope: 'esi-industry.read_character_jobs.v1', description: 'Read Character Industry Jobs' },
  { scope: 'esi-industry.read_corporation_jobs.v1', description: 'Read Corporation Industry Jobs' },
]


export function readyLabel(endDate: string, now: Date = new Date()): string {
  return new Date(endDate).getTime() <= now.getTime() ? `Ready ${formatAge(endDate, now)}` : 'In under a minute'
}


function SlotMeter({ label, kind, group }: { label: string; kind: Kind; group: SlotGroup }) {
  const busy = Math.max(0, group.total - Math.max(0, group.available))
  const pct = group.total > 0 ? Math.round((busy / group.total) * 100) : 0
  return (
    <div className="slot">
      <div className="slot-head">
        <span>{label}</span>
        <span className="num">
          {group.available} / {group.total}
        </span>
      </div>
      <div
        className={`bar bar-${kind}`}
        role="progressbar"
        aria-label={`${label} slots in use`}
        aria-valuemin={0}
        aria-valuemax={group.total}
        aria-valuenow={busy}
      >
        <span style={{ width: `${pct}%` }} />
      </div>
      <div className="muted small">{busy} busy</div>
    </div>
  )
}

function JobCard({ job, kind }: { job: Job; kind: Kind }) {
  return (
    <li className="job">
      <div className="job-head">
        {job.item_image_url ? <img src={job.item_image_url} alt="" width={32} height={32} loading="lazy" /> : null}
        <div>
          <strong>{job.item_name}</strong>
          <div className="muted small">
            {job.activity_name} · {job.runs} runs
            {job.probability_label ? ` · ${job.probability_label} chance` : null}
          </div>
        </div>
      </div>
      <div
        className={`bar bar-${kind}`}
        role="progressbar"
        aria-label={`${job.item_name} progress`}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={job.progress_pct}
      >
        <span style={{ width: `${job.progress_pct}%` }} />
      </div>
      <div className="job-foot small">
        <span>{job.ready_for_delivery ? <span className="badge badge-fresh">Ready for delivery</span> : `${job.time_remaining} left`}</span>
        <span className="muted">{job.progress_pct}%</span>
      </div>
    </li>
  )
}

function CharacterCard({ c }: { c: CharacterSlots }) {
  const jobCount = c.manufacturing.jobs.length + c.research.jobs.length + c.reactions.jobs.length
  return (
    <article className="panel char">
      <header className="char-head">
        {c.portrait_url ? <img src={c.portrait_url} alt="" width={48} height={48} loading="lazy" /> : null}
        <div>
          <h2>{c.character_name}</h2>
          <div className="muted small">{c.corporation_name ?? 'No corporation'}</div>
        </div>
      </header>
      <div className="slots">
        {KINDS.map(({ kind, label }) => (
          <SlotMeter key={kind} label={label} kind={kind} group={c[kind]} />
        ))}
      </div>
      <details>
        <summary>
          Skills and jobs <span className="badge">{jobCount}</span>
        </summary>
        <dl className="skills small">
          <dt>Manufacturing</dt>
          <dd>
            Mass Production {c.skills.mass_production} / Advanced {c.skills.adv_mass_production}
          </dd>
          <dt>Research</dt>
          <dd>
            Laboratory Operation {c.skills.laboratory_operation} / Advanced {c.skills.adv_laboratory_operation}
          </dd>
          <dt>Reactions</dt>
          <dd>
            Mass Reactions {c.skills.mass_reactions} / Advanced {c.skills.adv_mass_reactions}
          </dd>
        </dl>
        {KINDS.map(({ kind, jobs }) => (
          <section key={kind}>
            <h3>
              {jobs} <span className="badge">{c[kind].jobs.length}</span>
            </h3>
            {c[kind].jobs.length === 0 ? (
              <p className="muted small">None running.</p>
            ) : (
              <ul className="jobs">
                {c[kind].jobs.map((job) => (
                  <JobCard key={job.job_id} job={job} kind={kind} />
                ))}
              </ul>
            )}
          </section>
        ))}
        {c.completing_soon.length > 0 ? (
          <p className="small">
            Completing within the hour:{' '}
            {c.completing_soon.map((j) => (
              <span key={j.job_id} className="badge badge-stale">
                {j.minutes_remaining} min
              </span>
            ))}
          </p>
        ) : null}
      </details>
    </article>
  )
}

function ReadyList({ jobs }: { jobs: ReadyJob[] }) {
  if (jobs.length === 0) return null
  return (
    <details className="panel">
      <summary>
        <strong>Ready for collection</strong> <span className="badge badge-fresh">{jobs.length}</span>
      </summary>
      <ul className="jobs">
        {jobs.map((j) => (
          <li key={j.job_id} className="job">
            <div className="job-head">
              {j.item_image_url ? <img src={j.item_image_url} alt="" width={32} height={32} loading="lazy" /> : null}
              <div>
                <strong>{j.item_name}</strong>
                <div className="muted small">
                  {j.character_name} · {j.activity_name} · {j.location}
                </div>
              </div>
            </div>
            <div className="small">{readyLabel(j.end_date)}</div>
          </li>
        ))}
      </ul>
    </details>
  )
}

export default function IndustrySlots() {
  const availability = useIndustryAvailability()
  const refresh = useRefreshAvailability()
  const clear = useClearIndustryCaches()

  if (availability.isPending) return <p>Loading industry slots…</p>
  if (availability.isError) return <p role="alert">Could not load your industry slots.</p>

  const { characters, totals, ready_for_collection } = availability.data.data
  const busy = refresh.isPending || clear.isPending
  return (
    <>
      <div className="page-head">
        <h1>Industry slots</h1>
        <div className="page-actions">
          <button type="button" className="button" disabled={busy} onClick={() => refresh.mutate()}>
            {refresh.isPending ? 'Refreshing…' : 'Refresh'}
          </button>
          <button
            type="button"
            className="button"
            disabled={busy}
            onClick={() => {
              if (window.confirm('Clear all industry and ESI caches for your characters? Allowed once every 30 minutes.')) {
                clear.mutate()
              }
            }}
          >
            Clear caches
          </button>
        </div>
      </div>
      {refresh.isError ? (
        <p role="alert" className="panel warn">
          Could not refresh your slots.
        </p>
      ) : null}
      {clear.isError ? (
        <p role="alert" className="panel warn">
          {clear.error instanceof ApiError && clear.error.status === 429 ? clear.error.message : 'Could not clear the caches.'}
        </p>
      ) : null}
      {clear.isSuccess ? (
        <p role="status" className="panel">
          Caches cleared.
        </p>
      ) : null}

      <ScopeWarning required={SCOPES} />
      <ReadyList jobs={ready_for_collection} />

      <dl className="stats">
        <div className="panel">
          <dt className="muted">Characters</dt>
          <dd>{characters.length}</dd>
        </div>
        {KINDS.map(({ kind, label }) => (
          <div key={kind} className="panel">
            <dt className="muted">{label} free</dt>
            <dd className={`text-${kind}`}>
              {totals[kind].available} / {totals[kind].total}
            </dd>
          </div>
        ))}
      </dl>

      {characters.length === 0 ? (
        <p className="panel muted">No characters found. Make sure your characters have granted the ESI scopes above.</p>
      ) : (
        <div className="chars">
          {characters.map((c) => (
            <CharacterCard key={c.character_id} c={c} />
          ))}
        </div>
      )}
      <p className="muted small">Numbers are available / total slots; bars show slots in use.</p>
    </>
  )
}
