import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useAppraise, useMe } from '../api/queries'
import { formatIsk, formatQty } from '../components/format'
import { FreshnessBadge } from '../components/FreshnessBadge'

type Filter = 'all' | 'sell' | 'buy'
type Method = 'average' | 'lowest'

const FILTERS: Array<[Filter, string]> = [
  ['all', 'All'],
  ['sell', 'Sell'],
  ['buy', 'Buy'],
]
const METHODS: Array<[Method, string]> = [
  ['average', 'Average'],
  ['lowest', 'Lowest'],
]

const EXAMPLE_ITEMS = 'Tritanium\nTritanium\nTritanium\nPyerite\nPyerite\nMexallon\nRifter'

export default function ContractAppraisal() {
  const [items, setItems] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [method, setMethod] = useState<Method>('average')
  const appraise = useAppraise()
  const me = useMe()
  const isDemo = me.data?.data.demo === true
  const demoRan = useRef(false)
  useEffect(() => {
    if (!isDemo || demoRan.current || appraise.isPending || appraise.data || appraise.isError) return
    const timer = setTimeout(() => {
      demoRan.current = true
      setItems(EXAMPLE_ITEMS)
      appraise.mutate({ items: EXAMPLE_ITEMS, filter: 'all', method: 'average' })
    }, 0)
    return () => clearTimeout(timer)
  }, [isDemo, appraise.isPending, appraise.data, appraise.isError])

  function submit(e: FormEvent) {
    e.preventDefault()
    if (items.trim() === '') return
    appraise.mutate({ items, filter, method })
  }

  const result = appraise.data?.data
  return (
    <>
      <h1>Contract appraisal</h1>
      <p>
        Paste item names, one per line (handy for Abyssal BPCs and the like); duplicate lines add up. Prices come from
        public item exchange contracts holding only that item: the mean per unit, or the cheapest.
      </p>
      <div className="scan-layout">
        <form className="panel scan-form" onSubmit={submit}>
          <div className="scan-field">
            <label htmlFor="appraisal-items">Items</label>
            <textarea
              id="appraisal-items"
              rows={14}
              value={items}
              maxLength={50000}
              placeholder="Paste item names here, one per line…"
              onChange={(e) => setItems(e.target.value)}
            />
          </div>
          <fieldset className="choice">
            <legend>Contracts</legend>
            {FILTERS.map(([value, label]) => (
              <label key={value}>
                <input type="radio" name="filter" checked={filter === value} onChange={() => setFilter(value)} /> {label}
              </label>
            ))}
          </fieldset>
          <fieldset className="choice">
            <legend>Unit price</legend>
            {METHODS.map(([value, label]) => (
              <label key={value}>
                <input type="radio" name="method" checked={method === value} onChange={() => setMethod(value)} /> {label}
              </label>
            ))}
          </fieldset>
          <button type="submit" className="button" disabled={appraise.isPending || items.trim() === ''}>
            {appraise.isPending ? 'Appraising…' : 'Appraise'}
          </button>
        </form>
        <div>
          {appraise.isError ? (
            <p role="alert" className="panel warn">
              {appraise.error.message}
            </p>
          ) : null}
          {result && appraise.data ? (
            <>
              <p>
                <FreshnessBadge
                  label="Contracts"
                  asOf={appraise.data.meta.freshness.prices_as_of}
                  stale={appraise.data.meta.freshness.stale}
                />
              </p>
              <dl className="panel stats">
                <div>
                  <dt className="muted">Total value</dt>
                  <dd className="text-profit">{formatIsk(result.total_value)} ISK</dd>
                </div>
                <div>
                  <dt className="muted">Unique items</dt>
                  <dd>{result.items.length}</dd>
                </div>
                <div>
                  <dt className="muted">Unmatched</dt>
                  <dd>{result.unmatched.length}</dd>
                </div>
              </dl>
              {result.items.length > 0 ? (
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Item</th>
                        <th className="num">Qty</th>
                        <th className="num">Unit price</th>
                        <th className="num">Line total</th>
                        <th className="num">Contracts</th>
                      </tr>
                    </thead>
                    <tbody>
                      {result.items.map((row) => (
                        <tr key={row.type_id}>
                          <td>{row.name}</td>
                          <td className="num">{formatQty(row.quantity)}</td>
                          <td className="num">
                            {row.contracts_found > 0 ? (
                              `${formatIsk(row.unit_price)} ISK`
                            ) : (
                              <span className="badge badge-stale" title="No matching contracts found">
                                no contracts
                              </span>
                            )}
                          </td>
                          <td className="num">{row.contracts_found > 0 ? `${formatIsk(row.line_total)} ISK` : '—'}</td>
                          <td className="num">{row.contracts_found}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}
              {result.unmatched.length > 0 ? (
                <section className="panel warn">
                  <h2 className="subhead">Unmatched items</h2>
                  <p className="muted small">These names match no item in the database:</p>
                  <ul>
                    {result.unmatched.map((name, i) => (
                      <li key={`${name}-${i}`}>{name}</li>
                    ))}
                  </ul>
                </section>
              ) : null}
            </>
          ) : appraise.isPending ? (
            <p>Appraising items…</p>
          ) : (
            <p className="panel">Paste item names and press Appraise.</p>
          )}
        </div>
      </div>
    </>
  )
}
