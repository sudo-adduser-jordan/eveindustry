import { useEffect, useRef, useState } from 'react'
import { useCostIndices, useReactionBrowse, useStructures } from '../api/calculators'
import { useMe } from '../api/queries'
import { FreshnessBadge } from '../components/FreshnessBadge'
import { ProfitTable } from '../components/calculators/ProfitTable'
import { DEFAULT_REGION, NumberField, PRICE_REGIONS, SelectField, StructureSelect, errorText, intIn } from '../components/calculators/kit'

type Category = 'all' | 'composite' | 'biochemical' | 'hybrid'
type Complexity = 'all' | 'intermediate' | 'complex'

const CATEGORIES = [
  ['all', 'All types'],
  ['composite', 'Composite'],
  ['biochemical', 'Biochemical'],
  ['hybrid', 'Hybrid'],
] as const
const COMPLEXITY = [
  ['all', 'All complexity'],
  ['intermediate', 'Intermediate'],
  ['complex', 'Complex'],
] as const

export default function ReactionBrowser() {
  const me = useMe()
  const isDemo = me.data?.data.demo === true
  const structures = useStructures('reaction')
  const list = structures.data?.data ?? []
  const [choice, setChoice] = useState<number | null | undefined>(undefined)
  const structureId = choice === undefined ? (list.find((s) => s.is_default)?.id ?? null) : choice
  const structure = list.find((s) => s.id === structureId)

  const [runs, setRuns] = useState('1')
  const [region, setRegion] = useState<string>(DEFAULT_REGION)
  const [category, setCategory] = useState<Category>('all')
  const [complexity, setComplexity] = useState<Complexity>('all')
  
  const costIndices = useCostIndices(structure?.solar_system_id ?? null)
  const sci = costIndices.data ? Math.round(costIndices.data.data.cost_indices.reaction * 10000) / 100 : 0
  const [applied, setApplied] = useState<{ runs: number; region: number; rig: 'none' | 't1' | 't2'; security: 'lowsec' | 'nullsec'; tax: number; sci: number } | null>(null)

  const result = useReactionBrowse(
    applied
      ? {
          runs: applied.runs,
          rig_tier: applied.rig,
          security_space: applied.security,
          facility_tax: applied.tax,
          system_cost_index: applied.sci,
          price_region_id: applied.region,
          per_page: 200,
        }
      : null,
  )

  function calculate() {
    const rig = structure && (structure.rig_me === 't1' || structure.rig_me === 't2') ? structure.rig_me : 'none'
    setApplied({
      runs: intIn(runs, 1, 100000, 1),
      region: Number(region),
      rig,
      security: structure?.system_security === 'nullsec' ? 'nullsec' : 'lowsec',
      tax: structure?.tax_rate ?? 0,
      sci: structure?.solar_system_id ? sci : 0,
    })
  }

  const demoApplied = useRef(false)
  useEffect(() => {
    if (!isDemo || demoApplied.current || applied !== null || structures.isPending) return
    demoApplied.current = true
    const rig = structure && (structure.rig_me === 't1' || structure.rig_me === 't2') ? structure.rig_me : 'none'
    setApplied({
      runs: 1,
      region: Number(DEFAULT_REGION),
      rig,
      security: structure?.system_security === 'nullsec' ? 'nullsec' : 'lowsec',
      tax: structure?.tax_rate ?? 0,
      sci: 0,
    })
  }, [isDemo, applied, structures.isPending, structure, sci])

  return (
    <>
      <h1>Reaction browser</h1>
      <p>Set your parameters and calculate profitability for every reaction formula.</p>
      <div className="calc-layout">
        <form
          className="panel calc-form"
          aria-label="Reaction settings"
          onSubmit={(e) => {
            e.preventDefault()
            calculate()
          }}
        >
          <NumberField label="Number of runs" value={runs} onChange={setRuns} min={1} max={100000} step={1} />
          <StructureSelect label="Station" structures={list} value={structureId} onChange={setChoice} noneLabel="No station selected" />
          {structure?.solar_system_id ? (
            <p className="calc-note">
              System cost index: <strong>{costIndices.isPending ? 'loading…' : `${sci}%`}</strong>
              {structure.tax_rate ? ` · Facility tax ${structure.tax_rate}%` : ''}
            </p>
          ) : null}
          <SelectField label="Market prices" value={region} onChange={setRegion} options={PRICE_REGIONS} />
          <button type="submit" className="button">
            Calculate all reactions
          </button>
        </form>
        <section className="panel" aria-labelledby="rb-heading" aria-busy={result.isFetching}>
          <div className="calc-panel-head">
            <h2 id="rb-heading">Reaction profitability</h2>
            {result.data ? <FreshnessBadge asOf={result.data.meta.freshness.prices_as_of} stale={result.data.meta.freshness.stale} /> : null}
          </div>
          {!applied ? <p className="muted">Set your parameters and calculate to see profitability for every reaction formula.</p> : null}
          {applied && result.isPending ? <p className="muted">Calculating…</p> : null}
          {result.isError ? (
            <p role="alert" className="text-loss">
              {errorText(result.error)}
            </p>
          ) : null}
          {result.data ? (
            <ProfitTable
              rows={result.data.data}
              itemLabel="reactions"
              extraSearchText={(r) => r.formula_name}
              extraFilter={{
                test: (r) => (category === 'all' || r.reaction_category === category) && (complexity === 'all' || r.reaction_complexity === complexity),
                controls: (
                  <>
                    <select aria-label="Reaction type" value={category} onChange={(e) => setCategory(e.target.value as Category)}>
                      {CATEGORIES.map(([v, t]) => (
                        <option key={v} value={v}>
                          {t}
                        </option>
                      ))}
                    </select>
                    <select aria-label="Complexity" value={complexity} onChange={(e) => setComplexity(e.target.value as Complexity)}>
                      {COMPLEXITY.map(([v, t]) => (
                        <option key={v} value={v}>
                          {t}
                        </option>
                      ))}
                    </select>
                  </>
                ),
              }}
            />
          ) : null}
        </section>
      </div>
    </>
  )
}
