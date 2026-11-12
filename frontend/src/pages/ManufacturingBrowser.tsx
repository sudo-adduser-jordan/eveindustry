import { useEffect, useRef, useState } from 'react'
import { useManufacturingBrowse } from '../api/calculators'
import { useMe } from '../api/queries'
import { FreshnessBadge } from '../components/FreshnessBadge'
import { Pager } from '../components/Pager'
import { MarketGroupTree } from '../components/calculators/MarketGroupTree'
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

export default function ManufacturingBrowser() {
  const account = useMe()
  const isDemo = account.data?.data.demo === true
  const [group, setGroup] = useState<{ id: number; name: string } | null>(null)
  const demoSeeded = useRef(false)
  useEffect(() => {
    if (isDemo && !demoSeeded.current && group === null) {
      demoSeeded.current = true
      setGroup({ id: 9901, name: 'Demo Starships' })
    }
  }, [isDemo, group])
  const [page, setPage] = useState(1)
  const [runs, setRuns] = useState('1')
  const [me, setMe] = useState('0')
  const [rig, setRig] = useState<'none' | 't1' | 't2'>('none')
  const [security, setSecurity] = useState<'highsec' | 'lowsec' | 'nullsec'>('highsec')
  const [system, setSystem] = useState<SystemHit | null>(null)
  const [tax, setTax] = useState('0')
  const [sci, setSci] = useState('0')
  const [region, setRegion] = useState<string>(DEFAULT_REGION)

  const browse = useManufacturingBrowse(
    group
      ? {
          market_group_id: group.id,
          me_level: intIn(me, 0, 10, 0),
          price_region_id: Number(region),
          runs: intIn(runs, 1, 100000, 1),
          rig_tier: rig,
          security_space: security,
          facility_tax: num(tax, 0),
          system_cost_index: num(sci, 0),
          page,
          per_page: 100,
        }
      : null,
  )

  return (
    <>
      <h1>Manufacturing browser</h1>
      <p>Browse items by market group to see profitability. Pick a group in the item browser to load its results.</p>
      <form className="panel calc-form" aria-label="Browser settings" onSubmit={(e) => e.preventDefault()}>
        <div className="calc-stats">
          <NumberField label="Number of runs" value={runs} onChange={(v) => { setRuns(v); setPage(1) }} min={1} max={100000} step={1} />
          <NumberField label="Material efficiency (ME)" value={me} onChange={(v) => { setMe(v); setPage(1) }} min={0} max={10} step={1} />
          <SelectField label="Rig tier" value={rig} onChange={setRig} options={RIGS} />
          <SelectField label="Security space" value={security} onChange={setSecurity} options={SECURITY} />
          <SystemPicker system={system} onChange={setSystem} activity="manufacturing" onIndex={(p) => setSci(String(p))} />
          <NumberField label="Facility tax (%)" value={tax} onChange={setTax} min={0} max={50} step={0.1} />
          <NumberField label="System cost index (%)" value={sci} onChange={setSci} min={0} max={100} step={0.01} />
          <SelectField label="Market prices" value={region} onChange={(v) => { setRegion(v); setPage(1) }} options={PRICE_REGIONS} />
        </div>
      </form>
      <div className="calc-layout calc-layout-tree">
        <MarketGroupTree
          selected={group?.id ?? null}
          onSelect={(g) => {
            setGroup(g)
            setPage(1)
          }}
          disabled={browse.isFetching && !browse.data}
        />
        <section className="panel" aria-labelledby="mb-heading" aria-busy={browse.isFetching}>
          <div className="calc-panel-head">
            <h2 id="mb-heading">Manufacturing profitability{group ? `: ${group.name}` : ''}</h2>
            {browse.data ? (
              <FreshnessBadge asOf={browse.data.meta.freshness.prices_as_of} stale={browse.data.meta.freshness.stale} />
            ) : null}
          </div>
          {!group ? <p className="muted">Select a market group from the item browser to see manufacturing profitability.</p> : null}
          {browse.isPending && group ? <p className="muted">Loading…</p> : null}
          {browse.isError ? (
            <p role="alert" className="text-loss">
              {errorText(browse.error)}
            </p>
          ) : null}
          {browse.data ? (
            <>
              {browse.data.meta.effective_structure_me_bonus > 0 ? (
                <p className="calc-note">Rig ME bonus applied: {browse.data.meta.effective_structure_me_bonus}%</p>
              ) : null}
              <ProfitTable
                rows={browse.data.data}
                itemLabel="items"
                extraSearchText={(r) => r.blueprint_name}
              />
              <Pager pagination={browse.data.meta.pagination} onPage={setPage} />
            </>
          ) : null}
        </section>
      </div>
    </>
  )
}
