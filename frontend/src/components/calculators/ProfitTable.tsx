import { useState, type ReactNode } from 'react'
import { formatQty } from '../format'
import { isk, profitClass, signedIsk } from './format'
import { TypeIcon } from './kit'

export interface SellScore {
  score: number
  avg_daily_volume: number
  avg_order_count: number
  active_days: number
  total_days: number
}


export interface ProfitRow {
  product_type_id: number
  product_name: string
  total_output: number
  total_input_cost: number
  total_output_value: number
  total_job_cost: number
  scc_fee: number
  profit: number
  profit_positive: boolean
  profit_per_unit: number
  profit_margin: number
  sell_score: SellScore
}

type SortKey = 'product_name' | 'total_output' | 'total_input_cost' | 'total_output_value' | 'total_job_cost' | 'scc_fee' | 'profit' | 'profit_per_unit' | 'profit_margin' | 'sell_score'

const COLUMNS: Array<{ key: SortKey; label: string; numeric: boolean }> = [
  { key: 'product_name', label: 'Item', numeric: false },
  { key: 'total_output', label: 'Output', numeric: true },
  { key: 'total_input_cost', label: 'Input cost', numeric: true },
  { key: 'total_output_value', label: 'Output value', numeric: true },
  { key: 'total_job_cost', label: 'Job cost', numeric: true },
  { key: 'scc_fee', label: 'SCC fee', numeric: true },
  { key: 'profit', label: 'Profit', numeric: true },
  { key: 'profit_per_unit', label: 'Profit/unit', numeric: true },
  { key: 'profit_margin', label: 'Margin', numeric: true },
  { key: 'sell_score', label: 'Sell score', numeric: false },
]

function sellScoreColor(score: number): string {
  if (score >= 8) return '#2e7d32'
  if (score >= 6) return '#558b2f'
  if (score >= 4) return '#f9a825'
  if (score >= 2) return '#e65100'
  return '#c62828'
}

export function SellScoreBadge({ score }: { score: SellScore }) {
  const title = `Score: ${score.score}/10\nAvg daily volume: ${formatQty(score.avg_daily_volume)}\nAvg daily orders: ${score.avg_order_count}\nActive trading days: ${score.active_days}/${score.total_days}`
  return (
    <span className="score" style={{ background: sellScoreColor(score.score) }} title={title}>
      {score.score}
    </span>
  )
}

function value(row: ProfitRow, key: SortKey): number | string {
  return key === 'sell_score' ? row.sell_score.score : row[key]
}


export function ProfitTable<T extends ProfitRow>({
  rows,
  itemLabel,
  extraFilter,
  extraSearchText,
}: {
  rows: T[]
  itemLabel: string
  
  extraFilter?: { controls: ReactNode; test: (row: T) => boolean }
  
  extraSearchText?: (row: T) => string
}) {
  const [sortKey, setSortKey] = useState<SortKey>('profit')
  const [dir, setDir] = useState<'asc' | 'desc'>('desc')
  const [filter, setFilter] = useState('')

  const q = filter.trim().toLowerCase()
  const shown = rows
    .filter((r) => (extraFilter ? extraFilter.test(r) : true))
    .filter((r) => q === '' || r.product_name.toLowerCase().includes(q) || (extraSearchText?.(r) ?? '').toLowerCase().includes(q))
    .sort((a, b) => {
      const x = value(a, sortKey)
      const y = value(b, sortKey)
      const c = typeof x === 'string' ? x.localeCompare(String(y), undefined, { sensitivity: 'base' }) : x - Number(y)
      return dir === 'asc' ? c : -c
    })

  function sortBy(key: SortKey, numeric: boolean) {
    if (key === sortKey) setDir(dir === 'asc' ? 'desc' : 'asc')
    else {
      setSortKey(key)
      setDir(numeric || key === 'sell_score' ? 'desc' : 'asc')
    }
  }

  return (
    <>
      <div className="search" style={{ maxWidth: 'none', flexWrap: 'wrap' }}>
        <label className="visually-hidden" htmlFor="profit-filter">
          Filter by name
        </label>
        <input id="profit-filter" type="search" placeholder="Filter by name…" value={filter} onChange={(e) => setFilter(e.target.value)} />
        {extraFilter?.controls}
        <span className="muted" role="status">
          {shown.length} of {rows.length} {itemLabel}
        </span>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              {COLUMNS.map((c) => (
                <th
                  key={c.key}
                  scope="col"
                  className={c.numeric ? 'num' : undefined}
                  aria-sort={c.key === sortKey ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                >
                  <button type="button" className="sort" onClick={() => sortBy(c.key, c.numeric)}>
                    {c.label}
                    <span aria-hidden="true">{c.key === sortKey ? (dir === 'asc' ? ' ↑' : ' ↓') : ''}</span>
                  </button>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {shown.length === 0 ? (
              <tr>
                <td colSpan={COLUMNS.length} className="muted">
                  No {itemLabel} found.
                </td>
              </tr>
            ) : (
              shown.map((r) => (
                <tr key={r.product_type_id}>
                  <td>
                    <span className="asset-name">
                      <TypeIcon typeId={r.product_type_id} />
                      {r.product_name}
                    </span>
                  </td>
                  <td className="num">{formatQty(r.total_output)}</td>
                  <td className="num">{isk(r.total_input_cost)}</td>
                  <td className="num">{isk(r.total_output_value)}</td>
                  <td className="num">{isk(r.total_job_cost)}</td>
                  <td className="num text-warn">{isk(r.scc_fee)}</td>
                  <td className={`num ${profitClass(r.profit)}`}>{signedIsk(r.profit)}</td>
                  <td className={`num ${profitClass(r.profit)}`}>{signedIsk(r.profit_per_unit)}</td>
                  <td className={`num ${profitClass(r.profit)}`}>{r.profit_margin}%</td>
                  <td>
                    <SellScoreBadge score={r.sell_score} />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}
