import { fireEvent, screen, within } from '@testing-library/react'
import type { InventionResult } from '../api/invention'
import type { ReactionPlanResult, ReactionResult } from '../api/reactions'
import { renderWithData } from '../test/render'
import { InventionResults } from './InventionPlanner'
import { PlanResults } from './ReactionPlanner'
import ReactionPlanner from './ReactionPlanner'
import { ReactionResults } from './Reactions'

const reaction: ReactionResult = {
  product: { sell_price: 300, price_source: 'orders', total_output: 3000, total_revenue: 900000 },
  time: { base_time_seconds: 10800, time_per_run_seconds: 7258, total_time_seconds: 108864 },
  materials: [
    { type_id: 16634, name: 'Atmospheric Gases', base_quantity: 100, adjusted_quantity: 1464, quantity_per_run: 98, unit_price: 42, total_cost: 61488, price_source: 'orders' },
  ],
  summary: { material_cost: 1171488, scc_fee: 36000, profit: -307488, profit_positive: false, profit_per_unit: -102.5, profit_margin: -26.2 },
}

describe('ReactionResults', () => {
  it('shows a loss in the loss colour and the adjusted material quantities', () => {
    renderWithData(<ReactionResults result={reaction} />)

    expect(screen.getByText('Reaction time')).toBeInTheDocument()
    expect(screen.getByText('-307.49K ISK')).toHaveClass('text-loss')
    const row = screen.getByText('Atmospheric Gases').closest('tr')!
    expect(within(row).getByText('1,464')).toBeInTheDocument()
  })
})

const plan: ReactionPlanResult = {
  effective_rig_bonus: 2.2,
  composite_outputs: [
    { formula_type_id: 77010, product_type_id: 77011, product_name: 'Advanced Composite', slots: 2, runs_per_slot: 30, total_runs: 60, total_output: 600, portion_size: 10, price_source: 'orders', unit_price: 9000, total_value: 5400000 },
  ],
  composite_slots: [{ formula_type_id: 77010, product_name: 'Advanced Composite', slots: 2 }],
  intermediate_slots: [{ formula_type_id: 77006, product_type_id: 77005, product_name: 'Composite', slots: 1, runs_per_slot: 30, total_runs: 30 }],
  raw_materials: [
    { type_id: 16634, name: 'Atmospheric Gases', quantity: 3000, price_source: 'orders', unit_price: 42, total_cost: 126000, volume: 0.05 },
    { type_id: 4051, name: 'Fuel Block', quantity: 400, price_source: 'orders', unit_price: 15000, total_cost: 6000000, volume: 5 },
  ],
  slots: { composite: 2, intermediate: 1, total: 3 },
  totals: { input_cost: 6126000, output_value: 5400000 },
  fees: { system_cost_index: 3.5, facility_tax_rate: 2, job_fee: 1000, facility_tax: 200, total_job_cost: 1200, scc_fee: 216000 },
  profit: -727200,
  profit_margin: -11.9,
}

describe('PlanResults', () => {
  const stock = [
    ['calculators', 'reaction-stock', [16634, 4051]] as const,
    {
      data: [
        { type_id: 16634, quantity: 1000 },
        { type_id: 4051, quantity: 900 },
      ],
      meta: {},
    },
  ] as const
  const formulaStock = [
    ['calculators', 'formula-stock', [77010, 77006]] as const,
    { data: { has_blueprint_scope: true, formulas: [{ type_id: 77010, quantity: 1 }, { type_id: 77006, quantity: 5 }] }, meta: {} },
  ] as const

  it('subtracts stock from the quantity to buy and flags formulas that are not fully owned', () => {
    renderWithData(<PlanResults result={plan} regionLabel="The Forge (Jita)" exportButtons={null} />, [[stock[0], stock[1]], [formulaStock[0], formulaStock[1]]])

    const gases = screen.getByText('Atmospheric Gases', { selector: '.asset-name' }).closest('tr')!
    expect(within(gases).getByText('2,000')).toHaveClass('text-warn')
    const fuel = screen.getByText('Fuel Block', { selector: '.asset-name' }).closest('tr')!
    expect(within(fuel).getAllByText('0')[0]).toHaveClass('text-profit')

    expect(screen.getByText('1 / 2 owned')).toBeInTheDocument()
    expect(screen.getByText('5 owned')).toBeInTheDocument()
    expect(screen.getByText('Total slots').nextSibling).toHaveTextContent('3')
  })

  it('shows the job fee breakdown when fees apply', () => {
    renderWithData(<PlanResults result={plan} regionLabel="The Forge (Jita)" exportButtons={null} />)

    expect(screen.getByText(/SCI: 1\.00K ISK \+ tax: 200\.00 ISK/)).toBeInTheDocument()
    expect(screen.getByText('Profit (-11.9%)').nextSibling).toHaveClass('text-loss')
  })

  it('shows the SCC fee even when there are no job fees', () => {
    const noJobFees = { ...plan, fees: { ...plan.fees, job_fee: 0, facility_tax: 0, total_job_cost: 0 } }
    renderWithData(<PlanResults result={noJobFees} regionLabel="The Forge (Jita)" exportButtons={null} />)

    expect(screen.getByText('SCC fee (4%)').nextSibling).toHaveTextContent('216.00K ISK')
    expect(screen.queryByText(/SCI:/)).not.toBeInTheDocument()
  })
})

describe('ReactionPlanner saved plans', () => {
  it('loads a saved plan into the entry list', () => {
    const plans = {
      data: [
        {
          id: 7,
          name: 'Two week cycle',
          entries: [{ formula_type_id: 77010, formula_name: 'Advanced Composite Formula', icon_url: '', slots: 2, runs_per_slot: 240 }],
          default_runs_per_slot: 240,
          rig_bonus: 0,
          structure_type: 'athanor',
          rig_tier: 'none',
          security_space: 'lowsec',
          facility_tax: 0,
          station_id: null,
          solar_system_id: null,
          price_region_id: 10000043,
          created_at: null,
          updated_at: null,
        },
      ],
      meta: { pagination: { page: 1, per_page: 200, total: 1, last_page: 1 } },
    }
    renderWithData(<ReactionPlanner />, [
      [['plans', 'reaction'], plans],
      [['structures', 'reaction'], { data: [], meta: { pagination: { page: 1, per_page: 200, total: 0, last_page: 1 } } }],
    ])

    fireEvent.change(screen.getByLabelText('Saved plans'), { target: { value: '7' } })
    fireEvent.click(screen.getByRole('button', { name: 'Load' }))

    expect(screen.getByText('Advanced Composite Formula')).toBeInTheDocument()
    expect(screen.getByLabelText('Slots for Advanced Composite Formula')).toHaveValue(2)
    expect(screen.getByLabelText('Plan name')).toHaveValue('Two week cycle')
    expect(screen.getByRole('status')).toHaveTextContent('Plan "Two week cycle" loaded')
    
    expect(screen.getByRole('button', { name: 'Update' })).toBeInTheDocument()
  })
})

const invention: InventionResult = {
  invention: {
    probability: 0.3,
    expected_attempts: 4,
    total_attempts: 4,
    cost_per_attempt: 201250,
    cost_per_success: 670840,
    total_cost: 805010,
    datacore_cost_per_attempt: 201250,
    datacores: [{ type_id: 20171, name: 'Datacore - Mechanical Engineering', quantity: 2, unit_price: 22990, total_cost: 45980, volume: 0.1, total_quantity: 8, total_quantity_cost: 183930 }],
    decryptor: { unit_price: 1000000, volume: 1, total_quantity: 4, total_cost: 4000000 },
  },
  t1_item: { type_id: 587, name: 'Rifter' },
  t2_bpc: { me: 4, te: 14, runs: 2, decryptor: { name: 'Accelerant Decryptor', type_id: 34201, probability_multiplier: 1.2, me_modifier: 2, te_modifier: 10, run_modifier: 1 } },
  t2_materials: [
    {
      type_id: 77003,
      name: 'Test Component',
      base_quantity: 6,
      adjusted_quantity: 33,
      quantity_per_run: 6,
      unit_price: 1500,
      total_cost: 49500,
      volume: 2,
      component: {
        is_buildable: true,
        blueprint_type_id: 77004,
        portion_size: 1,
        runs_needed: 33,
        base_time: 300,
        materials: [{ type_id: 34, name: 'Tritanium', base_quantity: 100, adjusted_quantity: 2793, quantity_per_run: 85, unit_price: 5.5, total_cost: 15361.5, volume: 0.01 }],
        build_cost: 32546.17,
        material_cost: 31077.75,
        job_cost: 1468.42,
      },
    },
  ],
  material_costs: { buy: 49500, build: 32546.17 },
  t2_job: { job_fee: 3870, facility_tax: 193, total: 4063 },
  output: { sell_price: 30000000, total_output: 6, total_value: 180000000 },
  structure_bonuses: { me: 5.99, te: 45 },
  build_cheaper: true,
  best: { total_cost: 8190077, profit: 171809923, profit_margin: 2097.8, profit_per_unit: 28634987 },
  scenarios: {
    full_buy: { total_cost: 8231377, profit: 171768623, margin: 2086.8, per_unit: 28628103 },
    full_build: { total_cost: 8190077, profit: 171809923, margin: 2097.8, per_unit: 28634987 },
  },
}

describe('InventionResults', () => {
  it('shows probability, the output BPC and the decryptor row', () => {
    renderWithData(<InventionResults result={invention} regionLabel="The Forge (Jita)" fileName="Wolf" decryptorName="Accelerant Decryptor" exportButtons={null} />)

    expect(screen.getByText('30.0%')).toHaveClass('text-warn')
    expect(screen.getByText('ME 4')).toBeInTheDocument()
    expect(screen.getByText('Runs 2')).toBeInTheDocument()
    const dc = screen.getByRole('heading', { name: /Datacores/ }).closest('section')!
    expect(within(dc).getByText('Accelerant Decryptor')).toBeInTheDocument()
    expect(within(dc).getByText('4.00M ISK')).toBeInTheDocument()
  })

  it('expands a buildable T2 component with its material and job cost', () => {
    renderWithData(<InventionResults result={invention} regionLabel="The Forge (Jita)" fileName="Wolf" decryptorName="None" exportButtons={null} />)

    fireEvent.click(screen.getByRole('button', { name: 'Show the materials of Test Component' }))
    expect(screen.getByText('Tritanium')).toBeInTheDocument()
    expect(screen.getByText(/Job cost:/)).toBeInTheDocument()
    expect(screen.getByText('✓ Building components is cheaper')).toBeInTheDocument()
  })
})
