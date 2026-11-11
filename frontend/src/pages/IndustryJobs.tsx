import { useSearchParams } from 'react-router-dom'
import { useJobsOverview } from '../api/queries'
import type { components } from '../api/schema'
import { formatIsk, formatQty } from '../components/format'
import { FreshnessBadge } from '../components/FreshnessBadge'
import { REGIONS, type RegionId } from '../components/ScanForm'
import { ScopeWarning } from '../components/ScopeWarning'

type Group = components['schemas']['JobsOverviewGroupResource']
type Job = components['schemas']['JobsOverviewJobResource']
type Item = components['schemas']['JobsOverviewItemResource']

const SCOPES = [
  { scope: 'esi-industry.read_character_jobs.v1', description: 'Read Character Industry Jobs' },
  { scope: 'esi-industry.read_corporation_jobs.v1', description: 'Read Corporation Industry Jobs' },
]


const VALUED = new Set(['Manufacturing', 'Reactions'])

function barKind(activity: string): string {
  if (activity === 'Reactions') return 'reactions'
  if (activity === 'Manufacturing') return 'manufacturing'
  return 'research'
}


export function Value({ value }: { value: number | null }) {
  return value === null || value <= 0 ? <span className="muted">—</span> : <span className="text-warn">{formatIsk(value)} ISK</span>
}

function JobRow({ job, activity }: { job: Job; activity: string }) {
  const valued = VALUED.has(activity)
  return (
    <tr>
      <td>
        <span className="asset-name">
          {job.item_image_url ? <img src={job.item_image_url} alt="" width={28} height={28} loading="lazy" /> : null}
          <span>{job.item_name}</span>
        </span>
      </td>
      <td className="num">{formatQty(job.runs)}</td>
      <td className="num">{valued ? formatQty(job.expected_output) : <span className="muted">—</span>}</td>
      <td className="num">{valued ? <Value value={job.estimated_value} /> : <span className="muted">—</span>}</td>
      <td>
        {job.time_remaining}
        {job.ready_for_delivery ? <span className="badge badge-fresh">Ready</span> : null}
      </td>
      <td>
        <div
          className={`bar bar-${barKind(activity)}`}
          role="progressbar"
          aria-label={`${job.item_name} progress`}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={job.progress_pct}
        >
          <span style={{ width: `${job.progress_pct}%` }} />
        </div>
        <span className="muted small">{job.progress_pct}%</span>
      </td>
      <td>{job.character_name}</td>
      <td>{job.location}</td>
    </tr>
  )
}

function GroupPanel({ group, open }: { group: Group; open: boolean }) {
  return (
    <details className="panel" open={open}>
      <summary>
        <strong>{group.activity_name}</strong> <span className="badge">{group.jobs.length}</span>
      </summary>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th className="num">Runs</th>
              <th className="num">Output</th>
              <th className="num">Est. value</th>
              <th>Time left</th>
              <th>Progress</th>
              <th>Character</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>
            {group.jobs.map((job) => (
              <JobRow key={job.job_id} job={job} activity={group.activity_name} />
            ))}
          </tbody>
        </table>
      </div>
    </details>
  )
}

function SummaryPanel({ items, total }: { items: Item[]; total: number | null }) {
  if (items.length === 0) return null
  return (
    <details className="panel">
      <summary>
        <strong>Production summary</strong> <span className="badge">{items.length === 1 ? '1 item' : `${items.length} items`}</span>
      </summary>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th className="num">Total runs</th>
              <th className="num">Expected output</th>
              <th className="num">Est. value</th>
              <th className="num">Jobs</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.item_name}>
                <td>
                  <span className="asset-name">
                    {item.item_image_url ? <img src={item.item_image_url} alt="" width={28} height={28} loading="lazy" /> : null}
                    <span>{item.item_name}</span>
                  </span>
                </td>
                <td className="num">{formatQty(item.total_runs)}</td>
                <td className="num">{formatQty(item.total_expected_output)}</td>
                <td className="num">
                  <Value value={item.estimated_value} />
                </td>
                <td className="num">{item.job_count}</td>
              </tr>
            ))}
            <tr>
              <th>Total</th>
              <td />
              <td />
              <td className="num">
                <Value value={total} />
              </td>
              <td />
            </tr>
          </tbody>
        </table>
      </div>
    </details>
  )
}

export default function IndustryJobs() {
  const [params, setParams] = useSearchParams()
  const region = (params.get('region_id') ?? '10000002') as RegionId
  const jobs = useJobsOverview({ region_id: region })

  return (
    <>
      <div className="page-head">
        <h1>Industry jobs</h1>
        <label className="page-actions">
          <span className="muted">Market prices</span>
          <select
            value={region}
            onChange={(e) => setParams(e.target.value === '10000002' ? {} : { region_id: e.target.value })}
            aria-label="Market prices"
          >
            {REGIONS.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </select>
        </label>
      </div>
      <p>Active jobs across all your characters, grouped by activity.</p>
      <ScopeWarning required={SCOPES} />

      {jobs.isPending ? <p>Loading industry jobs…</p> : null}
      {jobs.isError ? (
        <p role="alert" className="panel warn">
          Could not load your industry jobs.
        </p>
      ) : null}
      {jobs.data ? (
        jobs.data.data.total_jobs === 0 ? (
          <p className="panel muted">No active industry jobs found.</p>
        ) : (
          <>
            <p>
              <FreshnessBadge
                label={`${jobs.data.meta.region.region_name} prices`}
                asOf={jobs.data.meta.freshness.prices_as_of}
                stale={jobs.data.meta.freshness.stale}
              />
            </p>
            <dl className="stats">
              <div className="panel">
                <dt className="muted">Total active jobs</dt>
                <dd>{jobs.data.data.total_jobs}</dd>
              </div>
              {jobs.data.data.groups.map((g) => (
                <div key={g.activity_name} className="panel">
                  <dt className="muted">{g.activity_name}</dt>
                  <dd>{g.jobs.length}</dd>
                </div>
              ))}
            </dl>
            <SummaryPanel items={jobs.data.data.summary} total={jobs.data.data.estimated_total} />
            {jobs.data.data.groups.map((g, i) => (
              <GroupPanel key={g.activity_name} group={g} open={i === 0} />
            ))}
          </>
        )
      ) : null}
    </>
  )
}
