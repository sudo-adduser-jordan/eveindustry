import { useState } from 'react'
import { ApiError } from '../api/client'
import { usePlanetary, useRefreshPlanetary } from '../api/queries'
import type { components } from '../api/schema'
import { formatQty } from '../components/format'
import { ScopeWarning } from '../components/ScopeWarning'

type Planet = components['schemas']['PlanetResource']
type Extractor = components['schemas']['PlanetaryExtractorResource']
type Storage = components['schemas']['PlanetaryStorageResource']
type Capacity = components['schemas']['PlanetaryDashboardResource']['capacity'][number]

const SCOPES = [{ scope: 'esi-planets.manage_planets.v1', description: 'Manage Planets (Planetary Interaction)' }]

const CAPACITY_LABELS: Record<Capacity['status'], string> = {
  balanced: 'Balanced',
  under_capacity: 'Under capacity',
  over_capacity: 'Over capacity',
  idle_factories: 'Idle factories',
}

const one = new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })


export function timeRemaining(expiry: string, now: Date = new Date()): string | null {
  const seconds = Math.floor((new Date(expiry).getTime() - now.getTime()) / 1000)
  if (seconds <= 0) return null
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  return hours > 0 ? `${hours}h ${minutes}m` : `${Math.max(1, minutes)}m`
}


export function extractorProgress(e: Pick<Extractor, 'install_time' | 'expiry_time'>, now: Date = new Date()): number {
  if (!e.install_time || !e.expiry_time) return 0
  const total = new Date(e.expiry_time).getTime() - new Date(e.install_time).getTime()
  if (total <= 0) return 0
  const done = (now.getTime() - new Date(e.install_time).getTime()) / total
  return Math.max(0, Math.min(100, Math.round(done * 1000) / 10))
}


function clock(seconds: number): string {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':')
}


export function groupFactories(factories: Planet['factories']) {
  const groups = new Map<number, Planet['factories'][number] & { count: number }>()
  for (const f of factories) {
    const g = groups.get(f.schematic_id)
    if (g) g.count += 1
    else groups.set(f.schematic_id, { ...f, count: 1 })
  }
  return [...groups.values()]
}

function planetText(p: Planet): string {
  return [
    p.planet_name,
    p.planet_type,
    ...p.outputs.map((o) => o.name),
    ...p.extractors.map((e) => e.product_name),
    ...p.factories.map((f) => f.schematic_name),
  ]
    .join(' ')
    .toLowerCase()
}

function ExtractorRow({ e }: { e: Extractor }) {
  const left = e.is_active && e.expiry_time ? timeRemaining(e.expiry_time) : null
  const pct = extractorProgress(e)
  return (
    <div className="pi-row">
      <div className="pi-line">
        <span>
          <img src={e.icon_url} alt="" width={16} height={16} loading="lazy" /> {e.product_name}{' '}
          <span className="num small">{one.format(e.per_hour)}/hr</span>
        </span>
        {left ? <span className="text-profit small">{left}</span> : <span className="badge badge-stale">Inactive</span>}
      </div>
      {left ? (
        <div
          className={`bar ${pct >= 90 ? 'pi-high' : 'pi-low'}`}
          role="progressbar"
          aria-label={`${e.product_name} extraction progress`}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={pct}
        >
          <span style={{ width: `${pct}%` }} />
        </div>
      ) : null}
    </div>
  )
}

function StorageRow({ s }: { s: Storage }) {
  const level = s.usage_percent < 50 ? 'pi-low' : s.usage_percent < 80 ? 'pi-mid' : 'pi-high'
  const label = s.kind === 'launchpad' ? 'Launchpad' : 'Storage'
  return (
    <div className="pi-row">
      <div className="pi-line small">
        <span>{label}</span>
        <span className="num">
          {one.format(s.used_volume)} / {formatQty(s.capacity)} m³ ({s.usage_percent}%)
        </span>
      </div>
      <div
        className={`bar ${level}`}
        role="progressbar"
        aria-label={`${label} fill`}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={s.usage_percent}
      >
        <span style={{ width: `${s.usage_percent}%` }} />
      </div>
      {s.items.map((item) => (
        <div key={item.type_id} className="pi-line small muted">
          <span>
            <img src={item.icon_url} alt="" width={16} height={16} loading="lazy" /> {item.name}
          </span>
          <span className="num">{formatQty(item.amount)}</span>
        </div>
      ))}
    </div>
  )
}

function PlanetCard({ p }: { p: Planet }) {
  return (
    <li className={`planet planet-${p.planet_type}`}>
      <div className="pi-line">
        <div>
          <strong>{p.planet_name}</strong>
          <div className="muted small">
            <span className="cap">{p.planet_type}</span> · level {p.upgrade_level}
          </div>
        </div>
        <span className="badge">{p.num_pins} pins</span>
      </div>
      <div className="pi-badges small">
        {p.extractor_count > 0 ? <span className="badge">{p.extractor_count} extractors</span> : null}
        {p.factory_count > 0 ? <span className="badge">{p.factory_count} factories</span> : null}
        {p.storage_count > 0 ? <span className="badge">{p.storage_count} storage</span> : null}
        {p.launchpad_count > 0 ? <span className="badge">{p.launchpad_count} launchpad</span> : null}
      </div>
      {p.alerts.map((a, i) => (
        <div key={i} className="pi-alert small">
          ⚠ {a.message}
        </div>
      ))}
      {p.extractors.map((e, i) => (
        <ExtractorRow key={i} e={e} />
      ))}
      {groupFactories(p.factories).map((f) => (
        <div key={f.schematic_id} className="pi-line small">
          <span>
            ⚙ {f.count > 1 ? `${f.count}× ` : null}
            {f.schematic_name}
          </span>
          <span className="muted">{clock(f.cycle_time)} cycle</span>
        </div>
      ))}
      {p.storages.map((s, i) => (
        <StorageRow key={i} s={s} />
      ))}
      {p.route_count > 0 ? <div className="muted small">{p.route_count} route(s)</div> : null}
    </li>
  )
}

export default function Planetary() {
  const planetary = usePlanetary()
  const refresh = useRefreshPlanetary()
  const [filter, setFilter] = useState('')

  if (planetary.isPending) return <p>Loading planetary data… A cold cache reads every colony from ESI and can take a while.</p>
  if (planetary.isError) {
    const message = planetary.error instanceof ApiError ? planetary.error.message : 'Could not load planetary data.'
    return <p role="alert">{message}</p>
  }

  const d = planetary.data.data
  const query = filter.trim().toLowerCase()
  const maxPerHour = Math.max(1, ...d.outputs.map((o) => o.per_hour))
  const characters = d.characters
    .map((c) => ({ ...c, planets: c.planets.filter((p) => query === '' || planetText(p).includes(query)) }))
    .filter((c) => query === '' || c.planets.length > 0)

  return (
    <>
      <div className="page-head">
        <h1>Planetary interaction</h1>
        <div className="page-actions">
          <button type="button" className="button" disabled={refresh.isPending || planetary.isFetching} onClick={() => refresh.mutate()}>
            {refresh.isPending || planetary.isFetching ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </div>
      <ScopeWarning required={SCOPES} />
      {refresh.isError ? (
        <p role="alert">{refresh.error instanceof ApiError ? refresh.error.message : 'Refresh failed.'}</p>
      ) : null}

      <dl className="stats">
        <div className="panel">
          <dd>{d.summary.characters}</dd>
          <dt className="muted small">Characters</dt>
        </div>
        <div className="panel">
          <dd>{d.summary.planets}</dd>
          <dt className="muted small">Planets</dt>
        </div>
        <div className="panel">
          <dd>{d.summary.extractors}</dd>
          <dt className="muted small">Extractors</dt>
        </div>
        <div className="panel">
          <dd>{d.summary.factories}</dd>
          <dt className="muted small">Factories</dt>
        </div>
      </dl>

      {d.characters.length === 0 ? (
        <div className="panel">
          <p>No planetary interaction data found.</p>
          <p className="muted">
            Make sure your characters have the <code>esi-planets.manage_planets.v1</code> scope and active colonies.
          </p>
        </div>
      ) : (
        <>
          {d.alerts.length > 0 ? (
            <details className="panel">
              <summary>Alerts ({d.alerts.length})</summary>
              <ul className="pi-alerts">
                {d.alerts.map((a, i) => (
                  <li key={i}>
                    <strong>{a.character_name}</strong> — {a.planet_name}: {a.message}
                  </li>
                ))}
              </ul>
            </details>
          ) : null}

          {d.outputs.length > 0 ? (
            <details className="panel">
              <summary>Production outputs</summary>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">Product</th>
                      <th scope="col" className="num">
                        Per hour
                      </th>
                      <th scope="col" className="num">
                        Per day
                      </th>
                      <th scope="col" className="num">
                        Planets
                      </th>
                      <th scope="col">Share of top output</th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.outputs.map((o) => (
                      <tr key={o.type_id}>
                        <td>
                          <span className="asset-name">
                            <img src={o.icon_url} alt="" width={20} height={20} loading="lazy" />
                            {o.name}
                          </span>
                        </td>
                        <td className="num">{one.format(o.per_hour)}</td>
                        <td className="num">{formatQty(Math.round(o.per_hour * 24))}</td>
                        <td className="num">{o.planets}</td>
                        <td className="pi-share">
                          <div
                            className="bar bar-research"
                            role="progressbar"
                            aria-label={`${o.name} share`}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={Math.round((o.per_hour / maxPerHour) * 100)}
                          >
                            <span style={{ width: `${(o.per_hour / maxPerHour) * 100}%` }} />
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          ) : null}

          {d.capacity.length > 0 ? (
            <details className="panel">
              <summary>Factory capacity</summary>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">Character</th>
                      <th scope="col">Planet</th>
                      <th scope="col">Type</th>
                      <th scope="col" className="num">
                        Extractors
                      </th>
                      <th scope="col" className="num">
                        Factories
                      </th>
                      <th scope="col">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.capacity.map((c, i) => (
                      <tr key={i}>
                        <td>{c.character_name}</td>
                        <td>{c.planet_name}</td>
                        <td className="cap">{c.planet_type}</td>
                        <td className="num">{c.extractor_count}</td>
                        <td className="num">{c.factory_count}</td>
                        <td>
                          <span className={c.status === 'balanced' ? 'badge badge-fresh' : 'badge badge-stale'}>
                            {CAPACITY_LABELS[c.status]}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          ) : null}

          <section aria-labelledby="pi-breakdown">
            <div className="page-head">
              <h2 id="pi-breakdown" className="section-title">
                Character breakdown
              </h2>
              <div className="search">
                <label className="visually-hidden" htmlFor="pi-filter">
                  Filter by resource, planet or type
                </label>
                <input
                  id="pi-filter"
                  type="search"
                  placeholder="Filter by resource type…"
                  value={filter}
                  onChange={(e) => setFilter(e.target.value)}
                />
              </div>
            </div>
            {characters.length === 0 ? <p className="muted">No planets match your search.</p> : null}
            {characters.map((c) => (
              <article key={c.character_id} className="panel char">
                <header className="char-head">
                  {c.portrait_url ? <img src={c.portrait_url} alt="" width={40} height={40} loading="lazy" /> : null}
                  <div>
                    <h3>{c.character_name}</h3>
                    <div className="muted small">{c.planets.length} planet(s)</div>
                  </div>
                </header>
                {c.planets.length === 0 ? (
                  <p className="muted">No planetary colonies found for this character.</p>
                ) : (
                  <ul className="planets">
                    {c.planets.map((p) => (
                      <PlanetCard key={p.planet_id} p={p} />
                    ))}
                  </ul>
                )}
              </article>
            ))}
          </section>
        </>
      )}
    </>
  )
}
