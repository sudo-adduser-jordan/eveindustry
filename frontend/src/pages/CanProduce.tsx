import { useState } from 'react'
import { useCanProduce, useRefreshCanProduce } from '../api/queries'
import type { components } from '../api/schema'
import { formatQty } from '../components/format'
import { ScopeWarning } from '../components/ScopeWarning'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

type Source = components['schemas']['CanProduceSourceResource']
type Item = components['schemas']['CanProduceItemResource']

const SCOPES = [
  { scope: 'esi-characters.read_blueprints.v1', description: 'Read Character Blueprints' },
  { scope: 'esi-assets.read_assets.v1', description: 'Read Character Assets' },
  { scope: 'esi-characters.read_corporation_roles.v1', description: 'Read Corporation Roles' },
  { scope: 'esi-corporations.read_blueprints.v1', description: 'Read Corporation Blueprints' },
  { scope: 'esi-assets.read_corporation_assets.v1', description: 'Read Corporation Assets' },
]

const icon = (typeId: number) => typeIconUrl(typeId)


export function filterSources(sources: Source[], query: string): Source[] {
  const q = query.trim().toLowerCase()
  if (q === '') return sources
  return sources
    .map((s) => ({ ...s, buildable: s.buildable.filter((i) => i.product_name.toLowerCase().includes(q)) }))
    .filter((s) => s.buildable.length > 0)
}

function ItemRow({ item }: { item: Item }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <tr>
        <td>
          <span className="asset-name">
            <img src={icon(item.product_type_id)} alt="" width={28} height={28} loading="lazy" onError={iconFallback} />
            <span>
              {item.product_name}
              {item.max_runs > 0 ? (
                <span className="badge" title={`Blueprint copy limited to ${item.max_runs} runs`}>
                  BPC
                </span>
              ) : null}
            </span>
          </span>
        </td>
        <td className="num">ME {item.me}</td>
        <td className="num">{formatQty(item.buildable_runs)}</td>
        <td className="num">{formatQty(item.total_output)}</td>
        <td className="num">
          <button type="button" className="toggle" aria-expanded={open} onClick={() => setOpen(!open)}>
            {open ? '▾' : '▸'} {item.materials.length} types
          </button>
        </td>
      </tr>
      {open
        ? item.materials.map((m) => (
            <tr key={m.type_id} className="child-row">
              <td colSpan={3}>
                <span className="asset-name">
                  <img src={icon(m.type_id)} alt="" width={20} height={20} loading="lazy" onError={iconFallback} />
                  <span>{m.name}</span>
                </span>
              </td>
              <td className="num">
                <span className={m.available >= m.required_per_run ? 'text-profit' : 'text-warn'}>{formatQty(m.available)}</span>
                {' / '}
                {formatQty(m.required_per_run)} per run
              </td>
              <td className="num muted">{formatQty(m.runs_possible)} runs</td>
            </tr>
          ))
        : null}
    </>
  )
}

function SourcePanel({ source }: { source: Source }) {
  const corp = source.type === 'corporation'
  return (
    <section className="panel">
      <header className="char-head">
        {(corp ? source.corporation_logo : source.portrait_url) ? (
          <img src={(corp ? source.corporation_logo : source.portrait_url) ?? ''} alt="" width={48} height={48} loading="lazy" />
        ) : null}
        <div>
          <strong>{corp ? (source.corporation_name ?? 'Corporation') : source.character_name}</strong>
          <span className="badge">{corp ? 'Corporation' : 'Character'}</span>
          <div className="muted small">
            {corp ? `Via ${source.character_name}` : (source.corporation_name ?? '')} · {source.blueprint_count} blueprints ·{' '}
            {source.material_count} material types
          </div>
        </div>
      </header>
      {source.available_materials.length > 0 ? (
        <details>
          <summary>Available materials ({source.available_materials.length} types)</summary>
          <ul className="mat-grid small">
            {source.available_materials.map((m) => (
              <li key={m.type_id}>
                <span>{m.name}</span>
                <span className="text-profit num">{formatQty(m.quantity)}</span>
              </li>
            ))}
          </ul>
        </details>
      ) : null}
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th className="num">ME</th>
              <th className="num">Runs</th>
              <th className="num">Output</th>
              <th className="num">Materials</th>
            </tr>
          </thead>
          <tbody>
            {source.buildable.map((item) => (
              <ItemRow key={`${item.blueprint_type_id}-${item.me}`} item={item} />
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

export default function CanProduce() {
  const data = useCanProduce()
  const refresh = useRefreshCanProduce()
  const [query, setQuery] = useState('')

  if (data.isPending) return <p>Working out what you can produce…</p>
  if (data.isError) return <p role="alert">Could not load what you can produce.</p>

  const sources = data.data.data.filter((s) => s.buildable.length > 0)
  const shown = filterSources(sources, query)
  return (
    <>
      <div className="page-head">
        <h1>Can produce</h1>
        <div className="page-actions">
          <input
            type="search"
            className="filter-input"
            placeholder="Filter products…"
            aria-label="Filter products"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <button type="button" className="button" disabled={refresh.isPending} onClick={() => refresh.mutate()}>
            {refresh.isPending ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </div>
      <p>What your blueprints could build right now from the materials in your hangars, per character and corporation.</p>
      {refresh.isError ? (
        <p role="alert" className="panel warn">
          Could not refresh. Try again in a moment.
        </p>
      ) : null}
      <ScopeWarning required={SCOPES} />
      {sources.length === 0 ? (
        <div className="panel">
          <h2>No buildable items found</h2>
          <ul>
            <li>Your characters may not own any blueprints.</li>
            <li>The required materials may not be in your hangars.</li>
            <li>Blueprint data from the SDE may not be imported yet.</li>
            <li>Sign in with EVE SSO again to grant the asset and blueprint scopes.</li>
          </ul>
        </div>
      ) : shown.length === 0 ? (
        <p className="panel muted">Nothing matches “{query}”.</p>
      ) : (
        shown.map((s) => <SourcePanel key={`${s.type}-${s.character_id}`} source={s} />)
      )}
    </>
  )
}
