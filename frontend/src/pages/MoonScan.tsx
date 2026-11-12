import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../api/client'
import {
  useAnalyseMoons,
  useCorporationSearch,
  useLoadMoonSystem,
  useMe,
  useMoonSystems,
  useSetMoonOccupation,
  type PricingMode,
} from '../api/queries'
import type { components } from '../api/schema'
import { FreshnessBadge } from '../components/FreshnessBadge'
import { formatIsk, formatQty } from '../components/format'
import { REGIONS } from '../components/ScanForm'

type Moon = components['schemas']['MoonResource']
type Freshness = { prices_as_of: string | null; stale: boolean }
type Source = { kind: 'scan' } | { kind: 'stored'; system: string; scannedAt: string | null }

const MODES: Array<[PricingMode, string, string]> = [
  ['raw', 'Raw ore prices', 'Unit price = sell price of 1 raw ore unit. Units based on 10 M m³.'],
  ['compressed', 'Compressed ore prices', 'Unit price = sell price of the compressed variant. Units based on 10 M m³ of raw ore.'],
  ['refined', 'Refined minerals (85% efficiency)', 'Unit price = mineral value at 85% reprocessing efficiency. Units based on 10 M m³.'],
]

const MODE_LABEL: Record<string, string> = { raw: 'Raw ore', compressed: 'Compressed', refined: 'Refined (85%)' }

const GRADES: Array<[string, string, string]> = [
  ['S', 'R64', 'Exceptional'],
  ['A', 'R32', 'Rare'],
  ['B', 'R16', 'Uncommon'],
  ['C', 'R8', 'Common'],
  ['D', 'R4', 'Ubiquitous'],
]

const TIER_LABEL: Record<number, string> = { 5: 'R64', 4: 'R32', 3: 'R16', 2: 'R8', 1: 'R4', 0: '?' }


export function formatMoonIsk(value: number): string {
  if (value >= 1e12) return `${(value / 1e12).toFixed(2)}T`
  return formatIsk(value)
}


export function summarise(moons: Moon[], hideOccupied: boolean) {
  const visible = hideOccupied ? moons.filter((m) => !m.occupied) : moons
  return {
    visible,
    hidden: moons.length - visible.length,
    byGrade: (grade: string) => visible.filter((m) => m.grade === grade).length,
  }
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

function CorporationPicker({ onPick, onCancel }: { onPick: (id: number, name: string) => void; onCancel: () => void }) {
  const [text, setText] = useState('')
  const [q, setQ] = useState('')
  
  useEffect(() => {
    const timer = setTimeout(() => setQ(text.trim()), 350)
    return () => clearTimeout(timer)
  }, [text])
  const search = useCorporationSearch(q)
  const results = search.data?.data ?? []

  return (
    <div className="occupy-form">
      <label className="visually-hidden" htmlFor="corp-search">
        Search corporation name
      </label>
      <input
        id="corp-search"
        type="search"
        autoFocus
        autoComplete="off"
        placeholder="Search corporation name…"
        value={text}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Escape') onCancel()
        }}
      />
      <button type="button" className="button" onClick={onCancel}>
        Cancel
      </button>
      {q.length >= 2 ? (
        <ul className="corp-results" aria-label="Corporation suggestions">
          {search.isPending ? <li className="muted">Searching…</li> : null}
          {search.isError ? <li className="muted">Search failed — try again</li> : null}
          {search.isSuccess && results.length === 0 ? <li className="muted">No results found</li> : null}
          {results.map((c) => (
            <li key={c.corporation_id}>
              <button type="button" className="option" onClick={() => onPick(c.corporation_id, c.name)}>
                {c.name} <span className="muted">[{c.ticker}]</span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

function MoonCard({ moon, rank, onOccupation }: { moon: Moon; rank: number; onOccupation: (moon: Moon, corp: { id: number; name: string } | null) => Promise<void> }) {
  const [picking, setPicking] = useState(false)
  const [failed, setFailed] = useState(false)
  const maxIsk = Math.max(0.001, ...moon.ores.map((o) => o.value_contribution))
  const medal = rank <= 3 ? ` rank-${rank}` : ''

  async function save(corp: { id: number; name: string } | null) {
    setFailed(false)
    try {
      await onOccupation(moon, corp)
      setPicking(false)
    } catch {
      setFailed(true)
    }
  }

  return (
    <li className={`moon-card${medal}`}>
      <div className="moon-head">
        <span className="badge">#{rank}</span>
        <span className={`grade grade-${moon.grade ?? 'D'}`}>{moon.grade ?? 'D'}</span>
        <strong className="moon-name">{moon.name}</strong>
        <span className="moon-value">
          {moon.total_value > 0 ? <strong>{formatMoonIsk(moon.total_value)} ISK</strong> : <span className="muted">No price data</span>}
        </span>
        {moon.id !== null ? (
          moon.occupied && moon.occupying_corporation_name ? (
            <span className="badge badge-stale" title={`Occupied by ${moon.occupying_corporation_name}`}>
              {moon.occupying_corporation_name}
              <button type="button" className="toggle" aria-label="Remove occupation" onClick={() => save(null)}>
                ✕
              </button>
            </span>
          ) : (
            <button type="button" className="button small" onClick={() => setPicking((v) => !v)}>
              Occupied
            </button>
          )
        ) : null}
      </div>
      <ul className="ores">
        {moon.ores.map((ore) => (
          <li key={ore.type_id} className="ore-row">
            <span className="ore-name">{ore.name}</span>
            <span className={`tier tier-${ore.tier}`}>{TIER_LABEL[ore.tier] ?? '?'}</span>
            <span className="num">{(ore.quantity * 100).toFixed(1)}%</span>
            <span className="num muted">{formatQty(Math.round(ore.ore_units ?? 0))} units</span>
            <span className="bar" role="img" aria-label={`${Math.round((ore.value_contribution / maxIsk) * 100)}% of the best ore`}>
              <span className={`tier-fill tier-${ore.tier}`} style={{ width: `${Math.round((ore.value_contribution / maxIsk) * 100)}%` }} />
            </span>
            <span className="num">{ore.price_per_unit > 0 ? `${formatMoonIsk(ore.price_per_unit)}/u` : '—'}</span>
            <span className="num">{ore.value_contribution > 0 ? `${formatMoonIsk(ore.value_contribution)} ISK` : '—'}</span>
          </li>
        ))}
      </ul>
      {picking ? <CorporationPicker onPick={(id, name) => save({ id, name })} onCancel={() => setPicking(false)} /> : null}
      {failed ? (
        <p role="alert" className="small text-loss">
          Failed to save — try again
        </p>
      ) : null}
    </li>
  )
}

export default function MoonScan() {
  const systems = useMoonSystems()
  const analyse = useAnalyseMoons()
  const load = useLoadMoonSystem()
  const occupation = useSetMoonOccupation()
  const me = useMe()
  const isDemo = me.data?.data.demo === true

  const [text, setText] = useState('')
  const [region, setRegion] = useState<(typeof REGIONS)[number][0]>('10000002')
  const [mode, setMode] = useState<PricingMode>('raw')
  const [pick, setPick] = useState('')
  const [loadMode, setLoadMode] = useState<PricingMode | 'stored'>('stored')
  const [moons, setMoons] = useState<Moon[] | null>(null)
  const [source, setSource] = useState<Source>({ kind: 'scan' })
  const [shownMode, setShownMode] = useState<string>('raw')
  const [freshness, setFreshness] = useState<Freshness | null>(null)
  const [warnings, setWarnings] = useState<string[]>([])
  const [message, setMessage] = useState<string | null>(null)
  const [hideOccupied, setHideOccupied] = useState(false)

  const names = useMemo(() => (systems.data?.data ?? []).map((s) => s.name), [systems.data])
  const matches = pick.trim() === '' ? [] : names.filter((n) => n.toLowerCase().includes(pick.trim().toLowerCase())).slice(0, 20)
  const exact = names.includes(pick)
  const busy = analyse.isPending || load.isPending

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (text.trim() === '') return
    setMessage(null)
    try {
      const res = await analyse.mutateAsync({ scan_data: text.trim(), region_id: region, pricing_mode: mode })
      if (res.data.moons.length === 0) {
        setMoons(null)
        setMessage(res.data.errors.length > 0 ? res.data.errors.join(' ') : 'No moons found. Please check the pasted data.')
        return
      }
      setMoons(res.data.moons)
      setSource({ kind: 'scan' })
      setShownMode(res.data.pricing_mode)
      setFreshness(res.meta.freshness)
      setWarnings(res.data.errors)
      setHideOccupied(false)
    } catch (err) {
      setMoons(null)
      setMessage(errorMessage(err, 'Failed to analyse moon data. Please try again.'))
    }
  }

  async function loadSystem(system: string, pricing: PricingMode | 'stored') {
    setMessage(null)
    try {
      const res = await load.mutateAsync({ system, pricing_mode: pricing === 'stored' ? undefined : pricing })
      if (res.data.length === 0) {
        setMoons(null)
        setMessage('No moons found for this system.')
        return
      }
      setMoons(res.data)
      setSource({ kind: 'stored', system: res.meta.system.name, scannedAt: res.meta.system.scanned_at })
      setShownMode(res.meta.system.pricing_mode)
      setFreshness(res.meta.freshness)
      setWarnings([])
      setHideOccupied(false)
    } catch (err) {
      setMoons(null)
      setMessage(errorMessage(err, 'Failed to load system scan. Please try again.'))
    }
  }

  async function setOccupation(moon: Moon, corp: { id: number; name: string } | null) {
    if (moon.id === null) return
    await occupation.mutateAsync(
      corp ? { moonId: moon.id, occupied: true, corporation_id: corp.id, corporation_name: corp.name } : { moonId: moon.id, occupied: false },
    )
    setMoons((list) =>
      (list ?? []).map((m) =>
        m.id === moon.id
          ? { ...m, occupied: corp !== null, occupying_corporation_id: corp?.id ?? null, occupying_corporation_name: corp?.name ?? null }
          : m,
      ),
    )
  }

  const stats = summarise(moons ?? [], hideOccupied)

  const demoLoaded = useRef(false)
  useEffect(() => {
    if (!isDemo || demoLoaded.current || moons !== null || message !== null || busy) return
    const first = (systems.data?.data ?? [])[0]
    if (!systems.isSuccess || !first) return
    const timer = setTimeout(() => {
      demoLoaded.current = true
      void loadSystem(first.name, 'stored')
    }, 0)
    return () => clearTimeout(timer)
  }, [isDemo, systems.isSuccess, systems.data, moons, message, busy])

  return (
    <>
      <h1>Moon scan analyser</h1>
      <p className="muted">
        Paste moon scan data from the in-game probe scanner window to grade moons by market value. ISK values are estimated from
        10 million m³ of total ore per moon (2 week pull). Moons are graded S / A / B / C / D by the rarity tier of their highest-value
        ore. The top three moons are highlighted in gold, silver and bronze.
      </p>
      <div className="scan-layout">
        <div className="scan-form">
          <form className="panel scan-form" onSubmit={submit}>
            <div className="scan-field">
              <label htmlFor="scan-input">Paste moon scan data</label>
              <textarea
                id="scan-input"
                rows={12}
                value={text}
                onChange={(e) => setText(e.target.value)}
                placeholder={'Moon\tMoon Product\tQuantity\tOre TypeID\tSolarSystemID\tPlanetID\tMoonID'}
              />
            </div>
            <div className="scan-field">
              <label htmlFor="scan-region">Market hub</label>
              <select id="scan-region" value={region} onChange={(e) => setRegion(e.target.value as typeof region)}>
                {REGIONS.map(([id, name]) => (
                  <option key={id} value={id}>
                    {name}
                  </option>
                ))}
              </select>
            </div>
            <fieldset className="scan-field">
              <legend className="small">Value basis</legend>
              {MODES.map(([value, label]) => (
                <label key={value} className="radio">
                  <input type="radio" name="pricing-mode" checked={mode === value} onChange={() => setMode(value)} /> {label}
                </label>
              ))}
              <span className="muted small">{MODES.find(([v]) => v === mode)?.[2]}</span>
            </fieldset>
            <button type="submit" className="button" disabled={busy || text.trim() === ''}>
              {analyse.isPending ? 'Analysing…' : 'Analyse moons'}
            </button>
          </form>

          <div className="panel scan-form">
            <div className="scan-field">
              <label htmlFor="saved-system">Load previously scanned system</label>
              <input
                id="saved-system"
                type="search"
                autoComplete="off"
                placeholder="Type system name…"
                value={pick}
                onChange={(e) => setPick(e.target.value)}
              />
              {matches.length > 0 && !exact ? (
                <ul className="corp-results" aria-label="Scanned systems">
                  {matches.map((n) => (
                    <li key={n}>
                      <button type="button" className="option" onClick={() => setPick(n)}>
                        {n}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
              {systems.isSuccess && names.length === 0 ? <span className="muted small">Your corporation has no stored scans yet.</span> : null}
            </div>
            <fieldset className="scan-field">
              <legend className="small">Load with pricing</legend>
              {(['stored', 'raw', 'compressed', 'refined'] as const).map((value) => (
                <label key={value} className="radio small">
                  <input type="radio" name="load-pricing-mode" checked={loadMode === value} onChange={() => setLoadMode(value)} />{' '}
                  {value === 'stored' ? 'Use stored pricing' : MODE_LABEL[value]}
                </label>
              ))}
            </fieldset>
            <button type="button" className="button" disabled={!exact || busy} onClick={() => loadSystem(pick, loadMode)}>
              {load.isPending ? 'Loading…' : 'Load saved scan'}
            </button>
          </div>
        </div>

        <div>
          {busy ? <p>Analysing moons…</p> : null}
          {!busy && message ? (
            <div className="panel warn" role="alert">
              {message}
            </div>
          ) : null}
          {!busy && !message && moons === null ? <p className="muted">Paste moon scan data and click Analyse moons to see results.</p> : null}
          {!busy && moons !== null ? (
            <>
              {source.kind === 'stored' ? (
                <div className="panel small">
                  Saved scan for <strong>{source.system}</strong> — {source.scannedAt ? new Date(source.scannedAt).toLocaleString() : 'unknown date'} —{' '}
                  {MODE_LABEL[shownMode] ?? shownMode} prices
                  <div className="reprice">
                    Re-analyse as:{' '}
                    {(['raw', 'compressed', 'refined'] as const).map((value) => (
                      <button
                        key={value}
                        type="button"
                        className={shownMode === value ? 'button active' : 'button'}
                        aria-pressed={shownMode === value}
                        onClick={() => loadSystem(source.system, value)}
                      >
                        {MODE_LABEL[value]}
                      </button>
                    ))}
                  </div>
                </div>
              ) : null}
              <dl className="stats">
                  <div className="panel">
                    <dd>{stats.visible.length}</dd>
                    <dt className="muted small">Moons scanned</dt>
                  </div>
                  {(['S', 'A', 'B'] as const).map((g) => (
                    <div key={g} className="panel">
                      <dd className={`grade-text-${g}`}>{stats.byGrade(g)}</dd>
                      <dt className="muted small">
                        Grade {g} ({GRADES.find(([k]) => k === g)?.[1]})
                      </dt>
                    </div>
                  ))}
                </dl>
              <div className="legend small">
                {GRADES.map(([g, tier, label]) => (
                  <span key={g}>
                    <span className={`grade grade-${g}`}>{g}</span> {tier} – {label}
                  </span>
                ))}
              </div>
              <div className="filter-bar">
                <label className="radio">
                  <input type="checkbox" checked={hideOccupied} onChange={(e) => setHideOccupied(e.target.checked)} /> Hide occupied moons
                </label>
                {hideOccupied && stats.hidden > 0 ? (
                  <span className="muted small">
                    {stats.hidden} occupied moon{stats.hidden === 1 ? '' : 's'} hidden
                  </span>
                ) : null}
                {freshness ? <FreshnessBadge asOf={freshness.prices_as_of} stale={freshness.stale} label="Prices" /> : null}
              </div>
              {warnings.length > 0 ? (
                <div className="panel warn small" role="status">
                  Parse warnings: {warnings.join('; ')}
                </div>
              ) : null}
              <ol className="moons">
                {stats.visible.map((m, i) => (
                  <MoonCard key={m.id ?? m.name} moon={m} rank={i + 1} onOccupation={setOccupation} />
                ))}
              </ol>
            </>
          ) : null}
        </div>
      </div>
    </>
  )
}
