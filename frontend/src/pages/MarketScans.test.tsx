import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithData } from '../test/render'
import RegionArbitrage from './RegionArbitrage'
import StationTrading from './StationTrading'

const freshness = { prices_as_of: '2026-10-23T12:00:00+00:00', costs_as_of: null, source: 'orders', stale: false }
const pagination = { page: 1, per_page: 200, total: 2, last_page: 1 }

const trade = (attrs: object) => ({
  type_id: 34,
  type_name: 'Tritanium',
  best_buy: 5,
  best_sell: 6,
  gross_margin_pct: 20,
  net_profit_per_unit: 0.5,
  net_margin_pct: 8.3,
  roi_pct: 10,
  break_even: 5.5,
  daily_volume: 1000,
  daily_isk_profit: 500,
  buy_order_count: 3,
  sell_order_count: 4,
  ...attrs,
})


const stationKey = [
  'market',
  'station-trading',
  { region_id: '10000002', broker_fee: 3, sales_tax: 3.6, min_margin: 5, min_daily_volume: 5, per_page: 200, page: 1 },
] as const

function rowNames() {
  return screen
    .getAllByRole('row')
    .slice(1)
    .map((row) => within(row).getAllByRole('cell')[0]?.textContent)
}

describe('StationTrading', () => {
  const data = {
    data: [
      trade({ type_id: 34, type_name: 'Tritanium', daily_isk_profit: 500 }),
      trade({ type_id: 35, type_name: 'Pyerite', daily_isk_profit: 9000, net_profit_per_unit: -1 }),
    ],
    meta: { region: { region_id: 10000002, region_name: 'The Forge (Jita)' }, freshness, pagination },
  }

  it('lists opportunities, best daily profit first', () => {
    renderWithData(<StationTrading />, [[stationKey, data]])

    expect(screen.getByText('The Forge (Jita)', { selector: 'strong' })).toBeInTheDocument()
    expect(rowNames()).toEqual(['Pyerite', 'Tritanium'])
  })

  it('sorts by a clicked column and filters by name', () => {
    renderWithData(<StationTrading />, [[stationKey, data]])

    fireEvent.click(screen.getByRole('button', { name: /Item/ }))
    expect(rowNames()).toEqual(['Pyerite', 'Tritanium'])
    fireEvent.click(screen.getByRole('button', { name: /Item/ }))
    expect(rowNames()).toEqual(['Tritanium', 'Pyerite'])

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search items' }), { target: { value: 'trit' } })
    expect(rowNames()).toEqual(['Tritanium'])
    expect(screen.getByText('1 of 2')).toBeInTheDocument()
  })

  it('explains an empty scan', () => {
    renderWithData(<StationTrading />, [[stationKey, { ...data, data: [] }]])

    expect(screen.getByText(/No opportunities match your filters/)).toBeInTheDocument()
  })
})

describe('RegionArbitrage', () => {
  it('shows the route and a pager when there are more pages', () => {
    const key = [
      'market',
      'region-arbitrage',
      {
        buy_region_id: '10000002',
        sell_region_id: '10000043',
        sales_tax: 3.6,
        transport_isk_m3: 0,
        min_margin: 5,
        min_daily_volume: 5,
        per_page: 200,
        page: 1,
      },
    ] as const
    renderWithData(<RegionArbitrage />, [
      [
        key,
        {
          data: [
            {
              type_id: 34,
              type_name: 'Tritanium',
              volume_m3: 0.01,
              buy_price: 5,
              sell_price: 7,
              net_profit_per_unit: 1.5,
              net_margin_pct: 30,
              roi_pct: 30,
              daily_volume: 100,
              daily_isk_profit: 150,
              cargo_value_m3: null,
            },
          ],
          meta: {
            buy_region: { region_id: 10000002, region_name: 'The Forge (Jita)' },
            sell_region: { region_id: 10000043, region_name: 'Domain (Amarr)' },
            freshness,
            freshness_by_region: { '10000002': freshness, '10000043': { ...freshness, stale: true } },
            pagination: { page: 1, per_page: 200, total: 250, last_page: 2 },
          },
        },
      ],
    ])

    expect(screen.getByText('The Forge (Jita) → Domain (Amarr)')).toBeInTheDocument()
    expect(screen.getByText(/Sell orders as of/)).toHaveTextContent('(stale)')
    expect(screen.getByText('Page 1 of 2 (250 results)')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
  })
})
