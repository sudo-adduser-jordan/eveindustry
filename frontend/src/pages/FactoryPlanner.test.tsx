import { fireEvent, screen } from '@testing-library/react'
import { renderWithData } from '../test/render'
import FactoryPlanner, { formatCycle, summarise, type ChainRow, type StepRow } from './FactoryPlanner'

const step = (attrs: Partial<StepRow>): StepRow => ({
  output_type_id: 2358,
  output_name: 'Biotech Research Reports',
  output_qty: 3,
  cycle_time: 3600,
  inputs: [{ type_id: 3689, name: 'Mechanical Parts', qty: 10, unit_cost: 1000, total_cost: 10000, has_price: true, price_source: 'orders' }],
  total_input_cost: 10000,
  output_sell_min: 5000,
  output_buy_max: 4500,
  output_price_source: 'orders',
  output_sell_value: 15000,
  output_buy_value: 13500,
  profit_per_cycle_sell: 5000,
  profit_per_cycle_buy: 3500,
  margin_percent_sell: 50,
  cycles_per_day: 24,
  daily_profit_per_factory: 120000,
  daily_profit_buy_per_factory: 84000,
  factory_slots: 18,
  daily_input_cost_planet: 4320000,
  daily_output_sell_planet: 6480000,
  daily_profit_sell_planet: 2160000,
  daily_profit_buy_planet: 1512000,
  prices_available: true,
  daily_import_tax: 0,
  daily_export_tax: 0,
  daily_tax_total: 0,
  daily_profit_after_tax: 2160000,
  ...attrs,
})

const key = (tier: string, tax = 0) => ['planetary', 'factory-planner', { tier, region_id: 10000002, tax_rate: tax }] as const

function response(tier: string, results: object[]) {
  return {
    data: { tier, tier_label: tier, factory_slots: 18, tax_rate: 0, results },
    meta: { freshness: { prices_as_of: '2026-10-23T12:00:00+00:00', costs_as_of: null, source: 'orders', stale: false } },
  }
}

describe('FactoryPlanner', () => {
  it('ranks products and opens the breakdown', () => {
    renderWithData(<FactoryPlanner />, [
      [key('p2_to_p3'), response('p2_to_p3', [step({}), step({ output_type_id: 2345, output_name: 'Camera Drones', prices_available: false, margin_percent_sell: null })])],
    ])
    expect(screen.getByText('Biotech Research Reports')).toBeInTheDocument()
    expect(screen.getByText('Missing price data')).toBeInTheDocument()
    
    expect(screen.getAllByText('2.16M ISK').length).toBeGreaterThan(0)
    expect(screen.getByText('Total products').previousSibling).toHaveTextContent('2')
    fireEvent.click(screen.getByText('Biotech Research Reports'))
    expect(screen.getByText('Input breakdown')).toBeInTheDocument()
    expect(screen.getByText('×10')).toBeInTheDocument()
  })

  it('shows the single-planet chain tier with its factory split', () => {
    const chain: ChainRow = {
      output_type_id: 2358,
      output_name: 'Biotech Research Reports',
      output_qty_per_day: 216,
      factory_split: { p3_factories: 6, total: 18 },
      p2_inputs: [{ type_id: 3689, name: 'Mechanical Parts', factories: 6, daily_produced: 100, daily_consumed: 100 }],
      p1_inputs: [{ type_id: 2393, name: 'Bacteria', daily_qty: 1200, unit_cost: 10, daily_cost: 12000, has_price: true, price_source: 'esi_average' }],
      total_daily_p1_cost: 12000,
      p3_sell_min: 1000,
      p3_buy_max: 900,
      p3_price_source: 'orders',
      daily_p3_sell_value: 216000,
      daily_p3_buy_value: 194400,
      daily_profit_sell: 204000,
      daily_profit_buy: 182400,
      margin_percent_sell: 1700,
      daily_import_value: 12000,
      daily_export_value: 216000,
      prices_available: true,
      daily_import_tax: 0,
      daily_export_tax: 0,
      daily_tax_total: 0,
      daily_profit_after_tax: 204000,
    }
    renderWithData(<FactoryPlanner />, [[key('p2_to_p3'), response('p2_to_p3', [])]])
    
    expect(screen.getByText('No market price data available.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('tab', { name: /P1 → P3/ }))
    
    expect(screen.getByRole('tab', { name: /P1 → P3/ })).toHaveAttribute('aria-selected', 'true')
    expect(summarise([chain], 0)).toEqual({ bestProfit: 204000, profitable: 1, total: 1, bestMargin: 1700 })
  })
})

describe('summarise', () => {
  it('uses the after-tax profit of the best row when tax applies', () => {
    const rows = [step({ daily_profit_after_tax: 1_000_000 }), step({ daily_profit_per_factory: -5, margin_percent_sell: 80 })]
    expect(summarise(rows, 5)).toEqual({ bestProfit: 1_000_000, profitable: 1, total: 2, bestMargin: 80 })
    expect(summarise(rows, 0).bestProfit).toBe(2_160_000)
    expect(summarise([], 0)).toEqual({ bestProfit: null, profitable: 0, total: 0, bestMargin: null })
  })
})

describe('formatCycle', () => {
  it('picks a readable unit', () => {
    expect(formatCycle(1800)).toBe('30 min')
    expect(formatCycle(7200)).toBe('2.0 hr')
    expect(formatCycle(172800)).toBe('2.0 day')
  })
})

describe('FactoryPlanner tier switch', () => {
  it('renders rows by the shape of the response, not the selected tab', () => {
    
    const chain = {
      output_type_id: 2358,
      output_name: 'Biotech Research Reports',
      output_qty_per_day: 216,
      factory_split: { p3_factories: 6, total: 18 },
      p2_inputs: [],
      p1_inputs: [],
      total_daily_p1_cost: 0,
      p3_sell_min: null,
      p3_buy_max: null,
      p3_price_source: null,
      daily_p3_sell_value: 0,
      daily_p3_buy_value: 0,
      daily_profit_sell: 0,
      daily_profit_buy: 0,
      margin_percent_sell: null,
      daily_import_value: 0,
      daily_export_value: 0,
      prices_available: true,
      daily_import_tax: 0,
      daily_export_tax: 0,
      daily_tax_total: 0,
      daily_profit_after_tax: 0,
    }
    renderWithData(<FactoryPlanner />, [[key('p2_to_p3'), response('p1_to_p3', [chain])]])
    expect(screen.getByText('6× P3')).toBeInTheDocument()
  })
})
