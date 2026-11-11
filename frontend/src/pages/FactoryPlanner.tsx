import { Fragment, useEffect, useState } from 'react'
import { ApiError } from '../api/client'
import { useFactoryPlan, type FactoryTier } from '../api/queries'
import { FreshnessBadge } from '../components/FreshnessBadge'
import { formatIsk, formatQty } from '../components/format'
import { REGIONS } from '../components/ScanForm'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

interface Input {
  type_id: number
  name: string
  has_price: boolean
  price_source: string | null
  unit_cost: number
}


export interface StepRow {
  output_type_id: number
  output_name: string
  output_qty: number
  cycle_time: number
  inputs: Array<Input & { qty: number; total_cost: number }>
  total_input_cost: number
  output_sell_min: number | null
  output_buy_max: number | null
  output_price_source: string | null
  output_sell_value: number
  output_buy_value: number
  profit_per_cycle_sell: number
  profit_per_cycle_buy: number
  margin_percent_sell: number | null
  cycles_per_day: number
  daily_profit_per_factory: number
  daily_profit_buy_per_factory: number
  factory_slots: number
  daily_input_cost_planet: number
  daily_output_sell_planet: number
  daily_profit_sell_planet: number
  daily_profit_buy_planet: number
  prices_available: boolean
  daily_import_tax: number
  daily_export_tax: number
  daily_tax_total: number
  daily_profit_after_tax: number
}


export interface ChainRow {
  output_type_id: number
  output_name: string
  output_qty_per_day: number
  factory_split: { p3_factories: number; total: number; bif_total?: number }
  p2_inputs: Array<{ type_id: number; name: string; factories: number; daily_produced: number; daily_consumed: number }>
  p1_inputs?: Array<Input & { daily_qty: number; daily_cost: number }>
  p0_inputs?: Array<Input & { daily_qty: number; daily_cost: number }>
  total_daily_p1_cost?: number
  total_daily_p0_cost?: number
  p3_sell_min: number | null
  p3_buy_max: number | null
  p3_price_source: string | null
  daily_p3_sell_value: number
  daily_p3_buy_value: number
  daily_profit_sell: number
  daily_profit_buy: number
  margin_percent_sell: number | null
  daily_import_value: number
  daily_export_value: number
  prices_available: boolean
  daily_import_tax: number
  daily_export_tax: number
  daily_tax_total: number
  daily_profit_after_tax: number
}

const TIERS: Array<[FactoryTier, string]> = [
  ['p1_to_p2', 'P1 → P2 (Buy P1 materials)'],
  ['p2_to_p3', 'P2 → P3 (Buy P2 materials)'],
  ['p3_to_p4', 'P3 → P4 (Buy P3 materials)'],
  ['p1_to_p3', 'P1 → P3 (Single Planet, Buy P1)'],
  ['p0_to_p3', 'P0 → P3 (Single Planet, Buy P0)'],
]

const INFO: Record<FactoryTier, string> = {
  p1_to_p2:
    'A factory planet has no extractors: it buys input materials from the market and uses factories to produce higher-tier commodities. Profit is sell value of the output minus buy cost of the inputs. Planet figures assume 18 factory slots.',
  p2_to_p3:
    'A factory planet has no extractors: it buys input materials from the market and uses factories to produce higher-tier commodities. Profit is sell value of the output minus buy cost of the inputs. Planet figures assume 18 factory slots.',
  p3_to_p4:
    'A factory planet has no extractors: it buys input materials from the market and uses factories to produce higher-tier commodities. Profit is sell value of the output minus buy cost of the inputs. Planet figures assume 18 factory slots.',
  p1_to_p3:
    'Buys P1 materials and converts them on a single planet into P3 goods across 18 factory slots: for 2-input P3 products 6 P3 + 6 + 6 P2 factories, for 3-input products 3 P3 + 5 + 5 + 5 P2 factories.',
  p0_to_p3:
    'Buys P0 raw materials and converts them on a single planet through P1 → P2 → P3 across 18 factory slots: 1 P3 factory, P2 factories per input type and 11 P0 → P1 factories split by demand. Inputs use buy-order prices, outputs sell-order prices.',
}

const isChain = (tier: FactoryTier) => tier === 'p1_to_p3' || tier === 'p0_to_p3'

export function formatCycle(seconds: number): string {
  if (seconds < 3600) return `${(seconds / 60).toFixed(0)} min`
  if (seconds < 86400) return `${(seconds / 3600).toFixed(1)} hr`
  return `${(seconds / 86400).toFixed(1)} day`
}


function isk(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${formatIsk(value)} ISK`
}

function tone(value: number | null | undefined): string {
  if (value === null || value === undefined || value === 0) return 'muted'
  return value > 0 ? 'text-profit' : 'text-loss'
}

function Margin({ pct }: { pct: number | null }) {
  const width = pct === null ? 0 : Math.min(100, Math.max(0, pct))
  const level = pct === null ? '' : pct >= 20 ? 'pi-low' : pct >= 0 ? 'pi-mid' : 'pi-high'
  return (
    <div className="margin">
      <div className={`bar ${level}`} role="img" aria-label={pct === null ? 'No margin' : `Margin ${pct.toFixed(1)}%`}>
        <span style={{ width: `${width}%` }} />
      </div>
      <span className="num small">{pct === null ? '—' : `${pct.toFixed(1)}%`}</span>
    </div>
  )
}

function Warn({ row }: { row: { prices_available: boolean } }) {
  return row.prices_available ? null : <div className="small text-loss">Missing price data</div>
}

function Approx({ source }: { source: string | null }) {
  return source === 'esi_average' ? (
    <abbr title="ESI global average price (no regional order book data)" className="approx">
      ~
    </abbr>
  ) : null
}

function Pill({ typeId, children }: { typeId: number; children: React.ReactNode }) {
  return (
    <span className="pill">
      <img src={typeIconUrl(typeId)} alt="" width={16} height={16} loading="lazy" onError={iconFallback} />
      {children}
    </span>
  )
}

function Line({ label, value, strong, cls }: { label: string; value: React.ReactNode; strong?: boolean; cls?: string }) {
  return (
    <tr className={strong ? 'line-strong' : undefined}>
      <th scope="row">{label}</th>
      <td className={`num ${cls ?? ''}`}>{value}</td>
    </tr>
  )
}

function StepTable({ rows, tax, tier }: { rows: StepRow[]; tax: number; tier: FactoryTier }) {
  const [open, setOpen] = useState<number | null>(null)
  const inTier = tier === 'p1_to_p2' ? 'P1' : tier === 'p2_to_p3' ? 'P2' : 'P3'
  const outTier = tier === 'p1_to_p2' ? 'P2' : tier === 'p2_to_p3' ? 'P3' : 'P4'
  return (
    <div className="table-wrap">
      <table className="planner">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">Product</th>
            <th scope="col">Inputs</th>
            <th scope="col" className="num">
              Input cost
            </th>
            <th scope="col" className="num">
              Sell (min)
            </th>
            <th scope="col" className="num">
              Buy (max)
            </th>
            <th scope="col" className="num">
              Profit / cycle
            </th>
            <th scope="col" className="num">
              Daily / factory
            </th>
            <th scope="col" className="num">
              Daily / planet (×18)
            </th>
            {tax > 0 ? (
              <>
                <th scope="col" className="num">
                  Daily tax
                </th>
                <th scope="col" className="num">
                  Net / planet
                </th>
              </>
            ) : null}
            <th scope="col">Margin</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <Fragment key={r.output_type_id}>
              <tr>
                <td>{i + 1}</td>
                <td>
                  <button type="button" className="toggle" aria-expanded={open === i} onClick={() => setOpen(open === i ? null : i)}>
                    {open === i ? '▾' : '▸'} <strong>{r.output_name}</strong>
                  </button>
                  <div className="muted small">
                    ×{r.output_qty} per cycle · {formatCycle(r.cycle_time)}
                  </div>
                  <Warn row={r} />
                </td>
                <td>
                  {r.inputs.map((inp) => (
                    <Pill key={inp.type_id} typeId={inp.type_id}>
                      {!inp.has_price ? '⚠ ' : null}
                      <Approx source={inp.price_source} />
                      {inp.name} ×{inp.qty}
                    </Pill>
                  ))}
                </td>
                <td className="num">{isk(r.total_input_cost)}</td>
                <td className="num">
                  <Approx source={r.output_price_source} />
                  {r.output_sell_min === null ? '—' : `${isk(r.output_sell_min)} ea`}
                </td>
                <td className="num">{r.output_buy_max === null ? '—' : `${isk(r.output_buy_max)} ea`}</td>
                <td className={`num ${tone(r.profit_per_cycle_sell)}`}>{isk(r.profit_per_cycle_sell)}</td>
                <td className={`num ${tone(r.daily_profit_per_factory)}`}>{isk(r.daily_profit_per_factory)}</td>
                <td className={`num ${tone(r.daily_profit_sell_planet)}`}>
                  <strong>{isk(r.daily_profit_sell_planet)}</strong>
                </td>
                {tax > 0 ? (
                  <>
                    <td className="num text-tax">{isk(r.daily_tax_total)}</td>
                    <td className={`num ${tone(r.daily_profit_after_tax)}`}>
                      <strong>{isk(r.daily_profit_after_tax)}</strong>
                    </td>
                  </>
                ) : null}
                <td>
                  <Margin pct={r.margin_percent_sell} />
                </td>
              </tr>
              {open === i ? (
                <tr className="detail">
                  <td colSpan={tax > 0 ? 12 : 10}>
                    <div className="detail-grid">
                      <div>
                        <h3>Input breakdown</h3>
                        <table>
                          <thead>
                            <tr>
                              <th scope="col">Material</th>
                              <th scope="col" className="num">
                                Qty
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
                            {r.inputs.map((inp) => (
                              <tr key={inp.type_id}>
                                <td>
                                  {!inp.has_price ? '⚠ ' : null}
                                  <Approx source={inp.price_source} />
                                  {inp.name}
                                </td>
                                <td className="num">×{inp.qty}</td>
                                <td className="num">{inp.has_price ? isk(inp.unit_cost) : '—'}</td>
                                <td className="num">{inp.has_price ? isk(inp.total_cost) : '—'}</td>
                              </tr>
                            ))}
                            <tr className="line-strong">
                              <td colSpan={3}>Total input cost</td>
                              <td className="num">{isk(r.total_input_cost)}</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                      <div>
                        <h3>Profitability</h3>
                        <table>
                          <tbody>
                            <Line label="Output qty per cycle" value={r.output_qty} />
                            <Line label="Output value (sell)" value={isk(r.output_sell_value)} />
                            <Line label="Output value (buy)" value={isk(r.output_buy_value)} />
                            <Line label="Cycles per day" value={r.cycles_per_day.toFixed(1)} />
                            <Line label="Profit / cycle (sell)" value={isk(r.profit_per_cycle_sell)} cls={tone(r.profit_per_cycle_sell)} strong />
                            <Line label="Profit / cycle (buy)" value={isk(r.profit_per_cycle_buy)} cls={tone(r.profit_per_cycle_buy)} strong />
                            <Line label="Daily / factory (sell)" value={isk(r.daily_profit_per_factory)} cls={tone(r.daily_profit_per_factory)} strong />
                            <Line label="Daily / factory (buy)" value={isk(r.daily_profit_buy_per_factory)} cls={tone(r.daily_profit_buy_per_factory)} strong />
                            <Line label={`Daily input cost / planet (×${r.factory_slots})`} value={isk(r.daily_input_cost_planet)} />
                            <Line label="Daily output value / planet (sell)" value={isk(r.daily_output_sell_planet)} />
                            <Line label="Daily profit / planet (sell)" value={isk(r.daily_profit_sell_planet)} cls={tone(r.daily_profit_sell_planet)} strong />
                            <Line label="Daily profit / planet (buy)" value={isk(r.daily_profit_buy_planet)} cls={tone(r.daily_profit_buy_planet)} strong />
                            {tax > 0 ? (
                              <>
                                <Line label={`Daily import tax (${inTier}, ${tax}%)`} value={isk(r.daily_import_tax)} cls="text-tax" />
                                <Line label={`Daily export tax (${outTier}, ${tax}%)`} value={isk(r.daily_export_tax)} cls="text-tax" />
                                <Line label="Total daily tax" value={isk(r.daily_tax_total)} cls="text-tax" />
                                <Line label="Net daily profit / planet" value={isk(r.daily_profit_after_tax)} cls={tone(r.daily_profit_after_tax)} strong />
                              </>
                            ) : null}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </td>
                </tr>
              ) : null}
            </Fragment>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ChainTable({ rows, tax, tier }: { rows: ChainRow[]; tax: number; tier: FactoryTier }) {
  const [open, setOpen] = useState<number | null>(null)
  const isP0 = tier === 'p0_to_p3'
  const label = isP0 ? 'P0' : 'P1'
  return (
    <div className="table-wrap">
      <table className="planner">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">P3 product</th>
            <th scope="col">Factory split (18 slots)</th>
            <th scope="col">P2 intermediates</th>
            <th scope="col">{label} inputs (daily)</th>
            <th scope="col" className="num">
              Daily P3 output
            </th>
            <th scope="col" className="num">
              Daily {label} cost
            </th>
            <th scope="col" className="num">
              Daily P3 revenue
            </th>
            <th scope="col" className="num">
              Daily profit
            </th>
            {tax > 0 ? (
              <>
                <th scope="col" className="num">
                  Tax
                </th>
                <th scope="col" className="num">
                  Net profit
                </th>
              </>
            ) : null}
            <th scope="col">Margin</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => {
            const raw = (isP0 ? r.p0_inputs : r.p1_inputs) ?? []
            const cost = (isP0 ? r.total_daily_p0_cost : r.total_daily_p1_cost) ?? 0
            return (
              <Fragment key={r.output_type_id}>
                <tr>
                  <td>{i + 1}</td>
                  <td>
                    <button type="button" className="toggle" aria-expanded={open === i} onClick={() => setOpen(open === i ? null : i)}>
                      {open === i ? '▾' : '▸'} <strong>{r.output_name}</strong>
                    </button>
                    <Warn row={r} />
                  </td>
                  <td className="small">
                    <span className="pill">{r.factory_split.p3_factories}× P3</span>
                    {r.p2_inputs.map((p2, n) => (
                      <span key={p2.type_id} className="pill" title={`Factories for ${p2.name}`}>
                        {p2.factories}× P2-{'ABCDEFG'[n]}
                      </span>
                    ))}
                    {isP0 && r.factory_split.bif_total !== undefined ? <span className="pill">{r.factory_split.bif_total}× BIF</span> : null}
                  </td>
                  <td>
                    {r.p2_inputs.map((p2) => (
                      <Pill key={p2.type_id} typeId={p2.type_id}>
                        {p2.name} ({p2.factories} fac.)
                      </Pill>
                    ))}
                  </td>
                  <td>
                    {raw.map((inp) => (
                      <Pill key={inp.type_id} typeId={inp.type_id}>
                        {!inp.has_price ? '⚠ ' : null}
                        <Approx source={inp.price_source} />
                        {inp.name} ×{formatQty(Math.round(inp.daily_qty))}/day
                      </Pill>
                    ))}
                  </td>
                  <td className="num">
                    <strong>{formatQty(Math.round(r.output_qty_per_day))}</strong>/day
                  </td>
                  <td className="num">{isk(cost)}</td>
                  <td className="num">
                    <Approx source={r.p3_price_source} />
                    {isk(r.daily_p3_sell_value)}
                  </td>
                  <td className={`num ${tone(r.daily_profit_sell)}`}>
                    <strong>{isk(r.daily_profit_sell)}</strong>
                  </td>
                  {tax > 0 ? (
                    <>
                      <td className="num text-tax">{isk(r.daily_tax_total)}</td>
                      <td className={`num ${tone(r.daily_profit_after_tax)}`}>
                        <strong>{isk(r.daily_profit_after_tax)}</strong>
                      </td>
                    </>
                  ) : null}
                  <td>
                    <Margin pct={r.margin_percent_sell} />
                  </td>
                </tr>
                {open === i ? (
                  <tr className="detail">
                    <td colSpan={tax > 0 ? 12 : 10}>
                      <div className="detail-grid">
                        <div>
                          <h3>{label} daily import</h3>
                          <table>
                            <thead>
                              <tr>
                                <th scope="col">Material</th>
                                <th scope="col" className="num">
                                  Qty / day
                                </th>
                                <th scope="col" className="num">
                                  Unit price
                                </th>
                                <th scope="col" className="num">
                                  Daily cost
                                </th>
                              </tr>
                            </thead>
                            <tbody>
                              {raw.map((inp) => (
                                <tr key={inp.type_id}>
                                  <td>
                                    {!inp.has_price ? '⚠ ' : null}
                                    <Approx source={inp.price_source} />
                                    {inp.name}
                                  </td>
                                  <td className="num">{formatQty(Math.round(inp.daily_qty))}</td>
                                  <td className="num">{inp.has_price ? isk(inp.unit_cost) : '—'}</td>
                                  <td className="num">{inp.has_price ? isk(inp.daily_cost) : '—'}</td>
                                </tr>
                              ))}
                              <tr className="line-strong">
                                <td colSpan={3}>Total daily {label} cost</td>
                                <td className="num">{isk(cost)}</td>
                              </tr>
                            </tbody>
                          </table>
                          <h3>P2 production</h3>
                          <table>
                            <thead>
                              <tr>
                                <th scope="col">Material</th>
                                <th scope="col" className="num">
                                  Factories
                                </th>
                                <th scope="col" className="num">
                                  Produced / day
                                </th>
                                <th scope="col" className="num">
                                  Consumed / day
                                </th>
                              </tr>
                            </thead>
                            <tbody>
                              {r.p2_inputs.map((p2) => (
                                <tr key={p2.type_id}>
                                  <td>{p2.name}</td>
                                  <td className="num">{p2.factories}</td>
                                  <td className="num">{formatQty(Math.round(p2.daily_produced))}</td>
                                  <td className="num">{formatQty(Math.round(p2.daily_consumed))}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                        <div>
                          <h3>Profitability</h3>
                          <table>
                            <tbody>
                              <Line label="P3 sell price (min)" value={r.p3_sell_min === null ? '—' : `${isk(r.p3_sell_min)} ea`} />
                              <Line label="P3 buy price (max)" value={r.p3_buy_max === null ? '—' : `${isk(r.p3_buy_max)} ea`} />
                              <Line label="Daily P3 revenue (sell)" value={isk(r.daily_p3_sell_value)} />
                              <Line label="Daily P3 revenue (buy)" value={isk(r.daily_p3_buy_value)} />
                              <Line label="Daily profit (sell)" value={isk(r.daily_profit_sell)} cls={tone(r.daily_profit_sell)} strong />
                              <Line label="Daily profit (buy)" value={isk(r.daily_profit_buy)} cls={tone(r.daily_profit_buy)} strong />
                              {tax > 0 ? (
                                <>
                                  <Line label={`Daily import value (${label})`} value={isk(r.daily_import_value)} />
                                  <Line label="Daily export value (P3)" value={isk(r.daily_export_value)} />
                                  <Line label={`Daily import tax (${tax}%)`} value={isk(r.daily_import_tax)} cls="text-tax" />
                                  <Line label={`Daily export tax (${tax}%)`} value={isk(r.daily_export_tax)} cls="text-tax" />
                                  <Line label="Net daily profit" value={isk(r.daily_profit_after_tax)} cls={tone(r.daily_profit_after_tax)} strong />
                                </>
                              ) : null}
                              <Line label="P3 factories (HTPP)" value={r.factory_split.p3_factories} />
                              {r.p2_inputs.map((p2, n) => (
                                <Line key={p2.type_id} label={`P2-${'ABCDEFG'[n]} factories (AIF): ${p2.name}`} value={p2.factories} />
                              ))}
                              {isP0 && r.factory_split.bif_total !== undefined ? (
                                <Line label="P0→P1 factories (BIF)" value={r.factory_split.bif_total} />
                              ) : null}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </td>
                  </tr>
                ) : null}
              </Fragment>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}


export function summarise(rows: Array<StepRow | ChainRow>, tax: number) {
  const chain = (r: StepRow | ChainRow): r is ChainRow => 'daily_profit_sell' in r
  const pre = (r: StepRow | ChainRow) => (chain(r) ? r.daily_profit_sell : r.daily_profit_sell_planet)
  const best = rows[0]
  const margins = rows.map((r) => r.margin_percent_sell).filter((m): m is number => m !== null)
  return {
    bestProfit: best ? (tax > 0 ? best.daily_profit_after_tax : pre(best)) : null,
    profitable: rows.filter((r) => (chain(r) ? r.daily_profit_sell : r.daily_profit_per_factory) > 0).length,
    total: rows.length,
    bestMargin: margins.length > 0 ? Math.max(...margins) : null,
  }
}

export default function FactoryPlanner() {
  const [tier, setTier] = useState<FactoryTier>('p2_to_p3')
  const [region, setRegion] = useState(String(REGIONS[0][0]))
  const [taxInput, setTaxInput] = useState('0')
  const [tax, setTax] = useState(0)

  
  useEffect(() => {
    const parsed = Number(taxInput)
    const next = taxInput.trim() === '' || !Number.isFinite(parsed) ? 0 : Math.max(0, Math.min(100, parsed))
    const timer = setTimeout(() => setTax(next), 400)
    return () => clearTimeout(timer)
  }, [taxInput])

  const plan = useFactoryPlan({ tier, region_id: Number(region), tax_rate: tax })
  const rows = ((plan.data?.data as unknown as { results?: Array<StepRow | ChainRow> } | undefined)?.results ?? []) as Array<
    StepRow | ChainRow
  >
  const freshness = (plan.data?.meta as unknown as { freshness?: { prices_as_of: string | null; stale: boolean } } | undefined)?.freshness
  
  const shownTier = ((plan.data?.data as unknown as { tier?: FactoryTier } | undefined)?.tier ?? tier) as FactoryTier
  const stats = summarise(rows, tax)

  return (
    <>
      <div className="page-head">
        <h1>Factory planet planner</h1>
        {freshness ? <FreshnessBadge asOf={freshness.prices_as_of} stale={freshness.stale} label="Prices" /> : null}
      </div>
      <p className="muted">
        Find the most profitable factory planet by buying PI materials and producing higher-tier commodities.
      </p>
      <div className="panel planner-form">
        <div className="scan-field">
          <label htmlFor="fp-region">Region</label>
          <select id="fp-region" value={region} onChange={(e) => setRegion(e.target.value)}>
            {REGIONS.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </select>
        </div>
        <div className="scan-field">
          <label htmlFor="fp-tax">PI tax rate (%)</label>
          <input id="fp-tax" type="number" min={0} max={100} step={0.1} value={taxInput} onChange={(e) => setTaxInput(e.target.value)} />
        </div>
        {tax > 0 ? <span className="badge badge-stale">{tax.toFixed(1)}% tax applied</span> : null}
      </div>
      <div className="tiers" role="tablist" aria-label="Tier">
        {TIERS.map(([key, label]) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tier === key}
            className={tier === key ? 'button tier active' : 'button tier'}
            onClick={() => setTier(key)}
          >
            {label}
          </button>
        ))}
      </div>
      <p className="panel small">{INFO[tier]}</p>

      {plan.isPending ? <p>Loading market prices…</p> : null}
      {plan.isError ? (
        <p role="alert">{plan.error instanceof ApiError ? plan.error.message : 'Could not calculate this tier.'}</p>
      ) : null}
      {plan.isSuccess && rows.length === 0 ? (
        <div className="panel">
          <p>No market price data available.</p>
          <p className="muted small">Prices for PI materials need to be fetched first.</p>
        </div>
      ) : null}
      {rows.length > 0 ? (
        <>
          <dl className="stats">
            <div className="panel">
              <dd className="text-profit">{isk(stats.bestProfit)}</dd>
              <dt className="muted small">{tax > 0 ? 'Best daily profit (after tax)' : 'Best daily profit / planet'}</dt>
            </div>
            <div className="panel">
              <dd>{stats.profitable}</dd>
              <dt className="muted small">Profitable products</dt>
            </div>
            <div className="panel">
              <dd>{stats.total}</dd>
              <dt className="muted small">Total products</dt>
            </div>
            <div className="panel">
              <dd>{stats.bestMargin === null ? '—' : `${stats.bestMargin.toFixed(1)}%`}</dd>
              <dt className="muted small">Best profit margin</dt>
            </div>
          </dl>
          <div aria-busy={plan.isPlaceholderData} style={plan.isPlaceholderData ? { opacity: 0.5 } : undefined}>
            {isChain(shownTier) ? (
              <ChainTable key={shownTier} rows={rows as ChainRow[]} tax={tax} tier={shownTier} />
            ) : (
              <StepTable key={shownTier} rows={rows as StepRow[]} tax={tax} tier={shownTier} />
            )}
          </div>
        </>
      ) : null}
      <p className="muted small">
        Prices come from the stored order sweeps of the selected region. Where an order book is missing the ESI global
        average is used (shown as ~). Recipe quantities are the standard PI schematics. PI tax is estimated on market
        value; the game charges it on NPC base prices.
      </p>
    </>
  )
}
