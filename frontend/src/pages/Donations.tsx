import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  useDisableDonations,
  useDonations,
  useDonationStatus,
  useEnableDonations,
  useRefreshDonations,
} from '../api/corp'
import { ErrorNotice, formatIskWhole, WriteError } from '../components/CorpCommon'
import { ScopeWarning } from '../components/ScopeWarning'

const SCOPES = [{ scope: 'esi-wallet.read_corporation_wallets.v1', description: 'Read Corporation Wallets' }]
const MINIMAL_KEY = 'donations_minimal_view'

function readMinimal(): boolean {
  try {
    return localStorage.getItem(MINIMAL_KEY) === '1'
  } catch {
    return false
  }
}

function writeMinimal(on: boolean) {
  try {
    localStorage.setItem(MINIMAL_KEY, on ? '1' : '0')
  } catch {
    
  }
}


export function leaderboardText(donators: Array<{ donator_name: string; total_amount: number }>): string {
  return donators.map((d, i) => `${i + 1} - ${d.donator_name} - ${Math.round(d.total_amount).toLocaleString('en-US')} ISK`).join('\n')
}

function EnablePanel() {
  const status = useDonationStatus()
  const enable = useEnableDonations()
  const [picked, setPicked] = useState('')
  const info = status.data?.data
  if (!info) return null

  return (
    <section className="panel" aria-labelledby="enable-heading">
      <h2 id="enable-heading">Donation tracking not enabled</h2>
      {info.can_enable ? (
        <>
          <p className="muted">Select a character with corporate wallet access to use for donation tracking.</p>
          <p className="muted">
            Enabling this lets every member of your corporation who uses this app see donation summaries from the
            corporation&apos;s visible wallets.
          </p>
          <form
            className="scan-form"
            onSubmit={(e) => {
              e.preventDefault()
              enable.mutate(picked === '' ? undefined : Number(picked))
            }}
          >
            {info.wallet_characters.length > 1 ? (
              <div className="scan-field">
                <label htmlFor="character">Character</label>
                <select id="character" value={picked} onChange={(e) => setPicked(e.target.value)}>
                  {info.wallet_characters.map((c) => (
                    <option key={c.character_id} value={c.character_id}>
                      {c.name} ({c.corporation_name})
                    </option>
                  ))}
                </select>
              </div>
            ) : (
              <p className="muted">
                Using <strong>{info.wallet_characters[0]?.name}</strong> for{' '}
                <strong>{info.wallet_characters[0]?.corporation_name}</strong>.
              </p>
            )}
            <button type="submit" className="button" disabled={enable.isPending}>
              Enable donation tracking
            </button>
          </form>
          <WriteError error={enable.error} fallback="Could not enable donation tracking." />
        </>
      ) : (
        <>
          <p className="muted">No one in your corporation has enabled donation tracking yet.</p>
          <p className="muted">
            To enable it, a corporation member needs a character with the{' '}
            <code>esi-wallet.read_corporation_wallets.v1</code> ESI scope and a corporation role such as Accountant or
            Junior Accountant.
          </p>
          <p className="muted">
            Re-authenticate with EVE SSO and make sure the wallet scope is granted, then return here.
          </p>
        </>
      )}
    </section>
  )
}

export default function Donations() {
  const status = useDonationStatus()
  const [params, setParams] = useSearchParams()
  const divisionParam = Number(params.get('division'))
  const query = {
    date_from: params.get('date_from') ?? undefined,
    date_to: params.get('date_to') ?? undefined,
    division: divisionParam >= 1 && divisionParam <= 7 ? divisionParam : undefined,
  }
  const enabled = status.data?.data.enabled === true
  const donations = useDonations(query, enabled)
  const refresh = useRefreshDonations()
  const disable = useDisableDonations()

  const [draft, setDraft] = useState<{ from?: string; to?: string; division?: string }>({})
  const [minimal, setMinimal] = useState(readMinimal)
  const [copied, setCopied] = useState(false)

  if (status.isPending) return <p>Loading donations…</p>
  if (status.isError) return <p role="alert">Could not load donation tracking.</p>

  const info = status.data.data
  const summary = donations.data?.meta.summary
  const donators = donations.data?.data ?? []

  function submit(e: FormEvent) {
    e.preventDefault()
    const next: Record<string, string> = {}
    const from = draft.from ?? query.date_from
    const to = draft.to ?? query.date_to
    const division = draft.division ?? (query.division ? String(query.division) : '')
    if (from) next.date_from = from
    if (to) next.date_to = to
    if (division) next.division = division
    setParams(next)
  }

  function copyLeaderboard() {
    void navigator.clipboard.writeText(leaderboardText(donators)).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Corporate donations</h1>
          <p className="muted">
            {enabled && info.corporation_name
              ? `Player donations to ${info.corporation_name}, summarised by donator`
              : 'Track player donations to corporate wallets'}
          </p>
        </div>
        {enabled ? (
          <div className="page-actions">
            <button type="button" className="button" disabled={refresh.isPending} onClick={() => refresh.mutate()}>
              {refresh.isPending ? 'Refreshing…' : 'Refresh'}
            </button>
          </div>
        ) : null}
      </div>

      <ScopeWarning required={SCOPES} />
      <WriteError error={refresh.error} fallback="Could not refresh the donations." />

      {!enabled ? <EnablePanel /> : null}

      {enabled ? (
        <>
          {info.enabled_by_me ? (
            <p className="panel">
              <span className="badge badge-fresh">Enabled</span> Wallet access is provided by{' '}
              <strong>{info.provider_character_name ?? 'an unknown character'}</strong>.{' '}
              <button
                type="button"
                className="button"
                disabled={disable.isPending}
                onClick={() => {
                  if (window.confirm('Are you sure? This will disable donation tracking for all corporation members.')) {
                    disable.mutate()
                  }
                }}
              >
                Disable
              </button>
            </p>
          ) : null}
          <WriteError error={disable.error} fallback="Could not disable donation tracking." />

          <form className="filters" onSubmit={submit}>
            <div className="scan-field">
              <label htmlFor="date_from">From</label>
              <input
                id="date_from"
                type="date"
                value={draft.from ?? query.date_from ?? summary?.date_from ?? ''}
                onChange={(e) => setDraft({ ...draft, from: e.target.value })}
              />
            </div>
            <div className="scan-field">
              <label htmlFor="date_to">To</label>
              <input
                id="date_to"
                type="date"
                value={draft.to ?? query.date_to ?? summary?.date_to ?? ''}
                onChange={(e) => setDraft({ ...draft, to: e.target.value })}
              />
            </div>
            {summary && summary.wallet_divisions.length > 1 ? (
              <div className="scan-field">
                <label htmlFor="division">Division</label>
                <select
                  id="division"
                  value={draft.division ?? (query.division ? String(query.division) : '')}
                  onChange={(e) => setDraft({ ...draft, division: e.target.value })}
                >
                  <option value="">All divisions</option>
                  {summary.wallet_divisions.map((d) => (
                    <option key={d.division} value={d.division}>
                      {d.name}
                    </option>
                  ))}
                </select>
              </div>
            ) : null}
            <button type="submit" className="button">
              Filter
            </button>
            <span className="filters-end">
              {donators.length > 0 ? (
                <button type="button" className="button" onClick={copyLeaderboard}>
                  {copied ? 'Copied!' : 'Copy leaderboard'}
                </button>
              ) : null}
              <button
                type="button"
                className="button"
                aria-pressed={minimal}
                onClick={() => {
                  writeMinimal(!minimal)
                  setMinimal(!minimal)
                }}
              >
                Minimal
              </button>
            </span>
          </form>

          {donations.isPending ? (
            <p>Loading donations…</p>
          ) : donations.isError ? (
            <ErrorNotice error={donations.error} what="the donations" unavailable="Donations unavailable:" />
          ) : donators.length === 0 ? (
            <p className="panel muted">No donations found for the selected date range.</p>
          ) : (
            <>
              <p className="panel total-bar">
                <span className="muted">
                  Grand total ({donators.length} {donators.length === 1 ? 'donator' : 'donators'})
                </span>
                <strong className="text-profit">{formatIskWhole(summary?.total ?? 0)}</strong>
              </p>
              <div className={minimal ? 'table-wrap minimal-view' : 'table-wrap'} id="donations-table">
                <table>
                  <thead>
                    <tr>
                      <th className="portrait-col">
                        <span className="visually-hidden">Portrait</span>
                      </th>
                      <th>Donator</th>
                      <th className="num">Donations</th>
                      <th className="num">Total amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {donators.map((d) => (
                      <DonatorRows key={d.donator_id} donator={d} />
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </>
      ) : null}
    </>
  )
}

type Donator = NonNullable<ReturnType<typeof useDonations>['data']>['data'][number]

function DonatorRows({ donator }: { donator: Donator }) {
  return (
    <>
      <tr>
        <td className="portrait-col">
          <img className="portrait" src={donator.portrait_url} alt="" width={40} height={40} loading="lazy" />
        </td>
        <td>{donator.donator_name}</td>
        <td className="num">{donator.donation_count}</td>
        <td className="num text-profit">{formatIskWhole(donator.total_amount)}</td>
      </tr>
      {donator.donations.length > 0 ? (
        <tr className="detail-row">
          <td colSpan={4}>
            <details>
              <summary className="muted small">
                View {donator.donation_count} individual {donator.donation_count === 1 ? 'donation' : 'donations'}
              </summary>
              <table>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th className="num">Amount</th>
                    <th>Wallet</th>
                    <th>Reason</th>
                  </tr>
                </thead>
                <tbody>
                  {donator.donations.map((x, i) => (
                    <tr key={x.id ?? i}>
                      <td className="small">{x.date}</td>
                      <td className="num text-profit">{formatIskWhole(x.amount)}</td>
                      <td className="small muted">{x.wallet_name}</td>
                      <td className="small muted">{x.reason || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </details>
          </td>
        </tr>
      ) : null}
    </>
  )
}
