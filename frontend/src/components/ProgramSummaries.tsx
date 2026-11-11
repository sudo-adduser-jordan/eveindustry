import { useState } from 'react'
import type { components } from '../api/schema'
import { formatQty } from './format'
import { typeIconUrl, iconFallback } from './typeIcon'

type Progress = components['schemas']['BuildProgramProgressResource']
export type Blueprint = Progress['required_blueprints'][number]
export type Stock = Progress['base_materials'][number]
export type Reaction = Progress['reactions_summary'][number]

const vol = (n: number, digits = 1) => n.toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits })

function Icon({ typeId, size = 24 }: { typeId: number; size?: number }) {
  return (
    <img
      src={typeIconUrl(typeId)}
      alt=""
      width={size}
      height={size}
      loading="lazy"
      onError={iconFallback}
    />
  )
}


export function multibuyText(items: Stock[], missingOnly: boolean): string {
  return items
    .filter((m) => !missingOnly || m.quantity > m.available)
    .map((m) => `${m.name} ${missingOnly ? Math.max(0, m.quantity - m.available) : m.quantity}`)
    .join('\n')
}

function CopyButton({ label, text }: { label: string; text: string }) {
  const [done, setDone] = useState(false)
  return (
    <button
      type="button"
      className="button small"
      disabled={text === ''}
      onClick={() => {
        void navigator.clipboard.writeText(text).then(() => {
          setDone(true)
          setTimeout(() => setDone(false), 1500)
        })
      }}
    >
      {done ? 'Copied!' : label}
    </button>
  )
}

export function BlueprintsPanel({ blueprints }: { blueprints: Blueprint[] }) {
  const missing = blueprints.filter((b) => !b.owned).length
  return (
    <details className="panel" open>
      <summary>
        <strong>Required blueprints &amp; formulas</strong>{' '}
        {blueprints.length === 0 ? (
          <span className="badge">—</span>
        ) : missing > 0 ? (
          <span className="badge score-bad">{missing} missing</span>
        ) : (
          <span className="badge score-best">All owned</span>
        )}
      </summary>
      {blueprints.length === 0 ? (
        <p className="muted small">No blueprints or formulas required.</p>
      ) : (
        <ul className="summary-list">
          {blueprints.map((b) => (
            <li key={b.type_id}>
              <Icon typeId={b.type_id} />
              <span className="grow">{b.name}</span>
              <span className={b.activity_type === 'reaction' ? 'badge text-reactions' : 'badge text-manufacturing'}>
                {b.activity_type === 'reaction' ? 'Reaction' : 'BPC/BPO'}
              </span>
              <span className={b.owned ? 'badge score-best' : 'badge score-bad'}>{b.owned ? 'Owned' : 'Missing'}</span>
            </li>
          ))}
        </ul>
      )}
    </details>
  )
}


export function StockPanel({ title, items, empty }: { title: string; items: Stock[]; empty: string }) {
  const groups = new Map<string, Stock[]>()
  for (const m of items) groups.set(m.group_name || 'Other', [...(groups.get(m.group_name || 'Other') ?? []), m])
  const names = [...groups.keys()].sort((a, b) => (a === 'Other' ? 1 : b === 'Other' ? -1 : a.localeCompare(b)))
  const totalVol = items.reduce((sum, m) => sum + m.volume * m.quantity, 0)
  const anyMissing = items.some((m) => m.available < m.quantity)

  return (
    <details className="panel" open>
      <summary>
        <strong>{title}</strong>
      </summary>
      {items.length === 0 ? (
        <p className="muted small">{empty}</p>
      ) : (
        <>
          <div className="page-actions">
            <CopyButton label="Buy all" text={multibuyText(items, false)} />
            {anyMissing ? <CopyButton label="Buy missing" text={multibuyText(items, true)} /> : null}
          </div>
          {names.map((group) => (
            <div key={group}>
              {names.length > 1 || group !== 'Fuel Blocks' ? <h3 className="summary-group">{group}</h3> : null}
              <ul className="summary-list">
                {groups.get(group)!.map((m) => {
                  const enough = m.available >= m.quantity
                  return (
                    <li key={m.type_id}>
                      <Icon typeId={m.type_id} />
                      <span className="grow">{m.name}</span>
                      <span className="muted small">{vol(m.volume * m.quantity)} m³</span>
                      <span className={enough ? 'text-profit' : 'text-loss'}>{formatQty(m.available)}</span>
                      <span className="muted">/ {formatQty(m.quantity)}</span>
                      {enough ? <span className="text-profit small">✓</span> : <span className="text-loss small">Need {formatQty(m.quantity - m.available)}</span>}
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}
          <p className="small muted total-vol">
            Total volume: <strong>{vol(totalVol, 2)} m³</strong>
          </p>
        </>
      )}
    </details>
  )
}

function ReactionRow({ r }: { r: Reaction }) {
  const [open, setOpen] = useState(false)
  const done = r.net_runs <= 0
  const stock = r.available >= r.output_qty ? 'text-profit' : r.available > 0 ? 'text-research' : 'text-loss'
  return (
    <>
      <tr>
        <td>
          <span className="asset-name">
            <Icon typeId={r.type_id} size={20} />
            {r.name}
          </span>
        </td>
        <td className="num">{formatQty(r.total_runs)}</td>
        <td className={`num ${stock}`}>{formatQty(r.available)}</td>
        <td className="num">{formatQty(r.net_runs)}</td>
        <td className="num">
          {formatQty(r.net_output_qty)} <span className="muted small">/ {formatQty(r.output_qty)}</span>
        </td>
        <td className="num muted small">{vol(r.input_volume)} m³</td>
        <td className="num muted small">{vol(r.output_volume)} m³</td>
        <td>
          {done ? <span className="text-profit">✓</span> : <span className="text-research">Need {formatQty(r.net_runs)}r</span>}{' '}
          {r.inputs.length > 0 ? (
            <button type="button" className="toggle" aria-expanded={open} aria-label={`${open ? 'Hide' : 'Show'} materials for ${r.name}`} onClick={() => setOpen(!open)}>
              {open ? '▲' : '▼'}
            </button>
          ) : null}
        </td>
      </tr>
      {open ? (
        <tr className="child-row">
          <td colSpan={8}>
            <table>
              <thead>
                <tr>
                  <th>Material</th>
                  <th className="num">In stock</th>
                  <th className="num">Required</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {r.inputs.map((i) => {
                  const enough = i.available >= i.required_qty
                  return (
                    <tr key={i.type_id}>
                      <td>
                        <span className="asset-name">
                          <Icon typeId={i.type_id} size={16} />
                          {i.name}
                        </span>
                      </td>
                      <td className={`num ${enough ? 'text-profit' : i.available > 0 ? 'text-research' : 'text-loss'}`}>{formatQty(i.available)}</td>
                      <td className="num muted">{formatQty(i.required_qty)}</td>
                      <td>{enough ? <span className="text-profit">✓</span> : <span className="text-loss">−{formatQty(i.required_qty - i.available)}</span>}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </td>
        </tr>
      ) : null}
    </>
  )
}

export function ReactionsPanel({ reactions }: { reactions: Reaction[] }) {
  const pending = reactions.filter((r) => r.net_runs > 0).length
  return (
    <details className="panel" open>
      <summary>
        <strong>Reactions summary</strong>{' '}
        {reactions.length === 0 ? (
          <span className="badge">—</span>
        ) : pending > 0 ? (
          <span className="badge score-good">{pending} pending</span>
        ) : (
          <span className="badge score-best">All done</span>
        )}
      </summary>
      {reactions.length === 0 ? (
        <p className="muted small">No reactions required.</p>
      ) : (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Reaction</th>
                  <th className="num">Total runs</th>
                  <th className="num">In stock</th>
                  <th className="num">Net runs</th>
                  <th className="num">Output qty</th>
                  <th className="num">Input vol</th>
                  <th className="num">Output vol</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {reactions.map((r) => (
                  <ReactionRow key={r.type_id} r={r} />
                ))}
              </tbody>
            </table>
          </div>
          <p className="small muted total-vol">
            Total input volume: <strong>{vol(reactions.reduce((s, r) => s + r.input_volume, 0), 2)} m³</strong> · Total output volume:{' '}
            <strong>{vol(reactions.reduce((s, r) => s + r.output_volume, 0), 2)} m³</strong>
          </p>
        </>
      )}
    </details>
  )
}
