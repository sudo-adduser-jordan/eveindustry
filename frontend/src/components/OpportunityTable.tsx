import { useState, type ReactNode } from 'react'
import { typeIconUrl, iconFallback } from './typeIcon'

export interface Column<T> {
  
  key: keyof T & string
  label: string
  render: (row: T) => ReactNode
  
  numeric?: boolean
}

type Row = { type_id: number; type_name: string }


function compare(a: unknown, b: unknown): number {
  if (a === b) return 0
  if (a === null || a === undefined) return 1
  if (b === null || b === undefined) return -1
  if (typeof a === 'number' && typeof b === 'number') return a - b
  return String(a).localeCompare(String(b), undefined, { sensitivity: 'base' })
}


export function OpportunityTable<T extends Row>({
  rows,
  columns,
  initialSort,
}: {
  rows: T[]
  columns: Column<T>[]
  initialSort: keyof T & string
}) {
  const [sortKey, setSortKey] = useState<keyof T & string>(initialSort)
  const [dir, setDir] = useState<'asc' | 'desc'>('desc')
  const [search, setSearch] = useState('')

  const q = search.trim().toLowerCase()
  const shown = rows
    .filter((r) => q === '' || r.type_name.toLowerCase().includes(q))
    .sort((a, b) => (dir === 'asc' ? 1 : -1) * compare(a[sortKey], b[sortKey]))

  function sortBy(column: Column<T>) {
    if (column.key === sortKey) {
      setDir(dir === 'asc' ? 'desc' : 'asc')
    } else {
      setSortKey(column.key)
      setDir(column.numeric ? 'desc' : 'asc')
    }
  }

  return (
    <>
      <div className="search">
        <label className="visually-hidden" htmlFor="opportunity-search">
          Search items
        </label>
        <input
          id="opportunity-search"
          type="search"
          value={search}
          placeholder="Search items…"
          onChange={(e) => setSearch(e.target.value)}
        />
        <span className="muted" role="status">
          {shown.length} of {rows.length}
        </span>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              {columns.map((c) => (
                <th
                  key={c.key}
                  scope="col"
                  className={c.numeric ? 'num' : undefined}
                  aria-sort={c.key === sortKey ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                >
                  <button type="button" className="sort" onClick={() => sortBy(c)}>
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
                <td colSpan={columns.length} className="muted">
                  No items match your search.
                </td>
              </tr>
            ) : (
              shown.map((row) => (
                <tr key={row.type_id}>
                  {columns.map((c) => (
                    <td key={c.key} className={c.numeric ? 'num' : undefined}>
                      {c.render(row)}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}


export function ItemCell({ row }: { row: Row }) {
  return (
    <span className="asset-name">
      <img
        src={typeIconUrl(row.type_id)}
        alt=""
        width={24}
        height={24}
        loading="lazy"
        onError={iconFallback}
      />
      {row.type_name}
    </span>
  )
}


export function Signed({ value, children }: { value: number; children: ReactNode }) {
  return <span className={value >= 0 ? 'text-profit' : 'text-loss'}>{children}</span>
}
