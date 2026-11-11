import { useState } from 'react'
import { useCostIndices, useStructures, type Structure } from '../api/calculators'
import type { components } from '../api/schema'
import {
  useCalculateReactionPlan,
  useDeleteReactionPlan,
  useFormulaSearch,
  useFormulaStock,
  useReactionPlans,
  useReactionStock,
  useSaveReactionPlan,
  type ReactionPlanBody,
  type ReactionPlanRequest,
  type ReactionPlanResult,
  type ReactionProgramRequest,
  type SavedReactionPlan,
} from '../api/reactions'
import { CorporateReactionExport, ReactionExport } from '../components/calculators/ExportReactionProgram'
import { formatVolume, isk, profitClass, signedIsk } from '../components/calculators/format'
import {
  CopyButton,
  DEFAULT_REGION,
  NumberField,
  PRICE_REGIONS,
  SearchPicker,
  SelectField,
  Stat,
  StructureSelect,
  TypeIcon,
  errorText,
  intIn,
  pick,
} from '../components/calculators/kit'
import { formatQty } from '../components/format'

type FormulaHit = components['schemas']['TypeSummaryResource']

interface Entry {
  formula_type_id: number
  formula_name: string
  icon_url: string
  slots: number
  runs_per_slot: number
}

const PRESETS = [
  [120, '1 week'],
  [240, '2 weeks'],
  [480, '4 weeks'],
] as const

function useFormulaHits(q: string) {
  const r = useFormulaSearch(q)
  return { data: r.data?.data, isFetching: r.isFetching, isError: r.isError }
}


function structureParams(structure: Structure | undefined) {
  return {
    structure_type: structure?.structure_type === 'tatara' ? ('tatara' as const) : ('athanor' as const),
    rig_tier: pick(structure?.rig_me, ['none', 't1', 't2'] as const, 'none'),
    security_space: structure?.system_security === 'nullsec' ? ('nullsec' as const) : ('lowsec' as const),
    facility_tax: structure?.tax_rate ?? 0,
  }
}

export default function ReactionPlanner() {
  const structures = useStructures('reaction')
  const list = structures.data?.data ?? []
  const [choice, setChoice] = useState<number | null | undefined>(undefined)
  const structureId = choice === undefined ? (list.find((s) => s.is_default)?.id ?? null) : choice
  const structure = list.find((s) => s.id === structureId)
  const costIndices = useCostIndices(structure?.solar_system_id ?? null)
  const sci = costIndices.data ? Math.round(costIndices.data.data.cost_indices.reaction * 10000) / 100 : 0

  const [region, setRegion] = useState<string>(DEFAULT_REGION)
  const [defaultRuns, setDefaultRuns] = useState('240')
  const [entries, setEntries] = useState<Entry[]>([])
  const [planName, setPlanName] = useState('')
  const [planId, setPlanId] = useState<number | null>(null)
  const [selectedPlan, setSelectedPlan] = useState('')
  const [notice, setNotice] = useState<string | null>(null)

  const plans = useReactionPlans()
  const savePlan = useSaveReactionPlan()
  const deletePlan = useDeleteReactionPlan()
  const calc = useCalculateReactionPlan()
  const [result, setResult] = useState<ReactionPlanResult | null>(null)
  const [calcPlan, setCalcPlan] = useState<Entry[]>([])

  const savedPlans: SavedReactionPlan[] = plans.data?.data ?? []

  function planBody(): ReactionPlanBody {
    return {
      name: planName.trim(),
      entries: entries.map((e) => ({ ...e })),
      default_runs_per_slot: intIn(defaultRuns, 1, 100000, 240),
      ...structureParams(structure),
      station_id: null,
      solar_system_id: structure?.solar_system_id ?? null,
      price_region_id: Number(region),
    }
  }

  function loadPlan(plan: SavedReactionPlan) {
    setPlanId(plan.id)
    setPlanName(plan.name)
    setDefaultRuns(String(plan.default_runs_per_slot || 240))
    if (plan.price_region_id) setRegion(String(plan.price_region_id))
    setEntries(plan.entries.map((e) => ({ formula_type_id: e.formula_type_id, formula_name: e.formula_name, icon_url: e.icon_url ?? '', slots: e.slots, runs_per_slot: e.runs_per_slot })))
    setNotice(`Plan "${plan.name}" loaded`)
  }

  function save(update: boolean) {
    const body = planBody()
    if (body.name === '' || entries.length === 0) return
    savePlan.mutate(
      { id: update ? planId : null, body },
      {
        onSuccess: (response) => {
          setPlanId(response.data.id)
          setSelectedPlan(String(response.data.id))
          setNotice(`Plan "${body.name}" ${update ? 'updated' : 'saved'}`)
        },
      },
    )
  }

  function remove() {
    const plan = savedPlans.find((p) => String(p.id) === selectedPlan)
    if (!plan || !window.confirm(`Delete plan "${plan.name}"?`)) return
    deletePlan.mutate(plan.id, {
      onSuccess: () => {
        if (planId === plan.id) setPlanId(null)
        setSelectedPlan('')
        setNotice(`Plan "${plan.name}" deleted`)
      },
    })
  }

  function updateEntry(index: number, patch: Partial<Entry>) {
    setEntries(entries.map((e, i) => (i === index ? { ...e, ...patch } : e)))
  }

  function calculate() {
    if (entries.length === 0) return
    const request: ReactionPlanRequest = {
      entries: entries.map((e) => ({ formula_type_id: e.formula_type_id, formula_name: e.formula_name, slots: e.slots, runs_per_slot: e.runs_per_slot })),
      ...structureParams(structure),
      system_cost_index: structure?.solar_system_id ? sci : 0,
      price_region_id: Number(region),
    }
    calc.mutate(request, {
      onSuccess: (response) => {
        setResult(response.data)
        setCalcPlan(entries)
      },
    })
  }

  function program(corporate: boolean): ReactionProgramRequest {
    const settings = structureParams(structure)
    return {
      name: `${planName.trim() || 'Reaction Plan'} ${corporate ? 'Corporate ' : ''}Build Program`,
      entries: calcPlan.map((e) => ({ formula_type_id: e.formula_type_id, formula_name: e.formula_name, slots: e.slots, runs_per_slot: e.runs_per_slot })),
      settings: { reaction_structure_type: settings.structure_type, reaction_rig_me: settings.rig_tier, reaction_system_security: settings.security_space },
    }
  }

  const canSave = planName.trim() !== '' && entries.length > 0 && !savePlan.isPending

  return (
    <>
      <h1>Reaction planner</h1>
      <p>Plan multi-reaction cycles. Add composites, set slots and runs, then calculate to see the raw materials to buy.</p>
      <div className="calc-layout calc-layout-wide">
        <form
          className="panel calc-form"
          aria-label="Reaction plan"
          onSubmit={(e) => {
            e.preventDefault()
            calculate()
          }}
        >
          <h2>Saved plans</h2>
          <div className="field-row">
            <select aria-label="Saved plans" value={selectedPlan} onChange={(e) => setSelectedPlan(e.target.value)} style={{ flex: 1, minWidth: 0 }}>
              <option value="">New plan</option>
              {savedPlans.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
            <button type="button" className="button" disabled={selectedPlan === ''} onClick={() => { const p = savedPlans.find((x) => String(x.id) === selectedPlan); if (p) loadPlan(p) }}>
              Load
            </button>
            <button type="button" className="button" disabled={selectedPlan === ''} onClick={remove}>
              Delete
            </button>
          </div>
          <div className="field-row">
            <input aria-label="Plan name" placeholder="Plan name…" maxLength={255} value={planName} onChange={(e) => setPlanName(e.target.value)} style={{ flex: 1, minWidth: 0 }} />
            <button type="button" className="button" disabled={!canSave} onClick={() => save(false)}>
              Save
            </button>
            {planId !== null ? (
              <button type="button" className="button" disabled={!canSave} onClick={() => save(true)}>
                Update
              </button>
            ) : null}
          </div>
          {notice ? (
            <p role="status" className="calc-note">
              {notice}
            </p>
          ) : null}
          {savePlan.isError ? (
            <p role="alert" className="text-loss">
              {errorText(savePlan.error)}
            </p>
          ) : null}

          <NumberField label="Cycle length (runs per slot)" value={defaultRuns} onChange={setDefaultRuns} min={1} max={100000} step={1} />
          <div className="calc-actions">
            {PRESETS.map(([runs, label]) => (
              <button
                key={runs}
                type="button"
                className="button"
                onClick={() => {
                  setDefaultRuns(String(runs))
                  setEntries(entries.map((e) => ({ ...e, runs_per_slot: runs })))
                }}
              >
                {label}
              </button>
            ))}
          </div>

          <h3>Structure settings</h3>
          <StructureSelect label="Station" structures={list} value={structureId} onChange={setChoice} noneLabel="No station selected" />
          {structure?.solar_system_id ? (
            <p className="calc-note">
              System cost index: <strong>{costIndices.isPending ? 'loading…' : `${sci}%`}</strong>
            </p>
          ) : null}
          <SelectField label="Market prices" value={region} onChange={setRegion} options={PRICE_REGIONS} />

          <SearchPicker<FormulaHit>
            label="Add reaction formula"
            placeholder="Search reaction formula…"
            useResults={useFormulaHits}
            getKey={(f) => f.type_id}
            renderItem={(f) => (
              <>
                <img src={f.icon_url} alt="" width={24} height={24} onError={(e) => (e.currentTarget.hidden = true)} />
                {f.name}
              </>
            )}
            onSelect={(f) =>
              setEntries([...entries, { formula_type_id: f.type_id, formula_name: f.name, icon_url: f.icon_url, slots: 1, runs_per_slot: intIn(defaultRuns, 1, 100000, 240) }])
            }
          />

          {entries.length === 0 ? <p className="calc-note">Search and add reaction formulas above.</p> : null}
          <ul className="calc-queue">
            {entries.map((e, i) => (
              <li key={`${e.formula_type_id}-${i}`} style={{ flexWrap: 'wrap' }}>
                <TypeIcon typeId={e.formula_type_id} size={28} />
                <strong style={{ flex: 1, minWidth: 0 }}>{e.formula_name}</strong>
                <button type="button" className="remove" aria-label={`Remove ${e.formula_name}`} onClick={() => setEntries(entries.filter((_, j) => j !== i))}>
                  ✕
                </button>
                <label>
                  <span className="muted small">Slots </span>
                  <input className="num-input" type="number" min={1} max={1000} value={e.slots} aria-label={`Slots for ${e.formula_name}`} onChange={(ev) => updateEntry(i, { slots: intIn(ev.target.value, 1, 1000, 1) })} />
                </label>
                <label>
                  <span className="muted small">Runs/slot </span>
                  <input className="num-input" type="number" min={1} max={100000} value={e.runs_per_slot} aria-label={`Runs per slot for ${e.formula_name}`} onChange={(ev) => updateEntry(i, { runs_per_slot: intIn(ev.target.value, 1, 100000, 1) })} />
                </label>
                <span className="small">
                  Total runs <strong className="text-warn">{formatQty(e.slots * e.runs_per_slot)}</strong>
                </span>
              </li>
            ))}
          </ul>

          <button type="submit" className="button" disabled={entries.length === 0 || calc.isPending}>
            {calc.isPending ? 'Calculating…' : 'Calculate full chain'}
          </button>
          {calc.isError ? (
            <p role="alert" className="text-loss">
              {errorText(calc.error)}
            </p>
          ) : null}
        </form>

        <div className="calc-results-pane" aria-busy={calc.isPending}>
          {result ? (
            <PlanResults
              result={result}
              regionLabel={PRICE_REGIONS.find(([id]) => id === region)?.[1] ?? 'Jita'}
              exportButtons={
                <>
                  <ReactionExport label="Export to build program" build={() => program(false)} />
                  <CorporateReactionExport label="Export to corporate build program" build={() => program(true)} />
                </>
              }
            />
          ) : (
            <p className="panel muted">Add reactions and calculate to see what to buy.</p>
          )}
        </div>
      </div>
    </>
  )
}

export function PlanResults({ result, regionLabel, exportButtons }: { result: ReactionPlanResult; regionLabel: string; exportButtons: React.ReactNode }) {
  const materialIds = result.raw_materials.map((m) => m.type_id)
  const stock = useReactionStock(materialIds)
  const owned = new Map((stock.data?.data ?? []).map((s) => [s.type_id, s.quantity]))
  const formulaIds = [...new Set([...result.composite_outputs.map((o) => o.formula_type_id), ...result.intermediate_slots.map((s) => s.formula_type_id)])]
  const formulaStock = useFormulaStock(formulaIds)
  const formulaOwned = new Map((formulaStock.data?.data.formulas ?? []).map((f) => [f.type_id, f.quantity]))
  const hasScope = formulaStock.data?.data.has_blueprint_scope

  const need = (type: number, quantity: number) => Math.max(0, quantity - (owned.get(type) ?? 0))
  const volume = result.raw_materials.reduce((sum, m) => sum + m.volume * m.quantity, 0)
  const fees = result.fees

  return (
    <>
      <section className="panel" aria-labelledby="slots-heading">
        <h2 id="slots-heading">Reaction slots required</h2>
        <dl className="calc-stats">
          <Stat label="Composite slots">{result.slots.composite}</Stat>
          <Stat label="Intermediate slots">{result.slots.intermediate}</Stat>
          <Stat label="Total slots" className="text-warn">
            {result.slots.total}
          </Stat>
        </dl>
      </section>

      <section className="panel" aria-labelledby="outputs-heading">
        <h2 id="outputs-heading">Composite outputs</h2>
        <ul className="calc-queue">
          {result.composite_outputs.map((o) => (
            <li key={o.formula_type_id}>
              <TypeIcon typeId={o.product_type_id} />
              <span className="pick">
                {o.product_name}
                {o.unit_price > 0 ? <small className="muted"> ({isk(o.unit_price)}/u → {isk(o.total_value)})</small> : null}
              </span>
              <OwnedBadge hasScope={hasScope} owned={formulaOwned.get(o.formula_type_id) ?? 0} required={o.slots} />
              <span className="badge">
                {o.slots} slot{o.slots > 1 ? 's' : ''}
              </span>
              <strong>{formatQty(o.total_output)} units</strong>
            </li>
          ))}
        </ul>
      </section>

      <section className="panel" aria-labelledby="cp-heading">
        <h2 id="cp-heading">Estimated cost &amp; profit ({regionLabel.replace(/.*\(/, '').replace(/\).*/, '')} sell)</h2>
        <dl className="calc-stats">
          <Stat label="Input materials" className="text-loss">
            {isk(result.totals.input_cost)}
          </Stat>
          <Stat label="Output value" className="text-profit">
            {isk(result.totals.output_value)}
          </Stat>
          <Stat label="Job fees" className="text-loss">
            {fees.total_job_cost > 0 ? isk(fees.total_job_cost) : '-'}
          </Stat>
          <Stat label="SCC fee (4%)" className="text-loss">
            {isk(fees.scc_fee)}
          </Stat>
          <Stat label={`Profit (${result.profit_margin}%)`} className={profitClass(result.profit)}>
            {signedIsk(result.profit)}
          </Stat>
        </dl>
        {fees.total_job_cost > 0 ? (
          <p className="calc-note">
            SCI: {isk(fees.job_fee)} + tax: {isk(fees.facility_tax)}
          </p>
        ) : null}
      </section>

      {result.intermediate_slots.length > 0 ? (
        <section className="panel" aria-labelledby="inter-heading">
          <h2 id="inter-heading">Intermediate reactions</h2>
          <ul className="calc-queue">
            {result.intermediate_slots.map((s) => (
              <li key={s.formula_type_id}>
                <TypeIcon typeId={s.product_type_id} />
                <span className="pick">{s.product_name}</span>
                <OwnedBadge hasScope={hasScope} owned={formulaOwned.get(s.formula_type_id) ?? 0} required={s.slots} />
                <span className="badge">
                  {s.slots} slot{s.slots > 1 ? 's' : ''}
                </span>
                <span className="muted">{formatQty(s.total_runs)} runs</span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="panel" aria-labelledby="raw-heading">
        <div className="calc-panel-head">
          <h2 id="raw-heading">Raw materials to buy</h2>
          {result.raw_materials.length > 0 ? (
            <CopyButton
              label="Copy multibuy"
              onCopy={() => {
                const lines = result.raw_materials.filter((m) => need(m.type_id, m.quantity) > 0).map((m) => `${m.name} ${need(m.type_id, m.quantity)}`)
                return lines.length > 0 ? lines.join('\n') : null
              }}
            />
          ) : null}
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">Material</th>
                <th scope="col" className="num">
                  Total quantity
                </th>
                <th scope="col" className="num">
                  Volume
                </th>
                <th scope="col" className="num">
                  In stock
                </th>
                <th scope="col" className="num">
                  Need to buy
                </th>
                <th scope="col" className="num">
                  Unit price
                </th>
                <th scope="col" className="num">
                  Total cost
                </th>
              </tr>
            </thead>
            <tbody>
              {result.raw_materials.length === 0 ? (
                <tr>
                  <td colSpan={7} className="muted">
                    No raw materials found
                  </td>
                </tr>
              ) : (
                result.raw_materials.map((m) => {
                  const inStock = owned.get(m.type_id) ?? 0
                  const toBuy = need(m.type_id, m.quantity)
                  return (
                    <tr key={m.type_id}>
                      <td>
                        <span className="asset-name">
                          <TypeIcon typeId={m.type_id} />
                          {m.name}
                        </span>
                      </td>
                      <td className="num">
                        <strong>{formatQty(m.quantity)}</strong>
                      </td>
                      <td className="num muted">{formatVolume(m.volume * m.quantity)}</td>
                      <td className={`num ${inStock > 0 ? 'text-profit' : 'muted'}`}>{formatQty(inStock)}</td>
                      <td className={`num ${toBuy > 0 ? 'text-warn' : 'text-profit'}`}>{formatQty(toBuy)}</td>
                      <td className="num muted">{m.unit_price > 0 ? isk(m.unit_price) : '-'}</td>
                      <td className="num">{m.total_cost > 0 ? isk(m.total_cost) : '-'}</td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
        <dl className="calc-stats">
          <Stat label="Total volume">{formatVolume(volume)}</Stat>
        </dl>
      </section>

      <section className="panel" aria-labelledby="export-heading">
        <h2 id="export-heading">Build program</h2>
        <p className="calc-note">Track production progress across all your characters.</p>
        <div className="calc-actions">{exportButtons}</div>
      </section>
    </>
  )
}

function OwnedBadge({ hasScope, owned, required }: { hasScope: boolean | undefined; owned: number; required: number }) {
  if (hasScope === undefined) return <span className="badge">…</span>
  if (!hasScope) return <span className="badge">No scope</span>
  if (owned >= required) return <span className="badge badge-fresh">{formatQty(owned)} owned</span>
  if (owned > 0) return <span className="badge badge-stale">{formatQty(owned)} / {required} owned</span>
  return <span className="badge badge-stale">Not owned</span>
}
