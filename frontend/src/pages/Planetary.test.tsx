import { fireEvent, screen } from '@testing-library/react'
import { me, renderWithData } from '../test/render'
import Planetary, { extractorProgress, groupFactories, timeRemaining } from './Planetary'

const planet = (attrs: object) => ({
  planet_id: 1,
  planet_name: 'Jita I',
  planet_type: 'barren',
  upgrade_level: 4,
  num_pins: 5,
  extractor_count: 1,
  factory_count: 2,
  storage_count: 0,
  launchpad_count: 1,
  route_count: 1,
  alerts: [{ type: 'extractor_expired', severity: 'warning', message: 'Extractor for Aqueous Liquids has expired' }],
  outputs: [{ type_id: 2268, name: 'Aqueous Liquids', icon_url: 'http://i', per_hour: 400 }],
  extractors: [
    {
      product_type_id: 2268,
      product_name: 'Aqueous Liquids',
      icon_url: 'http://i',
      per_hour: 400,
      is_active: false,
      install_time: null,
      expiry_time: null,
    },
  ],
  factories: [{ schematic_id: 121, schematic_name: 'Water', cycle_time: 1800 }],
  storages: [
    {
      kind: 'launchpad',
      capacity: 10000,
      used_volume: 2500,
      usage_percent: 25,
      items: [{ type_id: 2268, name: 'Aqueous Liquids', icon_url: 'http://i', amount: 1500 }],
    },
  ],
  ...attrs,
})

function response(planets: object[]) {
  return {
    data: {
      summary: { characters: 1, planets: planets.length, extractors: 1, factories: 2 },
      outputs: [{ type_id: 2268, name: 'Aqueous Liquids', icon_url: 'http://i', per_hour: 400, planets: 1 }],
      alerts: [
        {
          type: 'extractor_expired',
          severity: 'warning',
          message: 'Extractor for Aqueous Liquids has expired',
          character_name: 'Ana',
          planet_name: 'Jita I',
        },
      ],
      capacity: [
        { character_name: 'Ana', planet_name: 'Jita I', planet_type: 'barren', extractor_count: 1, factory_count: 2, status: 'under_capacity' },
      ],
      characters: [{ character_id: 1, character_name: 'Ana', portrait_url: null, planets }],
    },
    meta: {},
  }
}

describe('Planetary', () => {
  it('shows totals, alerts, outputs, capacity and the colonies', () => {
    renderWithData(<Planetary />, [
      [['planetary'], response([planet({})])],
      [['me'], me(['esi-planets.manage_planets.v1'])],
    ])
    expect(screen.getByText('Planets', { selector: 'dt' })).toBeInTheDocument()
    expect(screen.getByText('Alerts (1)')).toBeInTheDocument()
    expect(screen.getByText('Under capacity')).toBeInTheDocument()
    
    expect(screen.getByText('9,600')).toBeInTheDocument()
    expect(screen.getByText('Jita I', { selector: 'strong' })).toBeInTheDocument()
    expect(screen.getByText('Inactive')).toBeInTheDocument()
    expect(screen.getByText('00:30:00 cycle')).toBeInTheDocument()
    expect(screen.getByText('1,500')).toBeInTheDocument()
    expect(screen.getByText(/2,500.0 \/ 10,000 m³ \(25%\)/)).toBeInTheDocument()
  })

  it('filters colonies by resource and says when nothing matches', () => {
    renderWithData(<Planetary />, [
      [['planetary'], response([planet({}), planet({ planet_id: 2, planet_name: 'Jita II', outputs: [], extractors: [], factories: [], storages: [] })])],
    ])
    const filter = screen.getByLabelText(/Filter by resource/)
    fireEvent.change(filter, { target: { value: 'water' } })
    expect(screen.getByText('Jita I', { selector: 'strong' })).toBeInTheDocument()
    expect(screen.queryByText('Jita II', { selector: 'strong' })).not.toBeInTheDocument()
    fireEvent.change(filter, { target: { value: 'nothing' } })
    expect(screen.getByText('No planets match your search.')).toBeInTheDocument()
  })

  it('explains an empty account and warns about the missing scope', () => {
    const empty = response([])
    empty.data.characters = []
    renderWithData(<Planetary />, [
      [['planetary'], empty],
      [['me'], me(['esi-assets.read_assets.v1'])],
    ])
    expect(screen.getByText('No planetary interaction data found.')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('esi-planets.manage_planets.v1')
  })

  it('renders a legacy backend payload without crashing', () => {
    const legacy = response([planet({})])
    const [p] = legacy.data.characters[0].planets as Array<Record<string, unknown>>
    delete p.storages
    delete p.extractor_count
    renderWithData(<Planetary />, [[['planetary'], legacy]])
    expect(screen.getByText('Planetary interaction')).toBeInTheDocument()
    expect(screen.getByText('Jita I', { selector: 'strong' })).toBeInTheDocument()
  })
})

describe('extractor timing', () => {
  const now = new Date('2026-10-23T12:00:00Z')
  it('formats the time left', () => {
    expect(timeRemaining('2026-10-23T15:20:30Z', now)).toBe('3h 20m')
    expect(timeRemaining('2026-10-23T12:00:20Z', now)).toBe('1m')
    expect(timeRemaining('2026-10-23T11:59:00Z', now)).toBeNull()
  })
  it('measures progress through the program', () => {
    expect(extractorProgress({ install_time: '2026-10-23T10:00:00Z', expiry_time: '2026-10-23T14:00:00Z' }, now)).toBe(50)
    expect(extractorProgress({ install_time: null, expiry_time: '2026-10-23T14:00:00Z' }, now)).toBe(0)
  })
})

describe('groupFactories', () => {
  it('collapses identical schematics into a count', () => {
    const water = { schematic_id: 121, schematic_name: 'Water', cycle_time: 1800 }
    const oxygen = { schematic_id: 122, schematic_name: 'Oxygen', cycle_time: 1800 }
    expect(groupFactories([water, water, oxygen, water])).toEqual([
      { ...water, count: 3 },
      { ...oxygen, count: 1 },
    ])
  })
})
