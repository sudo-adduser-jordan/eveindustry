import { useEffect, useMemo, useState } from 'react'
import {
  bonusesOf,
  calculateManufacturing,
  useAssetStock,
  useBlueprintActivity,
  useBlueprintSearch,
  useCalculateManufacturing,
  useManufacturingSkills,
  useQueueSummary,
  useStructures,
  type BuildProgramRequest,
  type ManufacturingRequest,
  type ManufacturingResult,
  type Structure,
} from '../api/calculators'
import { useMe } from '../api/queries'
import type { components } from '../api/schema'
import { CorporateExportButton, ExportProgramButton } from '../components/calculators/ExportProgram'
import { formatDuration, formatVolume, isk, productName, profitClass } from '../components/calculators/format'
import {
  CopyButton,
  DEFAULT_REGION,
  NumberField,
  PRICE_REGIONS,
  SearchPicker,
  SelectField,
  StructureSelect,
  TypeIcon,
  errorText,
  intIn,
  num,
} from '../components/calculators/kit'
import { JobSummary, MaterialsPanel } from '../components/calculators/ManufacturingResults'
import { formatQty } from '../components/format'
import { ScopeWarning } from '../components/ScopeWarning'

type BlueprintHit = components['schemas']['TypeSummaryResource']

interface Job {
  id: number
  blueprint: BlueprintHit
  productTypeId: number
  productName: string
  runs: number
  me: number
  te: number
  request: ManufacturingRequest
  result: ManufacturingResult
  
  structureSettings: Record<string, unknown>
}

function useBlueprintHits(q: string) {
  const r = useBlueprintSearch(q)
  return { data: r.data?.data, isFetching: r.isFetching, isError: r.isError }
}

const SKILLS =[0, 1, 2, 3, 4, 5].map((n) => [String(n), String(n)] as const)


function stepsOf(result: ManufacturingResult): BuildProgramRequest['steps'] {
  return result.materials.map((m) => ({
    type_id: m.type_id,
    name: m.name,
    quantity_required: m.adjusted_quantity,
    runs_needed: m.component?.runs_needed ?? 0,
    materials: m.component?.materials ?? [],
  }))
}

export default function Manufacturing() {
  const me = useMe()
  const manufacturingStructures = useStructures('manufacturing')
  const reactionStructures = useStructures('reaction')
  const mfgList = manufacturingStructures.data?.data ?? []
  const reactionList = reactionStructures.data?.data ?? []

  const [blueprint, setBlueprint] = useState<BlueprintHit | null>(null)
  const activity = useBlueprintActivity(blueprint?.type_id ?? null)

  const [runs, setRuns] = useState('1')
  const [meLevel, setMeLevel] = useState(0)
  const [teLevel, setTeLevel] = useState(0)
  const [characterId, setCharacterId] = useState<number | null>(null)
  const [industry, setIndustry] = useState('5')
  const [advIndustry, setAdvIndustry] = useState('5')
  
  const [mfgChoice, setMfgChoice] = useState<number | null | undefined>(undefined)
  const [reactionChoice, setReactionChoice] = useState<number | null | undefined>(undefined)
  const [region, setRegion] = useState<string>(DEFAULT_REGION)
  const [buyback, setBuyback] = useState('')

  const mfgId = mfgChoice === undefined ? (mfgList.find((s) => s.is_default)?.id ?? null) : mfgChoice
  const reactionId = reactionChoice === undefined ? (reactionList.find((s) => s.is_default)?.id ?? null) : reactionChoice
  const mfgStructure = mfgList.find((s) => s.id === mfgId)
  const reactionStructure = reactionList.find((s) => s.id === reactionId)

  const skills = useManufacturingSkills(characterId)
  useEffect(() => {
    const loaded = skills.data?.data.skills
    if (loaded) {
      setIndustry(String(loaded.industry))
      setAdvIndustry(String(loaded.advanced_industry))
    }
  }, [skills.data])

  const [jobs, setJobs] = useState<Job[]>([])
  const [current, setCurrent] = useState<Job | null>(null)
  const [nextId, setNextId] = useState(1)
  const [recalcError, setRecalcError] = useState<string | null>(null)
  const [recalculating, setRecalculating] = useState(false)
  const [showCombined, setShowCombined] = useState(false)

  const calc = useCalculateManufacturing()

  const buybackPct = buyback.trim() === '' ? null : num(buyback, 100)

  
  function buildJob(): Omit<Job, 'id' | 'result'> | null {
    const data = activity.data?.data
    if (!blueprint || !data || data.materials.length === 0) return null
    const product = data.products[0]
    const productTypeId = product?.type_id ?? blueprint.type_id
    const runCount = intIn(runs, 1, 100000, 1)
    const mfg = bonusesOf(mfgStructure)
    const request: ManufacturingRequest = {
      blueprint_type_id: blueprint.type_id,
      product_type_id: productTypeId,
      runs: runCount,
      me_level: meLevel,
      te_level: teLevel,
      industry_skill: intIn(industry, 0, 5, 5),
      advanced_industry_skill: intIn(advIndustry, 0, 5, 5),
      structure_me_bonus: mfg.me,
      structure_te_bonus: mfg.te,
      reaction_me_bonus: reactionStructure ? reactionStructure.effective_bonuses.me : null,
      materials: data.materials.map((m) => ({ type_id: m.type_id, name: m.name ?? String(m.type_id), quantity: m.quantity })),
      base_time: data.time,
      portion_size: product?.quantity || 1,
      region_id: Number(region),
      buyback_percentage: buybackPct,
      ...(mfgStructure?.solar_system_id
        ? { solar_system_id: mfgStructure.solar_system_id, tax_rate: mfgStructure.tax_rate ?? 0 }
        : {}),
      ...(reactionStructure?.solar_system_id
        ? { reaction_solar_system_id: reactionStructure.solar_system_id, reaction_tax_rate: reactionStructure.tax_rate ?? 0 }
        : {}),
    }
    return {
      blueprint,
      productTypeId,
      productName: productName(blueprint.name),
      runs: runCount,
      me: meLevel,
      te: teLevel,
      request,
      structureSettings: structureSettings(mfgStructure, reactionStructure),
    }
  }

  function calculate() {
    const job = buildJob()
    if (!job) return
    calc.mutate(job.request, {
      onSuccess: (response) => {
        const shown = { ...job, id: 0, result: response.data }
        setCurrent(shown)
      },
    })
  }

  function addToList() {
    const job = buildJob()
    if (!job) return
    calc.mutate(job.request, {
      onSuccess: (response) => {
        const added = { ...job, id: nextId, result: response.data }
        setNextId(nextId + 1)
        setJobs([...jobs, added])
        setCurrent(added)
        setBlueprint(null)
      },
    })
  }

  
  async function recalculate(patch: Partial<ManufacturingRequest>) {
    if (jobs.length === 0 && !current) return
    setRecalculating(true)
    setRecalcError(null)
    try {
      const redo = async (job: Job): Promise<Job> => {
        const request = { ...job.request, ...patch }
        const response = await calculateManufacturing(request)
        return { ...job, request, result: response.data }
      }
      const next = await Promise.all(jobs.map(redo))
      setJobs(next)
      if (current) {
        const same = next.find((j) => j.id === current.id)
        setCurrent(same ?? (await redo(current)))
      }
    } catch (error) {
      setRecalcError(error instanceof Error ? error.message : 'Could not recalculate')
    } finally {
      setRecalculating(false)
    }
  }

  const summary = useQueueSummary(
    jobs.map((j) => ({
      revenue: j.result.product.total_revenue,
      input_cost: j.result.scenarios.full_buy.material_cost,
      fees: j.result.scenarios.full_buy.total_fees,
    })),
  )

  const combined = useMemo(() => combineMaterials(jobs), [jobs])
  const stock = useAssetStock(
    combined.materials.map((m) => m.type_id),
    showCombined,
  )
  const owned = new Map((stock.data?.data ?? []).map((s) => [s.type_id, s.quantity]))

  const characters = me.data?.data.characters ?? []
  const picked = activity.data?.data
  const canCalculate = blueprint !== null && picked !== undefined && picked.materials.length > 0 && !calc.isPending

  function programFor(list: Job[], corporate: boolean): BuildProgramRequest {
    const single = list.length === 1 ? list[0] : undefined
    const baseName = single ? `${single.productName} ${corporate ? 'Corporate ' : ''}Build Program` : `${corporate ? 'Corporate ' : ''}Manufacturing Build Program (${list.length} items)`
    const steps = list.flatMap((j) => stepsOf(j.result))
    const first = list[0]
    return {
      name: baseName,
      t2_type_id: single ? single.productTypeId : null,
      t2_type_name: single ? single.productName : null,
      steps,
      settings: {
        me_level: first?.me ?? 0,
        te_level: first?.te ?? 0,
        ...(single ? { runs: single.runs } : { sections: list.map((j) => ({ name: j.productName, step_count: j.result.materials.length })) }),
        ...(first?.structureSettings ?? {}),
      },
    }
  }

  return (
    <>
      <h1>Manufacturing calculator</h1>
      <ScopeWarning required={[{ scope: 'esi-skills.read_skills.v1', description: 'Read Character Skills' }]} />
      <div className="calc-layout">
        <form
          className="panel calc-form"
          aria-label="Manufacturing settings"
          onSubmit={(e) => {
            e.preventDefault()
            calculate()
          }}
        >
          <SearchPicker<BlueprintHit>
            label="Search blueprint"
            placeholder="Type a blueprint name…"
            useResults={useBlueprintHits}
            getKey={(b) => b.type_id}
            renderItem={(b) => (
              <>
                <img src={b.icon_url} alt="" width={24} height={24} onError={(e) => (e.currentTarget.hidden = true)} />
                {b.name}
              </>
            )}
            onSelect={setBlueprint}
          />
          {blueprint ? (
            <div className="calc-picked" aria-live="polite">
              <TypeIcon typeId={picked?.products[0]?.type_id ?? blueprint.type_id} size={32} />
              <div>
                <strong>{blueprint.name}</strong>
                <small className="muted">Type ID: {blueprint.type_id}</small>
              </div>
            </div>
          ) : null}
          {blueprint && activity.isPending ? <p className="muted">Loading blueprint data…</p> : null}
          {blueprint && activity.isError ? (
            <p role="alert" className="text-warn">
              No SDE data found for this blueprint. Run <code>php artisan sde:import-blueprints</code> to import blueprint data.
            </p>
          ) : null}
          {picked && picked.materials.length > 0 ? (
            <details open>
              <summary>Blueprint materials (per run)</summary>
              <ul className="calc-queue">
                {picked.materials.map((m) => (
                  <li key={m.type_id}>
                    <TypeIcon typeId={m.type_id} />
                    <span className="pick">{m.name}</span>
                    <strong>×{formatQty(m.quantity)}</strong>
                  </li>
                ))}
              </ul>
              {picked.time > 0 ? (
                <p className="calc-note">
                  Base time: {formatDuration(picked.time)}
                  {(picked.products[0]?.quantity ?? 1) > 1 ? ` · Output: ${picked.products[0]?.quantity} per run` : ''}
                </p>
              ) : null}
            </details>
          ) : null}

          <NumberField label="Number of runs" value={runs} onChange={setRuns} min={1} max={100000} step={1} />
          <div className="scan-field">
            <label htmlFor="mfg-me">
              Material efficiency (ME) <span className="badge">{meLevel}</span>
            </label>
            <input id="mfg-me" type="range" min={0} max={10} value={meLevel} onChange={(e) => setMeLevel(Number(e.target.value))} />
          </div>
          <div className="scan-field">
            <label htmlFor="mfg-te">
              Time efficiency (TE) <span className="badge">{teLevel}</span>
            </label>
            <input id="mfg-te" type="range" min={0} max={20} step={2} value={teLevel} onChange={(e) => setTeLevel(Number(e.target.value))} />
          </div>

          <h3>Character skills</h3>
          <div className="scan-field">
            <label htmlFor="mfg-character">Load from character</label>
            <select
              id="mfg-character"
              value={characterId ?? ''}
              onChange={(e) => setCharacterId(e.target.value === '' ? null : Number(e.target.value))}
            >
              <option value="">Manual entry</option>
              {characters.map((c) => (
                <option key={c.character_id} value={c.character_id}>
                  {c.name}
                </option>
              ))}
            </select>
            {skills.isFetching ? <span className="muted">Loading skills…</span> : null}
            {skills.isError ? <span className="text-loss">Failed to load skills</span> : null}
          </div>
          <SelectField label="Industry" value={industry} onChange={setIndustry} options={SKILLS} />
          <SelectField label="Advanced Industry" value={advIndustry} onChange={setAdvIndustry} options={SKILLS} />

          <h3>Structure &amp; rig bonuses</h3>
          <StructureSelect
            label="Manufacturing structure"
            structures={mfgList}
            value={mfgId}
            onChange={setMfgChoice}
            noneLabel="No station selected"
          />
          <StructureSelect
            label="Reaction structure"
            hint="(for reaction sub-components)"
            structures={reactionList}
            value={reactionId}
            onChange={setReactionChoice}
            noneLabel="No reaction structure"
          />

          <SelectField
            label="Market prices"
            value={region}
            onChange={(value) => {
              setRegion(value)
              void recalculate({ region_id: Number(value) })
            }}
            options={PRICE_REGIONS}
          />
          <div className="scan-field">
            <label htmlFor="mfg-buyback">Corporate buyback %</label>
            <input
              id="mfg-buyback"
              type="number"
              inputMode="decimal"
              min={0}
              max={100}
              step={0.1}
              placeholder="e.g. 90"
              value={buyback}
              onChange={(e) => setBuyback(e.target.value)}
              onBlur={() => void recalculate({ buyback_percentage: buyback.trim() === '' ? null : num(buyback, 100) })}
            />
            <span className="calc-note">Leave blank to use full market price.</span>
          </div>

          <div className="calc-actions">
            <button type="submit" className="button" disabled={!canCalculate}>
              {calc.isPending ? 'Calculating…' : 'Calculate'}
            </button>
            <button type="button" className="button" disabled={!canCalculate} onClick={addToList}>
              Add to list
            </button>
          </div>
          {calc.isError ? (
            <p role="alert" className="text-loss">
              {errorText(calc.error)}
            </p>
          ) : null}

          {jobs.length > 0 ? (
            <section aria-label="Manufacturing queue">
              <div className="calc-panel-head">
                <h2>Manufacturing queue ({jobs.length})</h2>
                <button
                  type="button"
                  className="button"
                  onClick={() => {
                    if (window.confirm('Clear all items from the queue?')) {
                      setJobs([])
                      setShowCombined(false)
                    }
                  }}
                >
                  Clear
                </button>
              </div>
              <ul className="calc-queue">
                {jobs.map((j) => (
                  <li key={j.id} className={current?.id === j.id ? 'active' : undefined}>
                    <button type="button" className="pick" onClick={() => setCurrent(j)}>
                      <TypeIcon typeId={j.productTypeId} />
                      <span>
                        {j.productName}
                        <small className="muted" style={{ display: 'block' }}>
                          {j.runs} runs · ME{j.me} · TE{j.te}
                        </small>
                      </span>
                    </button>
                    <span className={profitClass(j.result.scenarios.full_buy.profit)}>{isk(j.result.scenarios.full_buy.profit)}</span>
                    <button
                      type="button"
                      className="remove"
                      aria-label={`Remove ${j.productName} from the queue`}
                      onClick={() => {
                        setJobs(jobs.filter((x) => x.id !== j.id))
                        if (current?.id === j.id) setCurrent(null)
                      }}
                    >
                      ✕
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </form>

        <div className="calc-results-pane" aria-busy={calc.isPending || recalculating}>
          {recalculating ? <p className="muted">Recalculating prices…</p> : null}
          {recalcError ? (
            <p role="alert" className="panel warn">
              Failed to recalculate prices: {recalcError}
            </p>
          ) : null}

          {jobs.length > 0 ? (
            <section className="panel" aria-labelledby="queue-heading">
              <h2 id="queue-heading">Manufacturing queue: {jobs.length} items</h2>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th scope="col">Item</th>
                      <th scope="col" className="num">
                        Runs
                      </th>
                      <th scope="col" className="num">
                        Output
                      </th>
                      <th scope="col" className="num">
                        Revenue
                      </th>
                      <th scope="col" className="num">
                        Mat. cost
                      </th>
                      <th scope="col" className="num">
                        SCC + build fees
                      </th>
                      <th scope="col" className="num">
                        Profit
                      </th>
                      <th scope="col" className="num">
                        Margin
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {jobs.map((j, i) => {
                      const row = summary.data?.data.items[i]
                      return (
                        <tr key={j.id} className="row-click" onClick={() => setCurrent(j)}>
                          <td>
                            <span className="asset-name">
                              <TypeIcon typeId={j.productTypeId} size={20} />
                              {j.productName}
                            </span>
                          </td>
                          <td className="num">{formatQty(j.runs)}</td>
                          <td className="num">{formatQty(j.result.product.total_output)}</td>
                          <td className="num">{row ? isk(row.revenue) : '…'}</td>
                          <td className="num">{row ? isk(row.input_cost) : '…'}</td>
                          <td className="num text-warn">{row ? isk(row.fees) : '…'}</td>
                          <td className={`num ${row ? profitClass(row.profit) : ''}`}>{row ? isk(row.profit) : '…'}</td>
                          <td className={`num ${row ? profitClass(row.profit) : ''}`}>{row ? `${row.profit_margin}%` : '…'}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                  {summary.data ? (
                    <tfoot>
                      <tr>
                        <th scope="row" colSpan={3}>
                          Combined totals
                        </th>
                        <td className="num text-profit">{isk(summary.data.data.totals.revenue)}</td>
                        <td className="num text-loss">{isk(summary.data.data.totals.input_cost)}</td>
                        <td className="num text-warn">{isk(summary.data.data.totals.fees)}</td>
                        <td className={`num ${profitClass(summary.data.data.totals.profit)}`}>{isk(summary.data.data.totals.profit)}</td>
                        <td className={`num ${profitClass(summary.data.data.totals.profit)}`}>{summary.data.data.totals.profit_margin}%</td>
                      </tr>
                    </tfoot>
                  ) : null}
                </table>
              </div>
              {summary.isError ? <p className="text-loss">Could not total the queue: {summary.error.message}</p> : null}
              <div className="calc-actions">
                {jobs.length >= 2 ? (
                  <button type="button" className="button" onClick={() => setShowCombined(!showCombined)}>
                    {showCombined ? 'Hide combined materials' : 'Combined materials'}
                  </button>
                ) : null}
                <ExportProgramButton
                  label="Export all to build program"
                  build={() => programFor(jobs, false)}
                  hint="Create a build program with all queued items"
                />
                <CorporateExportButton label="Export all to corporate build program" build={() => programFor(jobs, true)} />
              </div>
              <p className="calc-note">Click an item above to see its detailed breakdown below.</p>
              {showCombined && jobs.length >= 2 ? (
                <CombinedMaterials combined={combined} owned={owned} loading={stock.isFetching} />
              ) : null}
            </section>
          ) : null}

          {current ? (
            <>
              <JobSummary result={current.result} />
              <MaterialsPanel result={current.result} fileName={current.productName.replace(/[^a-zA-Z0-9]/g, '_')} />
              <section className="panel" aria-label="Export">
                <div className="calc-actions">
                  <ExportProgramButton
                    label="Export to build program"
                    build={() => programFor([current], false)}
                    hint="Create a build program to track production progress for all components"
                  />
                  <CorporateExportButton label="Export to corporate build program" build={() => programFor([current], true)} />
                </div>
              </section>
            </>
          ) : jobs.length === 0 ? (
            <p className="panel muted">Search for a blueprint to see calculations.</p>
          ) : null}
        </div>
      </div>
    </>
  )
}

function structureSettings(mfg: Structure | undefined, reaction: Structure | undefined): Record<string, unknown> {
  return {
    structure_type: mfg?.structure_type ?? 'none',
    system_security: mfg?.system_security ?? 'highsec',
    ...(reaction
      ? {
          reaction_structure_type: reaction.structure_type,
          reaction_rig_me: reaction.rig_me,
          reaction_system_security: reaction.system_security,
        }
      : {}),
  }
}

interface CombinedMaterial {
  type_id: number
  name: string
  adjusted_quantity: number
  total_cost: number
  is_buildable: boolean
  build_cost: number | null
  volume: number
}


export function combineMaterials(jobs: Array<{ result: ManufacturingResult }>) {
  const map = new Map<number, CombinedMaterial>()
  for (const job of jobs) {
    for (const m of job.result.materials) {
      const buildable = m.component?.is_buildable === true
      const existing = map.get(m.type_id)
      if (existing) {
        existing.adjusted_quantity += m.adjusted_quantity
        existing.total_cost += m.total_cost
        if (buildable) {
          existing.build_cost = (existing.build_cost ?? 0) + (m.component?.build_cost ?? 0)
          existing.is_buildable = true
        }
      } else {
        map.set(m.type_id, {
          type_id: m.type_id,
          name: m.name,
          adjusted_quantity: m.adjusted_quantity,
          total_cost: m.total_cost,
          is_buildable: buildable,
          build_cost: buildable ? (m.component?.build_cost ?? 0) : null,
          volume: m.volume,
        })
      }
    }
  }
  const materials = [...map.values()].sort((a, b) => b.total_cost - a.total_cost)
  const buy = materials.reduce((sum, m) => sum + m.total_cost, 0)
  const build = materials.reduce((sum, m) => sum + (m.is_buildable && m.build_cost !== null ? m.build_cost : m.total_cost), 0)
  const volume = materials.reduce((sum, m) => sum + m.volume * m.adjusted_quantity, 0)
  return { materials, buy, build, volume }
}

function CombinedMaterials({
  combined,
  owned,
  loading,
}: {
  combined: ReturnType<typeof combineMaterials>
  owned: Map<number, number>
  loading: boolean
}) {
  const needed = (m: CombinedMaterial) => Math.max(0, m.adjusted_quantity - (owned.get(m.type_id) ?? 0))
  return (
    <section aria-labelledby="combined-heading">
      <div className="calc-panel-head">
        <h2 id="combined-heading">Combined materials: all queue items</h2>
        <div className="calc-actions">
          <CopyButton
            label="Buy missing"
            onCopy={() => {
              const lines = combined.materials.filter((m) => needed(m) > 0).map((m) => `${m.name} ${needed(m)}`)
              return lines.length > 0 ? lines.join('\n') : null
            }}
          />
          <CopyButton label="Buy all" onCopy={() => combined.materials.map((m) => `${m.name} ${m.adjusted_quantity}`).join('\n')} />
        </div>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th scope="col">Material</th>
              <th scope="col" className="num">
                Total qty
              </th>
              <th scope="col" className="num">
                Volume
              </th>
              <th scope="col" className="num">
                In stock
              </th>
              <th scope="col" className="num">
                Needed
              </th>
              <th scope="col" className="num">
                Buy cost
              </th>
              <th scope="col" className="num">
                Build cost
              </th>
            </tr>
          </thead>
          <tbody>
            {combined.materials.map((m) => {
              const inStock = owned.get(m.type_id) ?? 0
              return (
                <tr key={m.type_id}>
                  <td>
                    <span className="asset-name">
                      <TypeIcon typeId={m.type_id} />
                      {m.name}
                      {m.is_buildable ? <span className="badge badge-small">Buildable</span> : null}
                    </span>
                  </td>
                  <td className="num">{formatQty(m.adjusted_quantity)}</td>
                  <td className="num muted">{formatVolume(m.volume * m.adjusted_quantity)}</td>
                  <td className={`num ${inStock > 0 ? 'text-profit' : 'muted'}`}>{loading && !owned.size ? '…' : formatQty(inStock)}</td>
                  <td className={`num ${needed(m) > 0 ? 'text-warn' : 'text-profit'}`}>{formatQty(needed(m))}</td>
                  <td className="num">{isk(m.total_cost)}</td>
                  <td className="num">{m.is_buildable && m.build_cost !== null ? isk(m.build_cost) : <span className="muted">-</span>}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <dl className="calc-stats">
        <div className="calc-stat">
          <dt>Total volume</dt>
          <dd>{formatVolume(combined.volume)}</dd>
        </div>
        <div className="calc-stat">
          <dt>Total (buy)</dt>
          <dd>{isk(combined.buy)}</dd>
        </div>
        <div className="calc-stat">
          <dt>Total (build, ME 10)</dt>
          <dd>{isk(combined.build)}</dd>
        </div>
      </dl>
    </section>
  )
}
