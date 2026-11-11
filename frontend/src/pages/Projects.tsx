import { Link } from 'react-router-dom'
import { useProjects, useRefreshProjects } from '../api/corp'
import { ErrorNotice, formatUtc, WriteError } from '../components/CorpCommon'
import { formatQty } from '../components/format'
import { ScopeWarning } from '../components/ScopeWarning'

export const PROJECT_SCOPES = [{ scope: 'esi-corporations.read_projects.v1', description: 'Read Corporation Projects' }]


export function formatMillions(isk: number, decimals = 1): string {
  return `${(isk / 1_000_000).toFixed(decimals)}M`
}

type Project = NonNullable<ReturnType<typeof useProjects>['data']>['data'][number]

function StateBadge({ project }: { project: Project }) {
  if (project.is_complete) return <span className="badge badge-fresh">Complete</span>
  if (project.state === 'Active') return <span className="badge">Active</span>
  return <span className="badge badge-stale">{project.state ?? '—'}</span>
}

function Contributors({ project }: { project: Project }) {
  if (project.contributors.length === 0) return <span className="muted">—</span>
  return (
    <details>
      <summary className="small">{project.contributors.length}</summary>
      <ul className="plain-list">
        {project.contributors.map((c, i) => (
          <li key={c.character_id ?? i}>
            {c.character_name ?? `Character #${c.character_id ?? '?'}`}
            {c.contributed !== null ? <span className="muted"> — {formatQty(Number(c.contributed))}</span> : null}
          </li>
        ))}
      </ul>
    </details>
  )
}

export default function Projects() {
  const projects = useProjects()
  const refresh = useRefreshProjects()

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Corporation projects</h1>
          <p className="muted">
            {projects.data?.meta.summary.corporation_name
              ? `Analytics and performance for ${projects.data.meta.summary.corporation_name}`
              : 'Corporation project analytics and performance tracking'}
          </p>
        </div>
        <div className="page-actions">
          <Link className="button" to="/corporate/projects/contributors">
            Contributor leaderboard
          </Link>
          <button type="button" className="button" disabled={refresh.isPending || projects.isPending} onClick={() => refresh.mutate()}>
            {refresh.isPending ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </div>
      <ScopeWarning required={PROJECT_SCOPES} />
      <WriteError error={refresh.error} fallback="Could not refresh the projects." />

      {projects.isPending ? (
        <p>Loading projects… this asks ESI about every project, so a cold cache can take a while.</p>
      ) : projects.isError ? (
        <ErrorNotice error={projects.error} what="the projects" unavailable="Corporation projects unavailable:" />
      ) : (
        <Loaded projects={projects.data.data} summary={projects.data.meta.summary} />
      )}
    </>
  )
}

function Loaded({
  projects,
  summary,
}: {
  projects: Project[]
  summary: NonNullable<ReturnType<typeof useProjects>['data']>['meta']['summary']
}) {
  return (
    <>
      <dl className="stats">
        <div className="panel">
          <dt className="muted">Total projects</dt>
          <dd>{summary.total_projects}</dd>
        </div>
        <div className="panel">
          <dt className="muted">Completed</dt>
          <dd className="text-profit">{summary.completed_projects}</dd>
        </div>
        <div className="panel">
          <dt className="muted">In progress</dt>
          <dd>{summary.in_progress_projects}</dd>
        </div>
        <div className="panel">
          <dt className="muted">Rewards earned (ISK)</dt>
          <dd>{formatMillions(summary.total_reward_earned)}</dd>
        </div>
      </dl>

      {summary.total_desired > 0 ? (
        <div className="panel">
          <div className="slot-head">
            <span className="muted">Overall delivery progress</span>
            <strong>{summary.overall_completion_pct}%</strong>
          </div>
          <div className="bar bar-manufacturing" role="progressbar" aria-valuenow={summary.overall_completion_pct} aria-valuemin={0} aria-valuemax={100} aria-label="Overall delivery progress">
            <span style={{ width: `${summary.overall_completion_pct}%` }} />
          </div>
          <div className="slot-head small muted">
            <span>
              {formatQty(summary.total_progress)} / {formatQty(summary.total_desired)} units
            </span>
            {summary.total_reward_initial > 0 ? (
              <span>
                {formatMillions(summary.total_reward_remaining)} ISK remaining of {formatMillions(summary.total_reward_initial)} ISK
              </span>
            ) : null}
          </div>
        </div>
      ) : null}

      {projects.length === 0 ? (
        <p className="panel muted">No projects found.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Project</th>
                <th>Contributors</th>
                <th>State</th>
                <th className="num">Progress</th>
                <th>Delivery</th>
                <th className="num">Reward earned</th>
                <th className="num">Reward remaining</th>
                <th className="num">Last modified</th>
              </tr>
            </thead>
            <tbody>
              {projects.map((p) => (
                <tr key={p.id}>
                  <td>{p.name}</td>
                  <td>
                    <Contributors project={p} />
                  </td>
                  <td>
                    <StateBadge project={p} />
                  </td>
                  <td className="num">
                    {formatQty(p.progress.current)} / {formatQty(p.progress.desired)}
                  </td>
                  <td>
                    <div className={p.is_complete ? 'bar bar-research' : 'bar bar-manufacturing'} role="progressbar" aria-valuenow={p.completion_pct} aria-valuemin={0} aria-valuemax={100} aria-label={`${p.name} delivery`}>
                      <span style={{ width: `${p.completion_pct}%` }} />
                    </div>
                    <small className="muted">{p.completion_pct}%</small>
                  </td>
                  <td className="num">{p.reward ? <span className="text-profit">{formatMillions(p.reward_earned, 2)}</span> : <span className="muted">—</span>}</td>
                  <td className="num">
                    {p.reward ? (
                      <span className={p.reward.remaining > 0 ? undefined : 'text-profit'}>{formatMillions(p.reward.remaining, 2)}</span>
                    ) : (
                      <span className="muted">—</span>
                    )}
                  </td>
                  <td className="num muted">{p.last_modified ? formatUtc(p.last_modified) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}
