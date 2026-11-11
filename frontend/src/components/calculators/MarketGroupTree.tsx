import { useState } from 'react'
import { useMarketGroupTree, useTypeSearch } from '../../api/calculators'
import { SearchPicker } from './kit'
import type { components } from '../../api/schema'

export interface GroupNode {
  market_group_id: number
  name: string
  has_types: boolean
  children: GroupNode[]
}

type TypeHit = components['schemas']['MarketTypeResource']

function useTypeHits(q: string) {
  const r = useTypeSearch(q)
  return { data: r.data?.data, isFetching: r.isFetching, isError: r.isError }
}


export function pathTo(nodes: GroupNode[], target: number): number[] | null {
  for (const n of nodes) {
    if (n.market_group_id === target) return [n.market_group_id]
    const below = pathTo(n.children, target)
    if (below) return [n.market_group_id, ...below]
  }
  return null
}


export function MarketGroupTree({
  selected,
  onSelect,
  disabled,
}: {
  selected: number | null
  onSelect: (group: { id: number; name: string }) => void
  disabled?: boolean
}) {
  const tree = useMarketGroupTree()
  const [open, setOpen] = useState<ReadonlySet<number>>(new Set())
  const roots = (tree.data?.data ?? []) as unknown as GroupNode[]

  function toggle(node: GroupNode) {
    const next = new Set(open)
    if (!next.delete(node.market_group_id)) next.add(node.market_group_id)
    setOpen(next)
    if (node.has_types) onSelect({ id: node.market_group_id, name: node.name })
  }

  function reveal(hit: TypeHit) {
    if (hit.market_group_id === null) return
    const path = pathTo(roots, hit.market_group_id)
    if (!path) return
    setOpen(new Set([...open, ...path]))
    onSelect({ id: hit.market_group_id, name: hit.name })
  }

  return (
    <div className="panel">
      <h2>Item browser</h2>
      <SearchPicker<TypeHit>
        label="Search items"
        placeholder="Search items…"
        useResults={useTypeHits}
        getKey={(t) => t.type_id}
        renderItem={(t) => (
          <>
            <img src={t.icon_url} alt="" width={20} height={20} onError={(e) => (e.currentTarget.hidden = true)} />
            {t.name}
          </>
        )}
        onSelect={reveal}
        emptyText="No items found"
      />
      <div className="calc-tree" aria-busy={disabled} style={disabled ? { opacity: 0.6, pointerEvents: 'none' } : undefined}>
        {tree.isPending ? <p className="muted">Loading…</p> : null}
        {tree.isError ? <p className="text-loss">Failed to load market groups.</p> : null}
        {roots.length > 0 ? <ul role="tree">{roots.map((n) => renderNode(n))}</ul> : null}
      </div>
    </div>
  )

  function renderNode(node: GroupNode) {
    const expandable = node.children.length > 0
    const isOpen = open.has(node.market_group_id)
    return (
      <li key={node.market_group_id} role="treeitem" aria-expanded={expandable ? isOpen : undefined} aria-selected={selected === node.market_group_id}>
        <button type="button" className={selected === node.market_group_id ? 'active' : undefined} onClick={() => toggle(node)}>
          <span className="caret" aria-hidden="true">
            {expandable ? (isOpen ? '▾' : '▸') : ''}
          </span>
          {node.name}
        </button>
        {expandable && isOpen ? <ul role="group">{node.children.map((c) => renderNode(c))}</ul> : null}
      </li>
    )
  }
}
