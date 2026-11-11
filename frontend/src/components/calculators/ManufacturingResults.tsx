import { Fragment, useState } from 'react'
import type { ManufacturingResult } from '../../api/calculators'
import { formatQty } from '../format'
import { formatDuration, formatVolume, csvCell, isk, profitClass, signedIsk } from './format'
import { CopyButton, downloadText, Stat, TypeIcon } from './kit'

type Material = Omit<ManufacturingResult['materials'][number], 'price_source'>

const money = (n: number) => isk(n)


export function MaterialsPanel({ result, fileName }: { result: ManufacturingResult; fileName: string }) {
  return (
    <MaterialsTable
      materials={result.materials}
      buy={result.scenarios.full_buy.material_cost}
      build={result.scenarios.full_build.material_cost}
      fileName={fileName}
    />
  )
}


export function MaterialsTable({
  materials,
  buy,
  build,
  fileName,
  heading = 'Materials',
}: {
  materials: Material[]
  buy: number
  build: number
  fileName: string
  heading?: string
}) {
  const [expanded, setExpanded] = useState<ReadonlySet<number>>(new Set())
  const buildable = materials.filter((m) => m.component?.is_buildable)
  const allOpen = buildable.length > 0 && buildable.every((m) => expanded.has(m.type_id))
  const volume = materials.reduce((sum, m) => sum + m.volume * m.adjusted_quantity, 0)

  function toggle(typeId: number) {
    const next = new Set(expanded)
    if (!next.delete(typeId)) next.add(typeId)
    setExpanded(next)
  }

  return (
    <section className="panel" aria-labelledby="materials-heading">
      <div className="calc-panel-head">
        <h2 id="materials-heading">{heading}</h2>
        <div className="calc-actions">
          {buildable.length > 0 ? (
            <button
              type="button"
              className="button"
              onClick={() => setExpanded(allOpen ? new Set() : new Set(buildable.map((m) => m.type_id)))}
            >
              {allOpen ? 'Collapse all' : 'Expand all'}
            </button>
          ) : null}
          <CopyButton label="Multibuy" onCopy={() => materials.map((m) => `${m.name} x${m.adjusted_quantity}`).join('\n')} />
          <button
            type="button"
            className="button"
            onClick={() => {
              const rows = materials.map(
                (m) => `${csvCell(m.name)},${m.type_id},${m.adjusted_quantity},${m.total_cost},${m.component?.build_cost ?? ''}`,
              )
              downloadText(`${fileName}_materials.csv`, ['Name,Type ID,Adjusted Quantity,Buy Cost,Build Cost', ...rows].join('\n'))
            }}
          >
            CSV
          </button>
        </div>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th scope="col">Material</th>
              <th scope="col" className="num">
                Quantity
              </th>
              <th scope="col" className="num">
                Volume
              </th>
              <th scope="col" className="num">
                Buy cost
              </th>
              <th scope="col" className="num">
                Build cost
              </th>
            </tr>
          </thead>
          <tbody>
            {materials.map((m) => (
              <MaterialRow key={m.type_id} material={m} open={expanded.has(m.type_id)} onToggle={() => toggle(m.type_id)} />
            ))}
          </tbody>
        </table>
      </div>
      <dl className="calc-stats">
        <Stat label="Total volume">{formatVolume(volume)}</Stat>
        <Stat label="Total (buy)">{money(buy)}</Stat>
        <Stat label="Total (build, ME 10 components)">{money(build)}</Stat>
      </dl>
    </section>
  )
}

function MaterialRow({ material: m, open, onToggle }: { material: Material; open: boolean; onToggle: () => void }) {
  const component = m.component?.is_buildable ? m.component : null
  return (
    <Fragment>
      <tr>
        <td>
          <span className="asset-name">
            {component ? (
              <button
                type="button"
                className="toggle"
                aria-expanded={open}
                aria-label={`${open ? 'Hide' : 'Show'} the materials of ${m.name}`}
                onClick={onToggle}
              >
                {open ? '▾' : '▸'}
              </button>
            ) : null}
            <TypeIcon typeId={m.type_id} />
            {m.name}
            {component ? <span className="badge badge-small">Buildable</span> : null}
          </span>
        </td>
        <td className="num">{formatQty(m.adjusted_quantity)}</td>
        <td className="num muted">{formatVolume(m.volume * m.adjusted_quantity)}</td>
        <td className="num">{money(m.total_cost)}</td>
        <td className="num">{component ? money(component.build_cost) : <span className="muted">-</span>}</td>
      </tr>
      {component && open ? (
        <tr className="calc-sub">
          <td colSpan={5}>
            <table>
              <thead>
                <tr>
                  <th scope="col">Sub-material</th>
                  <th scope="col" className="num">
                    Qty
                  </th>
                  <th scope="col" className="num">
                    Volume
                  </th>
                  <th scope="col" className="num">
                    Unit price
                  </th>
                  <th scope="col" className="num">
                    Total
                  </th>
                </tr>
              </thead>
              <tbody>
                {component.materials.map((s) => (
                  <tr key={s.type_id}>
                    <td>
                      <span className="asset-name">
                        <TypeIcon typeId={s.type_id} size={20} />
                        {s.name}
                      </span>
                    </td>
                    <td className="num">{formatQty(s.adjusted_quantity)}</td>
                    <td className="num muted">{formatVolume(s.volume * s.adjusted_quantity)}</td>
                    <td className="num">{money(s.unit_price)}</td>
                    <td className="num">{money(s.total_cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="calc-note">
              {typeof (component as { job_cost?: number }).job_cost === 'number' ? (
                <>
                  Material cost: <strong>{money((component as { material_cost?: number }).material_cost ?? 0)}</strong> · Job cost:{' '}
                  <strong>{money((component as { job_cost?: number }).job_cost ?? 0)}</strong> ·{' '}
                </>
              ) : null}
              Total build: <strong>{money(component.build_cost)}</strong> ({component.runs_needed} runs)
            </p>
          </td>
        </tr>
      ) : null}
    </Fragment>
  )
}


export function JobSummary({ result }: { result: ManufacturingResult }) {
  const fee = result.build_fee
  const buy = result.scenarios.full_buy
  const build = result.scenarios.full_build
  const hasBuyFee = fee.has_data && fee.total > 0
  const hasBuildFee = fee.has_data && fee.total_full_build > 0

  return (
    <>
      <section className="panel" aria-labelledby="time-heading">
        <h2 id="time-heading">Production time</h2>
        <dl className="calc-stats">
          <Stat label="Per run">{formatDuration(result.time.time_per_run_seconds)}</Stat>
          <Stat label="Total">{formatDuration(result.time.total_time_seconds)}</Stat>
          <Stat label="Base time">{formatDuration(result.time.base_time_seconds)}</Stat>
        </dl>
      </section>

      <section className="panel" aria-labelledby="profit-heading">
        <h2 id="profit-heading">Cost &amp; profit</h2>
        <dl className="calc-stats">
          <Stat label={`Total output (${formatQty(result.product.total_output)} units)`} className="text-profit">
            {money(result.product.total_revenue)}
          </Stat>
          <Stat label="Total cost (materials + fees)" className="text-loss">
            {money(buy.total_cost)}
          </Stat>
          <Stat label="Profit" className={profitClass(buy.profit_after_fees)}>
            {signedIsk(buy.profit_after_fees)}
          </Stat>
          <Stat label="Margin / per unit" className={profitClass(buy.profit_after_fees)}>
            {buy.margin_after_fees}% · {signedIsk(buy.per_unit_after_fees)}/unit
          </Stat>
          <Stat label="Sell price (unit)">{money(result.product.sell_price)}</Stat>
        </dl>
        {fee.has_data ? (
          <>
            <h3 className="calc-note">Build fee breakdown</h3>
            <dl className="calc-stats">
              <Stat label={`Job cost (SCI ${(fee.sci * 100).toFixed(2)}%)`}>{money(fee.job_cost)}</Stat>
              <Stat label={`Facility tax${fee.tax_rate > 0 ? ` (${fee.tax_rate}%)` : ''}`}>{money(fee.facility_tax)}</Stat>
              <Stat label="SCC fee (4%)" className="text-warn">
                {money(result.scc_fee)}
              </Stat>
              <Stat label="Total build fee (incl. SCC)" className="text-warn">
                {money(buy.total_fees)}
              </Stat>
            </dl>
          </>
        ) : null}
      </section>

      <section className="panel" aria-labelledby="bvb-heading">
        <h2 id="bvb-heading">Build vs buy</h2>
        <dl className="calc-stats">
          <Stat label="Full buy (all from market)">{money(buy.material_cost)}</Stat>
          <Stat label="Full build (ME 10 components)">{money(build.material_cost)}</Stat>
        </dl>
        <p>
          <span className={result.build_cheaper ? 'badge badge-fresh' : 'badge badge-stale'}>
            {result.build_cheaper ? '✓ Building components is cheaper' : '✓ Buying from market is cheaper'}
          </span>
        </p>
      </section>

      <div className="calc-two">
        <ScenarioTable
          title="Summary (full buy)"
          materialLabel="Material cost (buy)"
          scenario={buy}
          fees={hasBuyFee ? [['Build fee', fee.total]] : []}
          sccFee={result.scc_fee}
          revenue={result.product.total_revenue}
          afterFees={hasBuyFee}
        />
        <ScenarioTable
          title="Summary (full build)"
          materialLabel="Material cost (build)"
          scenario={build}
          fees={
            hasBuildFee
              ? [
                  ['Main job fee', fee.total],
                  ...(fee.components_fee > 0 ? ([['Component fees', fee.components_fee]] as Array<[string, number]>) : []),
                ]
              : []
          }
          sccFee={result.scc_fee}
          revenue={result.product.total_revenue}
          afterFees={hasBuildFee}
        />
      </div>
    </>
  )
}

function ScenarioTable({
  title,
  materialLabel,
  scenario,
  fees,
  sccFee,
  revenue,
  afterFees,
}: {
  title: string
  materialLabel: string
  scenario: ManufacturingResult['scenarios']['full_buy']
  fees: Array<[string, number]>
  sccFee: number
  revenue: number
  afterFees: boolean
}) {
  
  const profit = afterFees ? scenario.profit_after_fees : scenario.profit
  const margin = afterFees ? scenario.margin_after_fees : scenario.margin
  const perUnit = afterFees ? scenario.per_unit_after_fees : scenario.per_unit
  return (
    <section className="panel" aria-label={title}>
      <h2>{title}</h2>
      <table>
        <tbody>
          <tr>
            <th scope="row">{materialLabel}</th>
            <td className="num">{money(scenario.material_cost)}</td>
          </tr>
          {fees.map(([label, value]) => (
            <tr key={label}>
              <th scope="row">{label}</th>
              <td className="num text-warn">{money(value)}</td>
            </tr>
          ))}
          <tr>
            <th scope="row">SCC fee (4%)</th>
            <td className="num text-warn">{money(sccFee)}</td>
          </tr>
          <tr>
            <th scope="row">Total cost</th>
            <td className="num text-loss">{money(scenario.total_cost)}</td>
          </tr>
          <tr>
            <th scope="row">Output value</th>
            <td className="num text-profit">{money(revenue)}</td>
          </tr>
          <tr>
            <th scope="row">Net profit</th>
            <td className={`num ${profitClass(profit)}`}>{money(profit)}</td>
          </tr>
          <tr>
            <th scope="row">Margin</th>
            <td className={`num ${profitClass(profit)}`}>{margin}%</td>
          </tr>
          <tr>
            <th scope="row">Per unit</th>
            <td className="num">{money(perUnit)}</td>
          </tr>
        </tbody>
      </table>
    </section>
  )
}
