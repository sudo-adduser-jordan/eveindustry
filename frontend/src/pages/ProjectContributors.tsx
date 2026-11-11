import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useProjectContributors, useRefreshProjects } from '../api/corp'
import { ErrorNotice, WriteError } from '../components/CorpCommon'
import { formatQty } from '../components/format'
import { ScopeWarning } from '../components/ScopeWarning'
import { PROJECT_SCOPES } from './Projects'


export function formatIsk2(n: number): string {
  return `${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ISK`
}

export default function ProjectContributors() {
  const [params, setParams] = useSearchParams()
  const query = {
    project_name: params.get('project_name') ?? undefined,
    date_from: params.get('date_from') ?? undefined,
    date_to: params.get('date_to') ?? undefined,
  }
  const board = useProjectContributors(query)
  const refresh = useRefreshProjects()
  const [draft, setDraft] = useState<{ name?: string; from?: string; to?: string }>({})
  const filtered = Boolean(query.project_name || query.date_from || query.date_to)

  function submit(e: FormEvent) {
    e.preventDefault()
    const next: Record<string, string> = {}
    const name = (draft.name ?? query.project_name ?? '').trim()
    const from = draft.from ?? query.date_from ?? ''
    const to = draft.to ?? query.date_to ?? ''
    if (name) next.project_name = name
    if (from) next.date_from = from
    if (to) next.date_to = to
    setParams(next)
  }

  const contributors = board.data?.data ?? []
  const summary = board.data?.meta.summary
  return (
    <>
      <div className="page-head">
        <div>
          <h1>Project contributor leaderboard</h1>
          <p className="muted">
            {summary?.corporation_name ? `ISK contribution rankings for ${summary.corporation_name}` : 'ISK contribution rankings'}
            , rewarded projects only, Closed and Completed.
          </p>
        </div>
        <div className="page-actions">
          <Link className="button" to="/corporate/projects">
            All projects
          </Link>
          <button type="button" className="button" disabled={refresh.isPending} onClick={() => refresh.mutate()}>
            {refresh.isPending ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </div>
      <ScopeWarning required={PROJECT_SCOPES} />
      <WriteError error={refresh.error} fallback="Could not refresh the projects." />

      <form className="filters" onSubmit={submit}>
        <div className="scan-field">
          <label htmlFor="project_name">Project name</label>
          <input
            id="project_name"
            type="text"
            placeholder="Filter by name…"
            value={draft.name ?? query.project_name ?? ''}
            onChange={(e) => setDraft({ ...draft, name: e.target.value })}
          />
        </div>
        <div className="scan-field">
          <label htmlFor="date_from">From</label>
          <input id="date_from" type="date" value={draft.from ?? query.date_from ?? ''} onChange={(e) => setDraft({ ...draft, from: e.target.value })} />
        </div>
        <div className="scan-field">
          <label htmlFor="date_to">To</label>
          <input id="date_to" type="date" value={draft.to ?? query.date_to ?? ''} onChange={(e) => setDraft({ ...draft, to: e.target.value })} />
        </div>
        <button type="submit" className="button">
          Filter
        </button>
        {filtered ? (
          <button
            type="button"
            className="button"
            onClick={() => {
              setDraft({})
              setParams({})
            }}
          >
            Clear
          </button>
        ) : null}
      </form>

      {board.isPending ? (
        <p>Loading the leaderboard… this asks ESI about every project, so a cold cache can take a while.</p>
      ) : board.isError ? (
        <ErrorNotice error={board.error} what="the leaderboard" unavailable="Corporation projects unavailable:" />
      ) : (
        <>
          <dl className="stats">
            <div className="panel">
              <dt className="muted">Contributors</dt>
              <dd>{contributors.length}</dd>
            </div>
            <div className="panel">
              <dt className="muted">Rewarded projects</dt>
              <dd>{summary?.total_rewarded_projects ?? 0}</dd>
            </div>
            <div className="panel">
              <dt className="muted">Total attributed ISK</dt>
              <dd className="text-profit">{formatIsk2(summary?.total_attributed_isk ?? 0)}</dd>
            </div>
          </dl>
          {contributors.length === 0 ? (
            <p className="panel muted">
              {filtered
                ? 'No rewarded projects with contributors found matching the selected filters.'
                : 'No rewarded projects with contributors found.'}
            </p>
          ) : (
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Contributor</th>
                    <th className="num">Projects</th>
                    <th className="num">Attributed ISK</th>
                    <th>Share</th>
                  </tr>
                </thead>
                <tbody>
                  {contributors.map((c, i) => (
                    <tr key={c.character_id ?? `anon-${i}`}>
                      <td>
                        <span className={i < 3 ? 'badge score-best' : 'badge'}>{i + 1}</span>
                      </td>
                      <td>
                        {c.character_name ?? `Character #${c.character_id ?? '?'}`}
                        <ul className="pills">
                          {c.projects.map((p) => (
                            <li key={p.id} className="pill" title={`${formatIsk2(p.attributed_isk)}, ${formatQty(p.quantity)} units`}>
                              {p.name}
                            </li>
                          ))}
                        </ul>
                      </td>
                      <td className="num">{c.project_count}</td>
                      <td className="num text-profit">{formatIsk2(c.attributed_isk)}</td>
                      <td>
                        <div className="share">
                          <div className="bar bar-manufacturing" role="progressbar" aria-valuenow={Math.min(100, c.attributed_pct)} aria-valuemin={0} aria-valuemax={100} aria-label={`${c.character_name ?? 'Contributor'} share`}>
                            <span style={{ width: `${Math.min(100, c.attributed_pct)}%` }} />
                          </div>
                          <span className="small muted">{c.attributed_pct.toFixed(1)}%</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </>
  )
}
