import { fireEvent, screen, within } from '@testing-library/react'
import type { ManufacturingResult } from '../api/calculators'
import { formatDuration, isk, signedIsk } from '../components/calculators/format'
import { JobSummary, MaterialsPanel } from '../components/calculators/ManufacturingResults'
import { renderWithData } from '../test/render'
import { combineMaterials } from './Manufacturing'

function material(overrides: Partial<ManufacturingResult['materials'][number]>): ManufacturingResult['materials'][number] {
  return {
    type_id: 34,
    name: 'Tritanium',
    base_quantity: 100,
    adjusted_quantity: 1000,
    quantity_per_run: 1000,
    unit_price: 5,
    total_cost: 5000,
    price_source: 'orders',
    volume: 0.01,
    component: null,
    ...overrides,
  }
}

const scenario = {
  material_cost: 10000,
  profit: 5000,
  margin: 50,
  per_unit: 500,
  job_fees: 0,
  total_fees: 600,
  total_cost: 10600,
  profit_after_fees: 4400,
  margin_after_fees: 41.5,
  per_unit_after_fees: 440,
}

function result(overrides: Partial<ManufacturingResult> = {}): ManufacturingResult {
  return {
    product: { sell_price: 1500, price_source: 'orders', total_output: 10, total_revenue: 15000 },
    time: { base_time_seconds: 3600, time_per_run_seconds: 3000, total_time_seconds: 30000 },
    materials: [
      material({}),
      material({
        type_id: 77003,
        name: 'Test Component',
        adjusted_quantity: 5,
        total_cost: 7500,
        volume: 2,
        component: {
          is_buildable: true,
          blueprint_type_id: 77004,
          portion_size: 1,
          runs_needed: 5,
          base_time: 300,
          materials: [
            { type_id: 35, name: 'Pyerite', base_quantity: 50, adjusted_quantity: 250, quantity_per_run: 50, unit_price: 10, total_cost: 2500, volume: 0.01 },
          ],
          build_cost: 2500,
        },
      }),
    ],
    build_cheaper: true,
    scc_fee: 600,
    build_fee: { eiv: 0, sci: 0, job_cost: 0, tax_rate: 0, facility_tax: 0, total: 0, components_fee: 0, total_full_build: 0, has_data: false },
    scenarios: { full_buy: scenario, full_build: { ...scenario, material_cost: 7500 } },
    market_buy: { material_cost: 12500, total_revenue: 15000, scc_fee: 600, profit: 1900, profit_positive: true, profit_per_unit: 190, profit_margin: 15.2 },
    ...overrides,
  }
}

describe('formatting', () => {
  it('formats durations and ISK', () => {
    expect(formatDuration(30000)).toBe('8h 20m 0s')
    expect(formatDuration(90061)).toBe('1d 1h 1m 1s')
    expect(formatDuration(59)).toBe('59s')
    expect(isk(1500)).toBe('1.50K ISK')
    expect(signedIsk(-2_000_000)).toBe('-2.00M ISK')
  })
})

describe('combineMaterials', () => {
  it('sums a material across jobs and totals buy and build cost', () => {
    const combined = combineMaterials([{ result: result() }, { result: result() }])

    expect(combined.materials.map((m) => [m.name, m.adjusted_quantity])).toEqual([
      ['Test Component', 10],
      ['Tritanium', 2000],
    ])
    expect(combined.buy).toBe(25000)
    
    expect(combined.build).toBe(5000 + 10000)
  })
})

describe('MaterialsPanel', () => {
  it('expands a buildable component to its sub-materials', () => {
    renderWithData(<MaterialsPanel result={result()} fileName="test" />)

    expect(screen.queryByText('Pyerite')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Show the materials of Test Component' }))
    const sub = screen.getByText('Pyerite').closest('tr')!
    expect(within(sub).getByText('2.50K ISK')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Collapse all' }))
    expect(screen.queryByText('Pyerite')).not.toBeInTheDocument()
  })

  it('shows the totals of the buy and build scenarios', () => {
    renderWithData(<MaterialsPanel result={result()} fileName="test" />)

    expect(screen.getByText('Total (buy)').nextSibling).toHaveTextContent('10.00K ISK')
    expect(screen.getByText('Total (build, ME 10 components)').nextSibling).toHaveTextContent('7.50K ISK')
  })
})

describe('JobSummary', () => {
  it('hides fee rows when no system was configured', () => {
    renderWithData(<JobSummary result={result()} />)

    expect(screen.queryByText('Build fee breakdown')).not.toBeInTheDocument()
    expect(screen.getByText('✓ Building components is cheaper')).toBeInTheDocument()
    
    const buy = screen.getByRole('region', { name: 'Summary (full buy)' })
    expect(within(buy).getByText('5.00K ISK')).toBeInTheDocument()
  })

  it('shows the job fee breakdown and fee-adjusted profit when fees apply', () => {
    const fee = { eiv: 100000, sci: 0.05, job_cost: 5000, tax_rate: 0.25, facility_tax: 40, total: 5040, components_fee: 700, total_full_build: 5740, has_data: true }
    renderWithData(<JobSummary result={result({ build_fee: fee })} />)

    expect(screen.getByText('Build fee breakdown')).toBeInTheDocument()
    expect(screen.getByText('Job cost (SCI 5.00%)')).toBeInTheDocument()
    const build = screen.getByRole('region', { name: 'Summary (full build)' })
    expect(within(build).getByText('Component fees')).toBeInTheDocument()
    expect(within(build).getByText('4.40K ISK')).toBeInTheDocument()
  })

  it('includes the SCC fee in the headline total cost and build fee', () => {
    const fee = { eiv: 100000, sci: 0.05, job_cost: 5000, tax_rate: 0.25, facility_tax: 40, total: 5040, components_fee: 0, total_full_build: 5040, has_data: true }
    const buy = { ...scenario, job_fees: 5040, total_fees: 5640, total_cost: 15640 }
    renderWithData(<JobSummary result={result({ build_fee: fee, scenarios: { full_buy: buy, full_build: buy } })} />)

    const panel = screen.getByRole('region', { name: 'Cost & profit' })
    expect(within(panel).getByText('Total cost (materials + fees)').nextSibling).toHaveTextContent('15.64K ISK')
    expect(within(panel).getByText('Total build fee (incl. SCC)').nextSibling).toHaveTextContent('5.64K ISK')
    expect(within(panel).getByText('Profit').nextSibling).toHaveTextContent('+4.40K ISK')
  })
})
