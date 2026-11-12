import { useEffect, useRef, useState } from 'react'
import { useCostIndices, useStructures, type BuildProgramRequest, type Structure } from '../api/calculators'
import {
  useCalculateInvention,
  useDecryptors,
  useDeleteInventionPlan,
  useInventionChain,
  useInventionPlans,
  useInventionSearch,
  useInventionSkills,
  useSaveInventionPlan,
  type InventionChain,
  type InventionPlanBody,
  type InventionRequest,
  type InventionResult,
  type SavedInventionPlan,
} from '../api/invention'
import { useMe } from '../api/queries'
import type { components } from '../api/schema'
import { CorporateExportButton, ExportProgramButton } from '../components/calculators/ExportProgram'
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
  num,
} from '../components/calculators/kit'
import { MaterialsTable } from '../components/calculators/ManufacturingResults'
import { formatQty } from '../components/format'

type ItemHit = components['schemas']['TypeSummaryResource']

function useItemHits(q: string) {
  const r = useInventionSearch(q)
  return { data: r.data?.data, isFetching: r.isFetching, isError: r.isError }
}

const STRUCTURES = ['none', 'raitaru', 'azbel', 'sotiyo'] as const
const RIGS = ['none', 't1', 't2'] as const
const SECURITIES = ['highsec', 'lowsec', 'nullsec'] as const


function structureParams(structure: Structure | undefined) {
  return {
    structure_type: pick(structure?.structure_type, STRUCTURES, 'none'),
    rig_me: pick(structure?.rig_me, RIGS, 'none'),
    rig_te: pick(structure?.rig_te, RIGS, 'none'),
    system_security: pick(structure?.system_security, SECURITIES, 'highsec'),
  }
}

type Skills = NonNullable<ReturnType<typeof useInventionSkills>['data']>['data']['skills']


function levelOf(skills: Skills, list: Skills['encryption'], skillId: number | null): number {
  if (skillId === null) return 0
  return list.find((s) => s.skill_id === skillId)?.level ?? skills.levels[String(skillId)] ?? 0
}

export default function InventionPlanner() {
  const me = useMe()
  const structures = useStructures('invention')
  const reactionStructures = useStructures('reaction')
  const list = structures.data?.data ?? []
  const reactionList = reactionStructures.data?.data ?? []
  const [choice, setChoice] = useState<number | null | undefined>(undefined)
  const [reactionChoice, setReactionChoice] = useState<number | null | undefined>(undefined)
  const structureId = choice === undefined ? (list.find((s) => s.is_default)?.id ?? null) : choice
  const reactionId = reactionChoice === undefined ? (reactionList.find((s) => s.is_default)?.id ?? null) : reactionChoice
  const structure = list.find((s) => s.id === structureId)
  const reactionStructure = reactionList.find((s) => s.id === reactionId)

  const [item, setItem] = useState<{ type_id: number; name: string } | null>(null)
  const chain = useInventionChain(item?.type_id ?? null)
  const chainData: InventionChain | undefined = chain.data?.data
  const decryptors = useDecryptors()
  const [decryptor, setDecryptor] = useState('none')
  const [encryption, setEncryption] = useState('0')
  const [science1, setScience1] = useState('0')
  const [science2, setScience2] = useState('0')
  const [runs, setRuns] = useState('1')
  const [baseProbability, setBaseProbability] = useState('0')
  const [baseRuns, setBaseRuns] = useState('1')
  const [region, setRegion] = useState<string>(DEFAULT_REGION)
  const [characterId, setCharacterId] = useState<number | null>(null)

  const [sci, setSci] = useState(0)

  
  useEffect(() => {
    if (chainData) {
      setBaseProbability(String(chainData.base_probability))
      setBaseRuns(String(chainData.base_runs))
    }
  }, [chainData])

  const skills = useInventionSkills(characterId)
  useEffect(() => {
    const loaded = skills.data?.data.skills
    if (!loaded || !chainData) return
    setEncryption(String(levelOf(loaded, loaded.encryption, chainData.encryption_skill_id)))
    setScience1(String(levelOf(loaded, loaded.science, chainData.science1_skill_id)))
    setScience2(String(levelOf(loaded, loaded.science, chainData.science2_skill_id)))
  }, [skills.data, chainData])

  const plans = useInventionPlans()
  const savePlan = useSaveInventionPlan()
  const deletePlan = useDeleteInventionPlan()
  const [planName, setPlanName] = useState('')
  const [planId, setPlanId] = useState<number | null>(null)
  const [selectedPlan, setSelectedPlan] = useState('')
  const [notice, setNotice] = useState<string | null>(null)
  const savedPlans: SavedInventionPlan[] = plans.data?.data ?? []

  const calc = useCalculateInvention()
  const [result, setResult] = useState<InventionResult | null>(null)
  const [calcItem, setCalcItem] = useState<{ type_id: number; name: string } | null>(null)

  const isDemo = me.data?.data.demo === true
  const demoInit = useRef(false)
  const demoCalculated = useRef(false)
  useEffect(() => {
    if (!isDemo || demoInit.current || savedPlans.length === 0) return
    demoInit.current = true
    loadPlan(savedPlans[0]!)
  }, [isDemo, savedPlans])
  useEffect(() => {
    if (!isDemo || !demoInit.current || demoCalculated.current || !item || !chainData || result !== null || calc.isPending) return
    if (baseRuns !== String(chainData.base_runs) || baseProbability !== String(chainData.base_probability)) return
    demoCalculated.current = true
    calculate()
  }, [isDemo, item, chainData, baseRuns, baseProbability, result, calc.isPending])

  function request(): InventionRequest | null {
    if (!item) return null
    return {
      t2_type_id: item.type_id,
      decryptor_key: decryptor,
      encryption_skill: intIn(encryption, 0, 5, 0),
      science1_skill: intIn(science1, 0, 5, 0),
      science2_skill: intIn(science2, 0, 5, 0),
      base_probability: num(baseProbability, 0) || null,
      base_runs: intIn(baseRuns, 1, 1000, 1),
      invention_runs: intIn(runs, 1, 10000, 1),
      ...structureParams(structure),
      ...(reactionStructure
        ? {
            reaction_structure_type: pick(reactionStructure.structure_type, ['none', 'athanor', 'tatara'] as const, 'none'),
            reaction_rig_me: pick(reactionStructure.rig_me, RIGS, 'none'),
            reaction_system_security: pick(reactionStructure.system_security, SECURITIES, 'highsec'),
          }
        : {}),
      facility_tax: structure?.tax_rate ?? 0,
      system_cost_index: structure?.solar_system_id ? sci : 0,
      price_region_id: Number(region),
    }
  }

  function calculate() {
    const body = request()
    if (!body) return
    calc.mutate(body, {
      onSuccess: (response) => {
        setResult(response.data)
        setCalcItem(item)
      },
    })
  }

  function planBody(): InventionPlanBody | null {
    if (!item) return null
    return {
      name: planName.trim(),
      t2_type_id: item.type_id,
      t2_type_name: item.name,
      decryptor_key: decryptor,
      encryption_skill: intIn(encryption, 0, 5, 0),
      science1_skill: intIn(science1, 0, 5, 0),
      science2_skill: intIn(science2, 0, 5, 0),
      invention_runs: intIn(runs, 1, 10000, 1),
      ...structureParams(structure),
      facility_tax: structure?.tax_rate ?? 0,
      solar_system_id: structure?.solar_system_id ?? null,
      station_id: null,
      price_region_id: Number(region),
    }
  }

  function loadPlan(plan: SavedInventionPlan) {
    setPlanId(plan.id)
    setPlanName(plan.name)
    setDecryptor(plan.decryptor_key || 'none')
    setEncryption(String(plan.encryption_skill || 0))
    setScience1(String(plan.science1_skill || 0))
    setScience2(String(plan.science2_skill || 0))
    setRuns(String(plan.invention_runs || 1))
    if (plan.price_region_id) setRegion(String(plan.price_region_id))
    setItem(plan.t2_type_id ? { type_id: plan.t2_type_id, name: plan.t2_type_name || 'Unknown' } : null)
    setResult(null)
    setNotice(`Plan "${plan.name}" loaded`)
  }

  function save(update: boolean) {
    const body = planBody()
    if (!body || body.name === '') return
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

  function program(corporate: boolean): BuildProgramRequest {
    const settings = structureParams(structure)
    return {
      name: `${calcItem?.name ?? 'T2 Item'} ${corporate ? 'Corporate ' : ''}Build Program`,
      t2_type_id: calcItem?.type_id ?? null,
      t2_type_name: calcItem?.name ?? null,
      steps: (result?.t2_materials ?? []).map((m) => ({
        type_id: m.type_id,
        name: m.name,
        quantity_required: m.adjusted_quantity,
        runs_needed: m.component?.runs_needed ?? 0,
        materials: m.component?.materials ?? [],
      })),
      settings: {
        decryptor_key: decryptor,
        invention_runs: intIn(runs, 1, 10000, 1),
        structure_type: settings.structure_type,
        system_security: settings.system_security,
        ...(reactionStructure
          ? {
              reaction_structure_type: reactionStructure.structure_type,
              reaction_rig_me: reactionStructure.rig_me,
              reaction_system_security: reactionStructure.system_security,
            }
          : {}),
      },
    }
  }

  const characters = me.data?.data.characters ?? []
  const decryptorOptions = (decryptors.data?.data ?? []).map(
    (d) =>
      [
        d.key,
        d.key === 'none'
          ? d.name
          : `${d.name} (Prob ×${d.probability_multiplier}, ME ${d.me_modifier >= 0 ? '+' : ''}${d.me_modifier}, TE ${d.te_modifier >= 0 ? '+' : ''}${d.te_modifier}, Runs ${d.run_modifier >= 0 ? '+' : ''}${d.run_modifier})`,
      ] as const,
  )
  const canSave = planName.trim() !== '' && item !== null && !savePlan.isPending

  return (
    <>
      <h1>Invention planner</h1>
      <div className="calc-layout calc-layout-wide">
        <form
          className="panel calc-form"
          aria-label="Invention settings"
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

          <SearchPicker<ItemHit>
            label="Search T2 item"
            placeholder="Search T2 item…"
            useResults={useItemHits}
            getKey={(i) => i.type_id}
            renderItem={(i) => (
              <>
                <img src={i.icon_url} alt="" width={24} height={24} onError={(e) => (e.currentTarget.hidden = true)} />
                {i.name}
              </>
            )}
            onSelect={(i) => {
              setItem({ type_id: i.type_id, name: i.name })
              setResult(null)
            }}
          />
          {item ? (
            <div className="calc-picked" aria-live="polite">
              <TypeIcon typeId={item.type_id} size={32} />
              <div style={{ flex: 1 }}>
                <strong>{item.name}</strong>
                <small className="muted">Type ID: {item.type_id}</small>
              </div>
              <button type="button" className="button" aria-label="Clear selection" onClick={() => { setItem(null); setResult(null) }}>
                ✕
              </button>
            </div>
          ) : null}
          {item && chain.isPending ? <p className="muted">Loading invention data…</p> : null}
          {item && chain.isError ? (
            <p role="alert" className="text-warn">
              Could not resolve the invention chain for this item. It may not be a T2 invented item, or SDE data may be missing.
            </p>
          ) : null}

          <SelectField label="Decryptor" value={decryptor} onChange={setDecryptor} options={decryptorOptions.length > 0 ? decryptorOptions : [['none', 'None']]} />

          <h3>Character skills</h3>
          {characters.length > 0 ? (
            <div className="scan-field">
              <label htmlFor="inv-character">Load from character</label>
              <select id="inv-character" value={characterId ?? ''} onChange={(e) => setCharacterId(e.target.value === '' ? null : Number(e.target.value))}>
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
          ) : null}
          <div className="calc-two">
            <NumberField label={chainData?.encryption_skill_name ?? 'Encryption'} value={encryption} onChange={setEncryption} min={0} max={5} step={1} />
            <NumberField label={chainData?.science1_skill_name ?? 'Science skill 1'} value={science1} onChange={setScience1} min={0} max={5} step={1} />
            <NumberField label={chainData?.science2_skill_name ?? 'Science skill 2'} value={science2} onChange={setScience2} min={0} max={5} step={1} />
          </div>

          <h3>Structure settings</h3>
          <StructureSelect label="Station" structures={list} value={structureId} onChange={setChoice} noneLabel="No station selected" />
          {structure?.solar_system_id ? <SystemIndex systemId={structure.solar_system_id} onIndex={setSci} /> : null}
          <StructureSelect
            label="Reaction structure"
            hint="(for reaction sub-components)"
            structures={reactionList}
            value={reactionId}
            onChange={setReactionChoice}
            noneLabel="No reaction structure"
          />
          <SelectField label="Market prices" value={region} onChange={setRegion} options={PRICE_REGIONS} />

          <NumberField label="Number of invention runs" value={runs} onChange={setRuns} min={1} max={10000} step={1} />
          <div className="calc-two">
            <NumberField label="Base probability" value={baseProbability} onChange={setBaseProbability} min={0} max={1} step={0.01} />
            <NumberField label="Base runs (T2 BPC)" value={baseRuns} onChange={setBaseRuns} min={1} max={1000} step={1} />
          </div>

          <button type="submit" className="button" disabled={item === null || chainData === undefined || calc.isPending}>
            {calc.isPending ? 'Calculating…' : 'Calculate invention'}
          </button>
          {calc.isError ? (
            <p role="alert" className="text-loss">
              {errorText(calc.error)}
            </p>
          ) : null}
        </form>

        <div className="calc-results-pane" aria-busy={calc.isPending}>
          {result ? (
            <InventionResults
              result={result}
              regionLabel={PRICE_REGIONS.find(([id]) => id === region)?.[1] ?? 'Jita'}
              fileName={(calcItem?.name ?? 't2').replace(/[^a-zA-Z0-9]/g, '_')}
              decryptorName={decryptors.data?.data.find((d) => d.key === decryptor)?.name ?? 'Decryptor'}
              exportButtons={
                <>
                  <ExportProgramButton label="Export to build program" build={() => program(false)} hint="Create a build program to track production progress for all components" />
                  <CorporateExportButton label="Export to corporate build program" build={() => program(true)} />
                </>
              }
            />
          ) : (
            <p className="panel muted">Select a T2 item and calculate to see invention results.</p>
          )}
        </div>
      </div>
    </>
  )
}

function SystemIndex({ systemId, onIndex }: { systemId: number; onIndex: (percent: number) => void }) {
  const indices = useCostIndices(systemId).data?.data.cost_indices.manufacturing
  const percent = indices === undefined ? null : Math.round(indices * 10000) / 100
  useEffect(() => {
    if (percent !== null) onIndex(percent)
    
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [percent])
  return (
    <p className="calc-note">
      System cost index: <strong>{percent === null ? 'loading…' : `${percent}%`}</strong>
    </p>
  )
}


export function InventionResults({
  result,
  regionLabel,
  fileName,
  decryptorName,
  exportButtons,
}: {
  result: InventionResult
  regionLabel: string
  fileName: string
  decryptorName: string
  exportButtons: React.ReactNode
}) {
  const inv = result.invention
  const probability = inv.probability * 100
  const buy = result.scenarios.full_buy
  const build = result.scenarios.full_build
  const decryptorUsed = inv.decryptor.unit_price > 0
  const datacoreVolume = inv.datacores.reduce((sum, d) => sum + d.volume * d.total_quantity, 0) + (decryptorUsed ? inv.decryptor.volume * inv.decryptor.total_quantity : 0)
  const multibuy = [...inv.datacores.map((d) => `${d.name} ${d.total_quantity}`), ...(decryptorUsed ? [`${decryptorName} ${inv.decryptor.total_quantity}`] : [])]

  return (
    <>
      <section className="panel" aria-labelledby="prob-heading">
        <h2 id="prob-heading">Invention probability</h2>
        <dl className="calc-stats">
          <Stat label="Success chance" className={probability >= 40 ? 'text-profit' : probability >= 25 ? 'text-warn' : 'text-loss'}>
            {probability.toFixed(1)}%
          </Stat>
          <Stat label="Attempts / success">{inv.expected_attempts}</Stat>
          <Stat label="Cost / attempt">{isk(inv.cost_per_attempt)}</Stat>
          <Stat label="Cost / success">{isk(inv.cost_per_success)}</Stat>
        </dl>
        <p>
          Output BPC: <span className="badge">ME {result.t2_bpc.me}</span> <span className="badge">TE {result.t2_bpc.te}</span>{' '}
          <span className="badge">Runs {result.t2_bpc.runs}</span>
        </p>
      </section>

      <section className="panel" aria-labelledby="iprofit-heading">
        <h2 id="iprofit-heading">Profit ({regionLabel.replace(/.*\(/, '').replace(/\).*/, '')} sell)</h2>
        <dl className="calc-stats">
          <Stat label={`Total output (${formatQty(result.output.total_output)} units)`} className="text-profit">
            {isk(result.output.total_value)}
          </Stat>
          <Stat label="Total cost" className="text-loss">
            {isk(result.best.total_cost)}
          </Stat>
          <Stat label="Profit" className={profitClass(result.best.profit)}>
            {signedIsk(result.best.profit)}
          </Stat>
          <Stat label="Margin / per unit" className={profitClass(result.best.profit)}>
            {result.best.profit_margin}% · {isk(result.best.profit_per_unit)}/unit
          </Stat>
        </dl>
      </section>

      <section className="panel" aria-labelledby="dc-heading">
        <div className="calc-panel-head">
          <h2 id="dc-heading">Datacores &amp; decryptor (total)</h2>
          {multibuy.length > 0 ? <CopyButton label="Multibuy" onCopy={() => multibuy.join('\n')} /> : null}
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">Material</th>
                <th scope="col" className="num">
                  Qty
                </th>
                <th scope="col" className="num">
                  Volume
                </th>
                <th scope="col" className="num">
                  Unit price
                </th>
                <th scope="col" className="num">
                  Total
                </th>
              </tr>
            </thead>
            <tbody>
              {inv.datacores.map((d) => (
                <tr key={d.type_id}>
                  <td>
                    <span className="asset-name">
                      <TypeIcon typeId={d.type_id} />
                      {d.name}
                    </span>
                  </td>
                  <td className="num">{formatQty(d.total_quantity)}</td>
                  <td className="num muted">{formatVolume(d.volume * d.total_quantity)}</td>
                  <td className="num muted">{isk(d.unit_price)}</td>
                  <td className="num">{isk(d.total_quantity_cost)}</td>
                </tr>
              ))}
              {decryptorUsed ? (
                <tr>
                  <td>{decryptorName}</td>
                  <td className="num">{formatQty(inv.decryptor.total_quantity)}</td>
                  <td className="num muted">{formatVolume(inv.decryptor.volume * inv.decryptor.total_quantity)}</td>
                  <td className="num muted">{isk(inv.decryptor.unit_price)}</td>
                  <td className="num">{isk(inv.decryptor.total_cost)}</td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>
        <dl className="calc-stats">
          <Stat label="Total volume">{formatVolume(datacoreVolume)}</Stat>
          <Stat label="Total invention cost">{isk(inv.total_cost)}</Stat>
        </dl>
      </section>

      <MaterialsTable
        heading="T2 bill of materials"
        materials={result.t2_materials}
        buy={result.material_costs.buy}
        build={result.material_costs.build}
        fileName={fileName}
      />

      <section className="panel" aria-labelledby="ibvb-heading">
        <h2 id="ibvb-heading">Build vs buy</h2>
        <dl className="calc-stats">
          <Stat label="Full buy (all from market)" className={result.build_cheaper ? undefined : 'text-profit'}>
            {isk(buy.total_cost)}
          </Stat>
          <Stat label="Full build (ME 10 components)" className={result.build_cheaper ? 'text-profit' : undefined}>
            {isk(build.total_cost)}
          </Stat>
        </dl>
        <p>
          <span className={result.build_cheaper ? 'badge badge-fresh' : 'badge'}>
            {result.build_cheaper ? '✓ Building components is cheaper' : '✓ Buying from market is cheaper'}
          </span>
        </p>
      </section>

      <div className="calc-two">
        <Summary title="Summary (full buy)" materialLabel="T2 materials (buy)" materials={result.material_costs.buy} inventionCost={inv.total_cost} jobFees={result.t2_job.total} outputValue={result.output.total_value} scenario={buy} />
        <Summary title="Summary (full build)" materialLabel="T2 materials (build)" materials={result.material_costs.build} inventionCost={inv.total_cost} jobFees={result.t2_job.total} outputValue={result.output.total_value} scenario={build} />
      </div>

      <section className="panel" aria-label="Export">
        <div className="calc-actions">{exportButtons}</div>
      </section>
    </>
  )
}

function Summary({
  title,
  materialLabel,
  materials,
  inventionCost,
  jobFees,
  outputValue,
  scenario,
}: {
  title: string
  materialLabel: string
  materials: number
  inventionCost: number
  jobFees: number
  outputValue: number
  scenario: InventionResult['scenarios']['full_buy']
}) {
  return (
    <section className="panel" aria-label={title}>
      <h2>{title}</h2>
      <table>
        <tbody>
          <tr>
            <th scope="row">Invention cost</th>
            <td className="num">{isk(inventionCost)}</td>
          </tr>
          <tr>
            <th scope="row">{materialLabel}</th>
            <td className="num">{isk(materials)}</td>
          </tr>
          <tr>
            <th scope="row">T2 job fees</th>
            <td className="num">{isk(jobFees)}</td>
          </tr>
          <tr>
            <th scope="row">Total cost</th>
            <td className="num text-loss">{isk(scenario.total_cost)}</td>
          </tr>
          <tr>
            <th scope="row">Output value</th>
            <td className="num text-profit">{isk(outputValue)}</td>
          </tr>
          <tr>
            <th scope="row">Net profit</th>
            <td className={`num ${profitClass(scenario.profit)}`}>{signedIsk(scenario.profit)}</td>
          </tr>
          <tr>
            <th scope="row">Margin</th>
            <td className={`num ${profitClass(scenario.profit)}`}>{scenario.margin}%</td>
          </tr>
        </tbody>
      </table>
    </section>
  )
}
