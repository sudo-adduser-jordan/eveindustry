import { Link } from 'react-router-dom'
import { useContracts, useFreshness, useMe } from '../api/queries'
import { formatQty } from '../components/format'
import { FreshnessBadge } from '../components/FreshnessBadge'

function MarketDataSection() {
  const freshness = useFreshness()
  return (
    <section className="panel" aria-labelledby="data-market-heading">
      <h2 id="data-market-heading">Market data</h2>
      <p className="muted">How old the data behind prices and costs is, per trade hub.</p>
      {freshness.isPending ? <p>Loading market data…</p> : null}
      {freshness.isError ? <p role="alert">Could not load market data.</p> : null}
      {freshness.data ? (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">Region</th>
                <th scope="col">Orders</th>
                <th scope="col">Build costs</th>
                <th scope="col">Global prices</th>
              </tr>
            </thead>
            <tbody>
              {freshness.data.data.map((r) => (
                <tr key={r.region_id}>
                  <th scope="row">{r.region_name}</th>
                  <td>
                    <FreshnessBadge label="Orders" asOf={r.orders_as_of} stale={r.stale} />
                  </td>
                  <td>
                    <FreshnessBadge label="Costs" asOf={r.costs_as_of} stale={r.stale} />
                  </td>
                  <td>
                    <FreshnessBadge label="Global" asOf={r.global_as_of} stale={r.stale} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  )
}

function ContractDataSection() {
  const contracts = useContracts({ per_page: 1 })
  const stats = contracts.data?.meta.stats
  return (
    <section className="panel" aria-labelledby="data-contracts-heading">
      <h2 id="data-contracts-heading">Contract data</h2>
      <p className="muted">
        The public contract snapshot behind appraisals and the <Link to="/tools/contract-browser">contract browser</Link>.
      </p>
      {contracts.isPending ? <p>Loading contract data…</p> : null}
      {contracts.isError ? <p role="alert">Could not load contract data.</p> : null}
      {contracts.data ? (
        <>
          <FreshnessBadge
            label="Contracts"
            asOf={contracts.data.meta.freshness.prices_as_of}
            stale={contracts.data.meta.freshness.stale}
          />
          {stats ? (
            <dl className="stats">
              <div>
                <dt className="muted">Total contracts</dt>
                <dd>{formatQty(stats.total)}</dd>
              </div>
              <div>
                <dt className="muted">Item exchange</dt>
                <dd>{formatQty(stats.item_exchange)}</dd>
              </div>
              <div>
                <dt className="muted">Auction</dt>
                <dd>{formatQty(stats.auction)}</dd>
              </div>
              <div>
                <dt className="muted">Courier</dt>
                <dd>{formatQty(stats.courier)}</dd>
              </div>
            </dl>
          ) : null}
        </>
      ) : null}
    </section>
  )
}

function CharacterDataSection() {
  const me = useMe()
  const characters = me.data?.data.characters ?? []
  return (
    <section className="panel" aria-labelledby="data-characters-heading">
      <h2 id="data-characters-heading">Character data</h2>
      <p className="muted">
        Characters linked to your account and the cached ESI data on them. Manage them under <Link to="/characters">Characters</Link>.
      </p>
      {me.isPending ? <p>Loading character data…</p> : null}
      {me.isError ? <p role="alert">Could not load character data.</p> : null}
      {me.data ? (
        characters.length === 0 ? (
          <p>No characters linked yet.</p>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">Character</th>
                  <th scope="col">Corporation</th>
                  <th scope="col">ESI access</th>
                  <th scope="col">Location</th>
                  <th scope="col">Training</th>
                </tr>
              </thead>
              <tbody>
                {characters.map((c) => (
                  <tr key={c.character_id}>
                    <th scope="row">
                      {c.name} {c.is_main ? <span className="badge badge-fresh">Main</span> : null}
                    </th>
                    <td>{c.corporation_name ?? '—'}</td>
                    <td>
                      <span className={c.is_esi_active ? 'badge badge-fresh' : 'badge badge-stale'}>
                        {c.is_esi_active ? 'Connected' : 'Renewal needed'}
                      </span>
                    </td>
                    <td>{c.cached_location ?? '—'}</td>
                    <td>{c.cached_training ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      ) : null}
    </section>
  )
}

export default function Data() {
  return (
    <>
      <h1>Data</h1>
      <p>How fresh the data behind the app is, and what is linked to your account.</p>
      <MarketDataSection />
      <ContractDataSection />
      <CharacterDataSection />
    </>
  )
}
