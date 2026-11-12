import { fireEvent, screen } from '@testing-library/react'
import { me, renderWithData } from '../test/render'
import MoonScan, { formatMoonIsk, summarise } from './MoonScan'

const demoMe = () => {
  const m = me([])
  return { data: { ...m.data, demo: true }, meta: m.meta }
}

const systems = (names: string[]) => ({
  data: names.map((name) => ({ name, region_id: 10000002, pricing_mode: 'raw', last_scanned_at: '2026-11-12T06:47:16Z' })),
  meta: { pagination: { page: 1, per_page: 200, total: names.length, last_page: 1 } },
})

const moon = (attrs: object) => ({
  id: 1,
  name: 'Aldali II - Moon 1',
  grade: 'S',
  total_value: 2_500_000_000,
  ores: [],
  occupied: false,
  occupying_corporation_id: null,
  occupying_corporation_name: null,
  ...attrs,
})

describe('MoonScan', () => {
  it('offers the stored systems and says so when there are none', () => {
    renderWithData(<MoonScan />, [
      [['moon-scans', 'systems'], { data: [], meta: { pagination: { page: 1, per_page: 200, total: 0, last_page: 1 } } }],
    ])
    expect(screen.getByText('Moon scan analyser')).toBeInTheDocument()
    expect(screen.getByText('Your corporation has no stored scans yet.')).toBeInTheDocument()
    expect(screen.getByText('Paste moon scan data and click Analyse moons to see results.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Analyse moons' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Load saved scan' })).toBeDisabled()
  })

  it('finds a stored system by typing part of its name', () => {
    renderWithData(<MoonScan />, [
      [
        ['moon-scans', 'systems'],
        {
          data: [
            { name: 'Aldali', region_id: 10000002, pricing_mode: 'raw', last_scanned_at: null },
            { name: 'Moro', region_id: 10000002, pricing_mode: 'raw', last_scanned_at: null },
          ],
          meta: { pagination: { page: 1, per_page: 200, total: 2, last_page: 1 } },
        },
      ],
    ])
    fireEvent.change(screen.getByLabelText('Load previously scanned system'), { target: { value: 'ald' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aldali' }))
    expect(screen.getByRole('button', { name: 'Load saved scan' })).toBeEnabled()
  })

  it('auto-loads the first stored system for the demo account', async () => {
    renderWithData(<MoonScan />, [
      [['me'], demoMe()],
      [['moon-scans', 'systems'], systems(['Tama', 'Amamake'])],
    ])
    expect(await screen.findByText('Failed to load system scan. Please try again.')).toBeInTheDocument()
  })

  it('does not auto-load for non-demo accounts', () => {
    renderWithData(<MoonScan />, [
      [['me'], me([])],
      [['moon-scans', 'systems'], systems(['Tama'])],
    ])
    expect(screen.getByText('Paste moon scan data and click Analyse moons to see results.')).toBeInTheDocument()
    expect(screen.queryByText('Analysing moons…')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})

describe('summarise', () => {
  const moons = [moon({ id: 1 }), moon({ id: 2, grade: 'A', occupied: true }), moon({ id: 3, grade: 'S' })] as never

  it('counts grades and hides occupied moons on request', () => {
    expect(summarise(moons, false).byGrade('S')).toBe(2)
    const hidden = summarise(moons, true)
    expect(hidden.visible).toHaveLength(2)
    expect(hidden.hidden).toBe(1)
    expect(hidden.byGrade('A')).toBe(0)
  })
})

describe('formatMoonIsk', () => {
  it('uses T, B, M and K', () => {
    expect(formatMoonIsk(1_500_000_000_000)).toBe('1.50T')
    expect(formatMoonIsk(2_500_000_000)).toBe('2.50B')
    expect(formatMoonIsk(42_300)).toBe('42.30K')
  })
})
