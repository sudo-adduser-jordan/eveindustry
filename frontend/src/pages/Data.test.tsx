import { screen } from '@testing-library/react'
import { renderWithData } from '../test/render'
import Data from './Data'

const freshness = {
  data: [
    {
      region_id: 10000002,
      region_name: 'The Forge',
      orders_as_of: '2026-10-23T12:00:00+00:00',
      costs_as_of: null,
      global_as_of: '2026-10-23T11:00:00+00:00',
      stale: false,
    },
  ],
  meta: {},
}

const contracts = {
  data: [],
  meta: {
    pagination: { page: 1, per_page: 1, total: 0, last_page: 1 },
    stats: { total: 12345, item_exchange: 12000, auction: 45, courier: 300 },
    freshness: { prices_as_of: '2026-10-23T12:00:00+00:00', costs_as_of: null, source: 'public_contracts', stale: false },
  },
}

const meData = {
  data: {
    id: 1,
    display_name: 'Pilot',
    characters: [
      { character_id: 1, name: 'Pilot', corporation_name: 'Test Corp', is_main: true, is_esi_active: true, cached_location: 'Jita IV', cached_training: 'Caldari Frigate V' },
      { character_id: 2, name: 'Alt', corporation_name: null, is_main: false, is_esi_active: false, cached_location: null, cached_training: null },
    ],
  },
  meta: {},
}

const seed: Array<[readonly unknown[], unknown]> = [
  [['market', 'freshness'], freshness],
  [['market', 'contracts', { per_page: 1 }], contracts],
  [['me'], meData],
]

describe('Data', () => {
  it('shows market, contract and character sections', () => {
    renderWithData(<Data />, seed)

    expect(screen.getByRole('heading', { name: 'Data' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Market data' })).toBeInTheDocument()
    expect(screen.getByText('The Forge')).toBeInTheDocument()

    expect(screen.getByRole('heading', { name: 'Contract data' })).toBeInTheDocument()
    expect(screen.getByText('12,345')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'contract browser' })).toHaveAttribute('href', '/tools/contract-browser')

    expect(screen.getByRole('heading', { name: 'Character data' })).toBeInTheDocument()
    expect(screen.getByText('Pilot')).toBeInTheDocument()
    expect(screen.getByText('Test Corp')).toBeInTheDocument()
    expect(screen.getByText('Connected')).toBeInTheDocument()
    expect(screen.getByText('Renewal needed')).toBeInTheDocument()
    expect(screen.getByText('Jita IV')).toBeInTheDocument()
  })

  it('explains missing data per section', () => {
    renderWithData(<Data />, [
      [['market', 'freshness'], { data: [], meta: {} }],
      [['market', 'contracts', { per_page: 1 }], { data: [], meta: { ...contracts.meta, stats: null } }],
      [['me'], { data: { id: 1, display_name: 'Pilot', characters: [] }, meta: {} }],
    ])

    expect(screen.getByText('No characters linked yet.')).toBeInTheDocument()
  })
})
