import { useState, type FormEvent, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useBlueprintResearch, useReactionFormulaResearch } from '../api/queries'
import { formatQty } from '../components/format'
import { Pager } from '../components/Pager'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

const icon = (typeId: number) => typeIconUrl(typeId)
const PER_PAGE = 100

interface Missing {
  character_id: number
  name: string
}

function MissingScope({ characters, what }: { characters: Missing[]; what: string }) {
  if (characters.length === 0) return null
  return (
    <div className="panel warn" role="alert">
      <strong>Missing blueprint scope.</strong> These characters lack <code>esi-characters.read_blueprints.v1</code>, so their {what} cannot
      be read:
      <ul>
        {characters.map((c) => (
          <li key={c.character_id}>{c.name}</li>
        ))}
      </ul>
      <a href="/auth/eve/scopes">Re-authenticate with EVE SSO to grant it</a>
    </div>
  )
}

function Owned({ owned, owners }: { owned: boolean; owners: string[] }) {
  return owned ? (
    <>
      <span className="badge badge-fresh">Yes</span>
      <div className="muted small">{owners.join(', ')}</div>
    </>
  ) : (
    <span className="badge">No</span>
  )
}

function SortHead({
  field,
  label,
  sort,
  dir,
  onSort,
}: {
  field: string
  label: string
  sort: string
  dir: string
  onSort: (field: string) => void
}) {
  const active = sort === field
  return (
    <th aria-sort={active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
      <button type="button" className="sort" onClick={() => onSort(field)}>
        {label} {active ? (dir === 'asc' ? '▲' : '▼') : ''}
      </button>
    </th>
  )
}


function Filters({
  placeholder,
  selectName,
  selectLabel,
  options,
  params,
  onApply,
}: {
  placeholder: string
  selectName: string
  selectLabel: string
  options: Array<[string, string]>
  params: URLSearchParams
  onApply: (next: Record<string, string>) => void
}) {
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [group, setGroup] = useState(params.get(selectName) ?? '')
  function submit(e: FormEvent) {
    e.preventDefault()
    onApply({ search, [selectName]: group })
  }
  return (
    <form className="panel filter-form" onSubmit={submit}>
      <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={placeholder} aria-label="Search by name" />
      <select value={group} onChange={(e) => setGroup(e.target.value)} aria-label={selectLabel}>
        <option value="">{selectLabel}</option>
        {options.map(([id, name]) => (
          <option key={id} value={id}>
            {name}
          </option>
        ))}
      </select>
      <button type="submit" className="button">
        Search
      </button>
      {search !== '' || group !== '' ? (
        <button
          type="button"
          className="button"
          onClick={() => {
            setSearch('')
            setGroup('')
            onApply({ search: '', [selectName]: '' })
          }}
        >
          Clear
        </button>
      ) : null}
    </form>
  )
}


function useResearchParams(selectName: string) {
  const [params, setParams] = useSearchParams()
  const sort = params.get('sort') ?? 'name'
  const dir = params.get('dir') ?? 'asc'
  const page = Number(params.get('page') ?? '1') || 1
  const group = params.get(selectName) ?? ''
  const search = params.get('search') ?? ''
  const update = (changes: Record<string, string>, keepPage = false) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(changes)) (v === '' ? next.delete(k) : next.set(k, v))
    if (!keepPage) next.delete('page')
    setParams(next)
  }
  const onSort = (field: string) => update({ sort: field, dir: sort === field && dir === 'asc' ? 'desc' : 'asc' })
  const exportQuery = new URLSearchParams()
  if (search) exportQuery.set('search', search)
  if (group) exportQuery.set(selectName, group)
  exportQuery.set('sort', sort)
  exportQuery.set('dir', dir)
  return { params, sort, dir, page, group, search, update, onSort, exportQuery: exportQuery.toString() }
}

function ResultsCard({ title, count, exportHref, children }: { title: string; count: number; exportHref: string; children: ReactNode }) {
  return (
    <section className="panel">
      <div className="page-head">
        <h2>
          {title} ({formatQty(count)})
        </h2>
        {count > 0 ? <a href={exportHref}>Export CSV</a> : null}
      </div>
      {children}
    </section>
  )
}

export function BlueprintResearch() {
  const r = useResearchParams('market_group')
  const query = {
    search: r.search || undefined,
    market_group: r.group ? Number(r.group) : undefined,
    sort: r.sort as 'name' | 'owned' | 'market_group',
    dir: r.dir as 'asc' | 'desc',
    page: r.page,
    per_page: PER_PAGE,
  }
  const data = useBlueprintResearch(query)

  return (
    <>
      <h1>Blueprint research</h1>
      <p>Every tech I blueprint, and the best ME/TE of the copy you own.</p>
      {data.isPending ? <p>Loading blueprints…</p> : null}
      {data.isError ? (
        <p role="alert" className="panel warn">
          Could not load blueprints.
        </p>
      ) : null}
      {data.data ? (
        <>
          <MissingScope characters={data.data.meta.characters_without_scope} what="blueprints" />
          <Filters
            placeholder="Search blueprints by name…"
            selectName="market_group"
            selectLabel="All market groups"
            options={data.data.meta.market_groups.map((g) => [String(g.market_group_id), g.name])}
            params={r.params}
            onApply={(next) => r.update(next)}
          />
          <ResultsCard
            title="Blueprints"
            count={data.data.meta.pagination.total}
            exportHref={`/industry/blueprint-research/export?${r.exportQuery}`}
          >
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th className="toggle-cell" />
                    <SortHead field="name" label="Blueprint" sort={r.sort} dir={r.dir} onSort={r.onSort} />
                    <SortHead field="owned" label="Owned" sort={r.sort} dir={r.dir} onSort={r.onSort} />
                    {data.data.meta.has_blueprint_data ? (
                      <>
                        <th className="num">ME</th>
                        <th className="num">TE</th>
                      </>
                    ) : null}
                    <SortHead field="market_group" label="Market group" sort={r.sort} dir={r.dir} onSort={r.onSort} />
                  </tr>
                </thead>
                <tbody>
                  {data.data.data.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="muted">
                        {r.search || r.group ? 'No blueprints match your search.' : 'No T1 blueprints found. Ensure the SDE data has been imported.'}
                      </td>
                    </tr>
                  ) : (
                    data.data.data.map((b) => (
                      <tr key={b.type_id}>
                        <td>
                          <img src={icon(b.type_id)} alt="" width={32} height={32} loading="lazy" onError={iconFallback} />
                        </td>
                        <td>{b.name}</td>
                        <td>
                          <Owned owned={b.owned} owners={b.owner_names} />
                        </td>
                        {data.data.meta.has_blueprint_data ? (
                          <>
                            <td className="num">{b.owned ? b.me : <span className="muted">-</span>}</td>
                            <td className="num">{b.owned ? b.te : <span className="muted">-</span>}</td>
                          </>
                        ) : null}
                        <td className="small">{b.market_group_name ?? 'Unknown'}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
            <Pager pagination={data.data.meta.pagination} onPage={(p) => r.update({ page: String(p) }, true)} />
          </ResultsCard>
        </>
      ) : null}
    </>
  )
}

export function ReactionFormulaResearch() {
  const r = useResearchParams('category')
  const data = useReactionFormulaResearch({
    search: r.search || undefined,
    category: r.group ? Number(r.group) : undefined,
    sort: r.sort as 'name' | 'owned' | 'category',
    dir: r.dir as 'asc' | 'desc',
  })

  return (
    <>
      <h1>Reaction formula research</h1>
      <p>Every reaction formula, how many you own, and who holds them.</p>
      {data.isPending ? <p>Loading formulas…</p> : null}
      {data.isError ? (
        <p role="alert" className="panel warn">
          Could not load reaction formulas.
        </p>
      ) : null}
      {data.data ? (
        <>
          <MissingScope characters={data.data.meta.characters_without_scope} what="reaction formulas" />
          <Filters
            placeholder="Search reaction formulas by name…"
            selectName="category"
            selectLabel="All categories"
            options={data.data.meta.categories.map((c) => [String(c.group_id), c.name])}
            params={r.params}
            onApply={(next) => r.update(next)}
          />
          <ResultsCard
            title="Reaction formulas"
            count={data.data.data.length}
            exportHref={`/industry/reaction-formula-research/export?${r.exportQuery}`}
          >
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th className="toggle-cell" />
                    <SortHead field="name" label="Formula" sort={r.sort} dir={r.dir} onSort={r.onSort} />
                    <SortHead field="owned" label="Owned" sort={r.sort} dir={r.dir} onSort={r.onSort} />
                    {data.data.meta.has_blueprint_data ? <th className="num">Qty</th> : null}
                    <SortHead field="category" label="Category" sort={r.sort} dir={r.dir} onSort={r.onSort} />
                  </tr>
                </thead>
                <tbody>
                  {data.data.data.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="muted">
                        {r.search || r.group
                          ? 'No reaction formulas match your search.'
                          : 'No reaction formulas found. Ensure the SDE data has been imported.'}
                      </td>
                    </tr>
                  ) : (
                    data.data.data.map((f) => (
                      <tr key={f.type_id}>
                        <td>
                          <img src={icon(f.type_id)} alt="" width={32} height={32} loading="lazy" onError={iconFallback} />
                        </td>
                        <td>{f.name}</td>
                        <td>
                          <Owned owned={f.owned} owners={f.owner_names} />
                        </td>
                        {data.data.meta.has_blueprint_data ? (
                          <td className="num">{f.owned ? formatQty(f.quantity) : <span className="muted">-</span>}</td>
                        ) : null}
                        <td className="small">{f.category}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </ResultsCard>
        </>
      ) : null}
    </>
  )
}
