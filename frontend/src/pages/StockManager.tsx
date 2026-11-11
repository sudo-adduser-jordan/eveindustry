import { useState, type FormEvent } from 'react'
import { ApiError } from '../api/client'
import { useStockMutations, useStockReport, useStockSearch } from '../api/queries'
import type { components } from '../api/schema'
import { formatQty } from '../components/format'
import { ScopeWarning } from '../components/ScopeWarning'

type Item = components['schemas']['StockReportItemResource']
type StockType = components['schemas']['StockTypeResource']

const SCOPES = [{ scope: 'esi-assets.read_corporation_assets.v1', description: 'Read Corporation Assets' }]


export function parseQuantity(text: string): number | null {
  const n = Number(text)
  return text.trim() !== '' && Number.isInteger(n) && n >= 0 ? n : null
}

function AddForm({ onAdd, busy, failed }: { onAdd: (type: StockType, qty: number) => Promise<boolean>; busy: boolean; failed: boolean }) {
  const [term, setTerm] = useState('')
  const [picked, setPicked] = useState<StockType | null>(null)
  const [qty, setQty] = useState('0')
  const results = useStockSearch(picked ? '' : term)
  const quantity = parseQuantity(qty)

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (!picked || quantity === null) return
    if (await onAdd(picked, quantity)) {
      setTerm('')
      setPicked(null)
      setQty('0')
    }
  }

  return (
    <form className="panel stock-add" onSubmit={submit}>
      <div className="scan-field typeahead">
        <label htmlFor="stock-search">Find an item to watch</label>
        <input
          id="stock-search"
          value={term}
          autoComplete="off"
          placeholder="Start typing a type name…"
          onChange={(e) => {
            setTerm(e.target.value)
            setPicked(null)
          }}
        />
        {!picked && results.data && results.data.data.length > 0 ? (
          <ul className="typeahead-list" role="listbox" aria-label="Matching items">
            {results.data.data.map((t) => (
              <li key={t.type_id}>
                <button className="option"
                  type="button"
                  role="option"
                  aria-selected={false}
                  onClick={() => {
                    setPicked(t)
                    setTerm(t.name)
                  }}
                >
                  <img src={t.icon_url} alt="" width={24} height={24} />
                  {t.name}
                </button>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
      <div className="scan-field">
        <label htmlFor="stock-desired">Desired quantity</label>
        <input id="stock-desired" type="number" min={0} step={1} value={qty} onChange={(e) => setQty(e.target.value)} />
      </div>
      <button type="submit" className="button" disabled={!picked || quantity === null || busy}>
        Add
      </button>
      {failed ? <p role="alert">Could not add the item.</p> : null}
    </form>
  )
}

function Row({ item, onSave, onRemove, busy }: { item: Item; onSave: (qty: number) => void; onRemove: () => void; busy: boolean }) {
  const [qty, setQty] = useState(String(item.desired_quantity))
  const quantity = parseQuantity(qty)
  const changed = quantity !== null && quantity !== item.desired_quantity
  return (
    <tr>
      <td>
        <span className="asset-name">
          <img src={item.icon_url} alt="" width={32} height={32} loading="lazy" />
          <span>{item.type_name}</span>
        </span>
      </td>
      <td className="num">
        <input
          type="number"
          min={0}
          step={1}
          className="qty-input"
          aria-label={`Desired quantity of ${item.type_name}`}
          value={qty}
          onChange={(e) => setQty(e.target.value)}
        />
      </td>
      <td className="num">{formatQty(item.on_hand)}</td>
      <td className={item.shortfall > 0 ? 'num text-loss' : 'num muted'}>{formatQty(item.shortfall)}</td>
      <td className="num">
        <button type="button" className="button" disabled={!changed || busy} onClick={() => quantity !== null && onSave(quantity)}>
          Save
        </button>{' '}
        <button type="button" className="button" disabled={busy} onClick={onRemove} aria-label={`Remove ${item.type_name}`}>
          Remove
        </button>
      </td>
    </tr>
  )
}

export default function StockManager() {
  const report = useStockReport()
  const { add, update, remove, refresh } = useStockMutations()

  if (report.isPending) return <p>Loading stock…</p>
  if (report.isError) {
    return report.error instanceof ApiError && report.error.status === 403 ? (
      <>
        <h1>Industry stock manager</h1>
        <p role="alert" className="panel warn">
          The stock manager is not enabled for your account. Ask an admin to turn it on.
        </p>
      </>
    ) : (
      <p role="alert">Could not load your stock.</p>
    )
  }

  const busy = update.isPending || remove.isPending
  return (
    <>
      <div className="page-head">
        <h1>Industry stock manager</h1>
        <button type="button" className="button" disabled={refresh.isPending} onClick={() => refresh.mutate()}>
          {refresh.isPending ? 'Refreshing…' : 'Refresh'}
        </button>
      </div>
      <p>Track desired stock levels and compare them with what your corporation hangars hold.</p>
      <ScopeWarning required={SCOPES} />
      {report.data.meta.warning ? (
        <div className="panel warn" role="alert">
          {report.data.meta.warning}
          <div className="muted small">On-hand quantities show 0 until corporation assets can be read.</div>
        </div>
      ) : null}
      {update.isError || remove.isError || refresh.isError ? (
        <p role="alert" className="panel warn">
          That change did not go through. Try again.
        </p>
      ) : null}

      <AddForm
        busy={add.isPending}
        failed={add.isError}
        onAdd={(type, desired_quantity) =>
          add.mutateAsync({ type_id: type.type_id, desired_quantity }).then(
            () => true,
            () => false,
          )
        }
      />

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Item</th>
              <th className="num">Desired</th>
              <th className="num">On hand</th>
              <th className="num">Shortfall</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {report.data.data.length === 0 ? (
              <tr>
                <td colSpan={5} className="muted">
                  No items in your stock watchlist yet. Search above to add one.
                </td>
              </tr>
            ) : (
              report.data.data.map((item) => (
                <Row
                  key={`${item.id}-${item.desired_quantity}`}
                  item={item}
                  busy={busy}
                  onSave={(desired_quantity) => update.mutate({ id: item.id, desired_quantity })}
                  onRemove={() => {
                    if (window.confirm(`Remove ${item.type_name} from your watchlist?`)) remove.mutate(item.id)
                  }}
                />
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}
