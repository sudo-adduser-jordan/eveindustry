import { useState } from 'react'
import { useMostProfitable } from '../api/queries'
import { FreshnessBadge } from '../components/FreshnessBadge'
import { Pager } from '../components/Pager'
import { ProfitTable } from '../components/calculators/ProfitTable'
import { DEFAULT_REGION, NumberField, PRICE_REGIONS, SelectField, errorText, intIn, num } from '../components/calculators/kit'
import { SystemPicker, type SystemHit } from '../components/calculators/SystemPicker'

const RIGS = [
  ['none', 'No rig'],
  ['t1', 'T1 rig'],
  ['t2', 'T2 rig'],
] as const
const SECURITY = [
  ['highsec', 'Highsec'],
  ['lowsec', 'Lowsec'],
  ['nullsec', 'Nullsec / WH'],
] as const

export default function MostProfitable() {
  const [page, setPage] = useState(1)
  const [runs, setRuns] = useState('1')
  const [me, setMe] = useState('10')
  const [rig, setRig] = useState<'none' | 't1' | 't2'>('none')
  const [security, setSecurity] = useState<'highsec' | 'lowsec' | 'nullsec'>('highsec')
  const [system, setSystem] = useState<SystemHit | null>(null)
  const [tax, setTax] = useState('0')
  const [sci, setSci] = useState('0')
  const [region, setRegion] = useState<string>(DEFAULT_REGION)
  const [search, setSearch] = useState('')
  const [onlyProfitable, setOnlyProfitable] = useState(true)

  const query = {
    me_level: intIn(me, 0, 10, 10),
    price_region_id: Number(region),
    runs: intIn(runs, 1, 100000, 1),
    rig_tier: rig,
    security_space: security,
    facility_tax: num(tax, 0),
    system_cost_index: num(sci, 0),
    q: search.trim() === '' ? undefined : search.trim(),
    only_profitable: onlyProfitable,
    page,
    per_page: 100,
  }
  const results = useMostProfitable(query)

  function resetPage() {
    setPage(1)
  }

  return (
    <>
      <h1>Most profitable</h1>
      <p>Top items to manufacture across all market groups, ranked by profit from precomputed costs.</p>
      <form className="panel calc-form" aria-label="Profitability settings" onSubmit={(e) => e.preventDefault()}>
        <div className="calc-stats">
          <NumberField label="Number of runs" value={runs} onChange={(v) => { setRuns(v); resetPage() }} min={1} max={100000} step={1} />
          <NumberField label="Material efficiency (ME)" value={me} onChange={(v) => { setMe(v); resetPage() }} min={0} max={10} step={1} />
          <SelectField label="Rig tier" value={rig} onChange={setRig} options={RIGS} />
          <SelectField label="Security space" value={security} onChange={setSecurity} options={SECURITY} />
          <SystemPicker system={system} onChange={setSystem} activity="manufacturing" onIndex={(p) => setSci(String(p))} />
          <NumberField label="Facility tax (%)" value={tax} onChange={setTax} min={0} max={50} step={0.1} />
          <NumberField label="System cost index (%)" value={sci} onChange={setSci} min={0} max={100} step={0.01} />
          <SelectField label="Market prices" value={region} onChange={(v) => { setRegion(v); resetPage() }} options={PRICE_REGIONS} />
        </div>
        <div className="calc-stats">
          <div className="scan-field">
            <label htmlFor="most-profitable-search">Search items</label>
            <input
              id="most-profitable-search"
              type="search"
              placeholder="Filter by item name…"
              value={search}
              onChange={(e) => { setSearch(e.target.value); resetPage() }}
            />
          </div>
          <div className="scan-field">
            <label htmlFor="most-profitable-only">
              <input
                id="most-profitable-only"
                type="checkbox"
                checked={onlyProfitable}
                onChange={(e) => { setOnlyProfitable(e.target.checked); resetPage() }}
              />
              {' '}Only profitable
            </label>
          </div>
        </div>
      </form>
      <section className="panel" aria-label="Most profitable items" aria-busy={results.isFetching}>
        <div className="calc-panel-head">
          <h2>Most profitable items</h2>
          {results.data ? (
            <FreshnessBadge asOf={results.data.meta.freshness.prices_as_of} stale={results.data.meta.freshness.stale} />
          ) : null}
        </div>
        {results.isPending ? <p className="muted">Loading…</p> : null}
        {results.isError ? (
          <p role="alert" className="text-loss">
            {errorText(results.error)}
          </p>
        ) : null}
        {results.data ? (
          <>
            {results.data.meta.effective_structure_me_bonus > 0 ? (
              <p className="calc-note">Rig ME bonus applied: {results.data.meta.effective_structure_me_bonus}%</p>
            ) : null}
            <ProfitTable
              rows={results.data.data}
              itemLabel="items"
              extraSearchText={(r) => ('blueprint_name' in r ? String((r as { blueprint_name: string }).blueprint_name) : '')}
            />
            <Pager pagination={results.data.meta.pagination} onPage={setPage} />
          </>
        ) : null}
      </section>
    </>
  )
}
