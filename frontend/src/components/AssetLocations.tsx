import { useState, type ReactNode } from 'react'
import type { components } from '../api/schema'
import { formatQty, portraitUrl } from './format'
import { useAssetItems, type AssetScope } from '../api/queries'
import { typeIconUrl, iconFallback } from './typeIcon'

type Asset = components['schemas']['AssetResource'] & { children_count?: number; has_children?: boolean }
type SolarSystem = components['schemas']['AssetSolarSystemResource']
type Station = SolarSystem['stations'][number] & { item_count?: number }


function Icon({ src, size }: { src: string; size: number }) {
  return (
    <img
      src={src}
      alt=""
      width={size}
      height={size}
      loading="lazy"
      onError={iconFallback}
    />
  )
}

function ItemName({ item }: { item: Asset }) {
  return (
    <span className="asset-name">
      <Icon src={typeIconUrl(item.type_id)} size={24} />
      <span>
        {item.name ?? item.type_name}
        {item.name ? <small className="muted">{item.type_name}</small> : null}
      </span>
    </span>
  )
}

function ItemRows({ item }: { item: Asset }) {
  const [open, setOpen] = useState(false)
  const hasChildren = item.children.length > 0
  return (
    <>
      <tr>
        <td className="toggle-cell">
          {hasChildren ? (
            <button
              type="button"
              className="toggle"
              aria-expanded={open}
              aria-label={`${open ? 'Hide' : 'Show'} ${item.children.length} items inside ${item.name ?? item.type_name}`}
              onClick={() => setOpen(!open)}
            >
              {open ? '▾' : '▸'}
            </button>
          ) : null}
        </td>
        <td>
          <ItemName item={item} />
          {hasChildren ? <span className="badge">{item.children.length}</span> : null}
        </td>
        <td className="num">{formatQty(item.quantity)}</td>
        <td>
          <span className="asset-name">
            <Icon src={portraitUrl(item.character_id, 32)} size={16} />
            {item.character_name}
          </span>
        </td>
      </tr>
      {open
        ? item.children.map((child) => (
            <tr key={child.item_id} className="child-row">
              <td />
              <td>
                <ItemName item={child} />
              </td>
              <td className="num">{formatQty(child.quantity)}</td>
              <td className="muted">{child.location_flag}</td>
            </tr>
          ))
        : null}
    </>
  )
}

function PagedItems({ scope, locationId, parentId, search, revision }: {
  scope: AssetScope; locationId?: number; parentId?: number; search?: string; revision?: string | null
}) {
  const items = useAssetItems(scope, {
    location_id: locationId,
    parent_item_id: parentId,
    search: search || undefined,
  }, revision)
  if (items.isPending) return <tr><td colSpan={4} role="status">Loading items…</td></tr>
  if (items.isError) return <tr><td colSpan={4} role="alert">Could not load items. <button type="button" className="button small" onClick={() => void items.refetch()}>Try again</button></td></tr>
  const rows = items.data.pages.flatMap((page) => page.data)
  return <>
    {rows.map((item) => <LazyItemRows key={`${item.character_id}:${item.item_id}`} item={item} scope={scope} search={search} revision={revision} />)}
    {rows.length === 0 ? <tr><td colSpan={4}>No items found.</td></tr> : null}
    {items.hasNextPage ? <tr><td colSpan={4}><button type="button" className="button small" disabled={items.isFetchingNextPage} onClick={() => void items.fetchNextPage()}>{items.isFetchingNextPage ? 'Loading…' : 'Show more items'}</button></td></tr> : null}
  </>
}

function LazyItemRows({ item, scope, search, revision }: { item: Asset; scope: AssetScope; search?: string; revision?: string | null }) {
  const [open, setOpen] = useState(false)
  const count = item.children_count ?? item.children.length
  const hasChildren = item.has_children ?? count > 0
  return <>
    <tr>
      <td className="toggle-cell">{hasChildren ? <button type="button" className="toggle" aria-expanded={open} aria-label={`${open ? 'Hide' : 'Show'} ${count} items inside ${item.name ?? item.type_name}`} onClick={() => setOpen(!open)}>{open ? '▾' : '▸'}</button> : null}</td>
      <td><ItemName item={item} />{hasChildren ? <span className="badge">{formatQty(count)}</span> : null}</td>
      <td className="num">{formatQty(item.quantity)}</td>
      <td>{item.character_name}</td>
    </tr>
    {open ? <tr className="child-row"><td /><td colSpan={3}><div className="table-wrap"><table><tbody><PagedItems scope={scope} parentId={item.item_id} search={search} revision={revision} /></tbody></table></div></td></tr> : null}
  </>
}

function LazyStation({ station, scope, search, revision }: { station: Station; scope: AssetScope; search?: string; revision?: string | null }) {
  const [open, setOpen] = useState(false)
  return <details className="station" onToggle={(event) => setOpen(event.currentTarget.open)}>
    <summary>{station.location_name}<span className="badge">{formatQty(station.item_count ?? station.items.length)}</span></summary>
    {open ? <div className="table-wrap"><table>
      <thead><tr><th scope="col"><span className="visually-hidden">Contents</span></th><th scope="col">Item</th><th scope="col" className="num">Qty</th><th scope="col">Character</th></tr></thead>
      <tbody><PagedItems scope={scope} locationId={station.location_id} search={search} revision={revision} /></tbody>
    </table></div> : null}
  </details>
}

export interface AssetLocationsProps {
  solarSystems: SolarSystem[]
  
  empty: ReactNode
  scope?: AssetScope
  search?: string
  revision?: string | null
}


export function AssetLocations({ solarSystems, empty, scope, search, revision }: AssetLocationsProps) {
  if (solarSystems.length === 0) return <p className="panel muted">{empty}</p>
  return (
    <div className="asset-systems">
      {solarSystems.map((system) => (
        <details key={system.solar_system_id ?? system.solar_system_name} className="panel" open>
          <summary>
            <strong>{system.solar_system_name}</strong>{' '}
            <span className="muted">
              {system.stations.length} {system.stations.length === 1 ? 'station' : 'stations'}
            </span>
          </summary>
          {system.stations.map((station) => (
            scope && 'item_count' in station ? <LazyStation key={`${station.location_id}:${revision}`} station={station} scope={scope} search={search} revision={revision} /> :
            <details key={station.location_id || station.location_name} className="station">
              <summary>
                {station.location_name}
                <span className="badge">{formatQty(station.items.length)}</span>
              </summary>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">
                        <span className="visually-hidden">Contents</span>
                      </th>
                      <th scope="col">Item</th>
                      <th scope="col" className="num">
                        Qty
                      </th>
                      <th scope="col">Character</th>
                    </tr>
                  </thead>
                  <tbody>
                    {station.items.map((item) => (
                      <ItemRows key={item.item_id} item={item} />
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          ))}
        </details>
      ))}
    </div>
  )
}
