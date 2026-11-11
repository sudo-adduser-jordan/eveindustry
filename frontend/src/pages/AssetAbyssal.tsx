import { useAbyssalItems } from '../api/queries'
import type { components } from '../api/schema'
import { RefreshBar, useAssetRefresh } from '../components/AssetRefresh'
import { typeIconUrl, iconFallback } from '../components/typeIcon'

type Item = components['schemas']['AbyssalItemResource']


export function scoreTier(score: number): 'best' | 'good' | 'bad' {
  return score > 10 ? 'best' : score >= 0 ? 'good' : 'bad'
}

function ItemCard({ item, rank, mutaplasmid }: { item: Item; rank: number; mutaplasmid: string }) {
  const tier = scoreTier(item.score)
  return (
    <li className="abyssal-card">
      <div className="abyssal-head">
        <img
          src={typeIconUrl(item.type_id, 64)}
          alt=""
          width={38}
          height={38}
          loading="lazy"
          onError={iconFallback}
        />
        <div className="abyssal-title">
          <strong>{item.type_name}</strong>
          {item.mutator_type_id ? <small className="muted">{mutaplasmid} Mutaplasmid</small> : null}
          <small className="muted">{item.character_name}</small>
          <small className="muted">
            {item.location_name}
            {item.container_name ? ` · ${item.container_name}` : ''}
          </small>
        </div>
        <div className="abyssal-rank">
          <span className="badge" title={`Rank ${rank}`}>
            #{rank}
          </span>
          <span className={`badge score-${tier}`} title="Quality score">
            {item.score > 0 ? '+' : ''}
            {item.score}
          </span>
        </div>
      </div>
      {item.attributes.length === 0 ? (
        <p className="muted small">No attribute changes detected.</p>
      ) : (
        <ul className="abyssal-attrs">
          {item.attributes.map((attr) => (
            <li key={attr.name}>
              <span className="muted small">{attr.name}</span>
              <span>
                {attr.value} <span className={attr.is_good ? 'text-profit' : 'text-loss'}>{attr.change}</span>
              </span>
              <span className="bar" aria-hidden="true">
                <span
                  className={attr.is_good ? 'bar-good' : 'bar-bad'}
                  style={{ width: `${Math.min(100, Math.abs(attr.pct_change ?? 0) * 2)}%` }}
                />
              </span>
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}

export default function AssetAbyssal() {
  const refresh = useAssetRefresh()
  const abyssal = useAbyssalItems(refresh.refetchInterval)

  if (abyssal.isPending) {
    return <p>Loading abyssal items… this asks ESI about every module, so the first load can take a while.</p>
  }
  if (abyssal.isError) {
    return (
      <>
        <h1>Abyssal items</h1>
        <p role="alert">Could not load your abyssal items.</p>
      </>
    )
  }

  const groups = abyssal.data.data
  const itemCount = groups.reduce((n, g) => n + g.items.length, 0)
  return (
    <>
      <div className="page-head">
        <h1>Abyssal items</h1>
        <RefreshBar refresh={refresh} lastUpdated={abyssal.data.meta.last_updated} dataUpdatedAt={abyssal.dataUpdatedAt} />
      </div>
      <p className="muted">Mutated modules from your character assets, ranked by roll quality.</p>

      {groups.length === 0 ? (
        <p className="panel">
          No abyssal items found. Abyssal modules appear once your assets are loaded; make sure your characters have the{' '}
          <code>esi-assets.read_assets.v1</code> scope and have been refreshed recently.
        </p>
      ) : (
        <>
          <p className="muted small">
            {groups.length} module {groups.length === 1 ? 'type' : 'types'}, {itemCount} abyssal{' '}
            {itemCount === 1 ? 'item' : 'items'}, best roll first. <span className="text-profit">Green</span> is a
            beneficial change, <span className="text-loss">red</span> a detrimental one.
          </p>
          {groups.map((group) => (
            <details key={group.source_type_id} className="panel" open>
              <summary>
                <strong>{group.source_type_name}</strong>{' '}
                <span className="badge">
                  {group.items.length} {group.items.length === 1 ? 'variant' : 'variants'}
                </span>
              </summary>
              <ul className="abyssal-grid">
                {group.items.map((item, i) => (
                  <ItemCard key={item.item_id} item={item} rank={i + 1} mutaplasmid={group.source_type_name} />
                ))}
              </ul>
            </details>
          ))}
        </>
      )}
    </>
  )
}
