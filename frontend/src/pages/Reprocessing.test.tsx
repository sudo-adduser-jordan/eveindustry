import { fireEvent, screen } from '@testing-library/react'
import { formatIsk } from '../components/format'
import { me, renderWithData } from '../test/render'
import Reprocessing from './Reprocessing'

const item = {
  type_id: 1,
  name: 'Scrap Plate',
  quantity: 10,
  market_value: 1_000_000,
  reprocess_value: 2_500_000,
  profit: 1_500_000,
  profit_pct: 150,
  unit_market_price: 100_000,
  materials: [{ type_id: 34, name: 'Tritanium', quantity: 500, unit_price: 5000, total_value: 2_500_000 }],
  icon_url: 'http://i',
}

function response(items: (typeof item)[]) {
  return {
    data: {
      characters: [
        {
          character_id: 1,
          character_name: 'Ana',
          portrait_url: null,
          corporation_name: 'Test Corp',
          yield_pct: 54.3,
          skills: { reprocessing: 5, reprocessing_efficiency: 4, scrapmetal_processing: 0 },
          locations:
            items.length === 0
              ? []
              : [
                  {
                    location_id: 60003760,
                    location_name: 'Jita IV - Moon 4',
                    items,
                    total_market_value: 1_000_000,
                    total_reprocess_value: 2_500_000,
                    total_profit: 1_500_000,
                  },
                ],
          total_profit: items.length === 0 ? 0 : 1_500_000,
        },
      ],
      totals: { profit: items.length === 0 ? 0 : 1_500_000, characters: 1, items: items.length },
    },
    meta: { freshness: { prices_as_of: null, costs_as_of: null, source: 'none', stale: true } },
  }
}

describe('Reprocessing', () => {
  it('shows totals and the profitable items per location', () => {
    renderWithData(<Reprocessing />, [
      [['industry', 'reprocessing'], response([item])],
      [['me'], me(['esi-assets.read_assets.v1'])],
    ])
    expect(screen.getByText('1.50M ISK', { selector: 'dd' })).toBeInTheDocument()
    expect(screen.getByText(/Yield 54.3%/)).toBeInTheDocument()
    fireEvent.click(screen.getByText('Jita IV - Moon 4'))
    expect(screen.getByText('Scrap Plate')).toHaveAttribute('title', '500 Tritanium')
    expect(screen.getByText('+150%')).toBeInTheDocument()
    
    expect(screen.getByText('Prices: no data yet')).toBeInTheDocument()
  })

  it('says so when nothing is worth reprocessing', () => {
    renderWithData(<Reprocessing />, [[['industry', 'reprocessing'], response([])]])
    expect(screen.getByText(/No items found that are worth more reprocessed/)).toBeInTheDocument()
    expect(screen.getByText('No items')).toBeInTheDocument()
  })
})

describe('formatIsk', () => {
  it('abbreviates like the game', () => {
    expect(formatIsk(1_234_000_000)).toBe('1.23B')
    expect(formatIsk(45_600_000)).toBe('45.60M')
    expect(formatIsk(7_890)).toBe('7.89K')
    expect(formatIsk(12.3)).toBe('12.30')
    expect(formatIsk(-2_000_000)).toBe('-2.00M')
  })
})
