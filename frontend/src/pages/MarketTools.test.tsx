import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { vi } from 'vitest'
import { api } from '../api/client'
import { renderWithData } from '../test/render'
import ContractAppraisal from './ContractAppraisal'
import ContractBrowser from './ContractBrowser'
import ContractDetail from './ContractDetail'
import MarketBrowser from './MarketBrowser'
import Structures, { toTimerRequest } from './Structures'

afterEach(() => vi.restoreAllMocks())

const freshness = { prices_as_of: '2026-10-23T12:00:00+00:00', costs_as_of: null, source: 'public_contracts', stale: false }
const pagination = { page: 1, per_page: 50, total: 2, last_page: 1 }

const contract = (attrs: object) => ({
  contract_id: 1,
  type: 'item_exchange',
  title: 'Blueprint pack',
  region_id: 10000002,
  issuer_id: 9,
  price: 1500.5,
  volume: 10,
  date_issued: '2026-10-20T10:00:00+00:00',
  date_expired: '2026-10-27T10:00:00+00:00',
  items_count: 3,
  ...attrs,
})

describe('ContractBrowser', () => {
  const key = ['market', 'contracts', { per_page: 50, page: 1 }] as const
  const data = {
    data: [contract({ contract_id: 11 }), contract({ contract_id: 12, type: 'courier', title: null, price: 0 })],
    meta: { pagination, freshness, stats: { total: 1234, item_exchange: 1000, auction: 4, courier: 230 } },
  }

  it('shows stats, contracts and links to the detail page', () => {
    renderWithData(<ContractBrowser />, [[key, data]])

    expect(screen.getByText('1,234')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '11' })).toHaveAttribute('href', '/tools/contract-browser/11')
    const rows = screen.getAllByRole('row').slice(1)
    expect(rows).toHaveLength(2)
    expect(within(rows[0]!).getByText('1,500.50')).toBeInTheDocument()
    
    expect(within(rows[1]!).getAllByText('—').length).toBeGreaterThanOrEqual(2)
  })

  it('offers Clear only when a filter is applied', () => {
    renderWithData(<ContractBrowser />, [[key, data]])
    expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument()
  })

  it('says so when a filter matches nothing', () => {
    const empty = { data: [], meta: { ...data.meta, pagination: { ...pagination, total: 0 } } }
    renderWithData(<ContractBrowser />, [[['market', 'contracts', { type: 'auction', per_page: 50, page: 1 }], empty]], [
      '/?type=auction',
    ])
    expect(screen.getByText('No contracts match your filters.')).toBeInTheDocument()
  })
})

describe('ContractDetail', () => {
  const detail = {
    data: {
      ...contract({ contract_id: 7, title: null }),
      buyout: null,
      reward: 250000,
      collateral: null,
      items: [
        {
          type_id: 34,
          type_name: 'Tritanium',
          quantity: 100,
          is_included: true,
          is_blueprint_copy: false,
          material_efficiency: null,
          time_efficiency: null,
          runs: null,
        },
        {
          type_id: 999,
          type_name: 'Unknown Type #999',
          quantity: 1,
          is_included: false,
          is_blueprint_copy: true,
          material_efficiency: 10,
          time_efficiency: 20,
          runs: 5,
        },
      ],
    },
    meta: {},
  }

  it('lists items with their role and blueprint details, and only the courier amounts that exist', () => {
    renderWithData(
      <Routes>
        <Route path="/tools/contract-browser/:contractId" element={<ContractDetail />} />
      </Routes>,
      [[['market', 'contracts', 7], detail]],
      ['/tools/contract-browser/7'],
    )

    expect(screen.getByRole('heading', { name: 'Contract 7' })).toBeInTheDocument()
    expect(screen.getByText('Reward')).toBeInTheDocument()
    expect(screen.queryByText('Buyout')).not.toBeInTheDocument()
    const rows = screen.getAllByRole('row').slice(1)
    expect(within(rows[0]!).getByText('Offered')).toBeInTheDocument()
    expect(within(rows[1]!).getByText('Requested')).toBeInTheDocument()
    expect(within(rows[1]!).getByText('BPC')).toBeInTheDocument()
    expect(within(rows[1]!).getByText('20')).toBeInTheDocument()
  })
})

describe('ContractAppraisal', () => {
  it('posts the pasted items with the chosen options and shows lines, totals and unmatched names', async () => {
    const post = vi.spyOn(api, 'POST').mockResolvedValue({
      data: {
        data: {
          items: [
            { type_id: 34, name: 'Tritanium', quantity: 3, unit_price: 5500, line_total: 16500, contracts_found: 2 },
            { type_id: 35, name: 'Pyerite', quantity: 1, unit_price: 0, line_total: 0, contracts_found: 0 },
          ],
          total_value: 16500,
          unmatched: ['Nope'],
        },
        meta: { freshness },
      },
      response: new Response(),
    } as never)
    renderWithData(<ContractAppraisal />)

    expect(screen.getByRole('button', { name: 'Appraise' })).toBeDisabled()
    fireEvent.change(screen.getByLabelText('Items'), { target: { value: 'Tritanium\nNope' } })
    fireEvent.click(screen.getByLabelText('Lowest'))
    fireEvent.click(screen.getByLabelText('Sell'))
    fireEvent.click(screen.getByRole('button', { name: 'Appraise' }))

    expect(await screen.findByText('16.50K ISK', { selector: 'dd' })).toBeInTheDocument()
    expect(post).toHaveBeenCalledWith('/market/appraisals', { body: { items: 'Tritanium\nNope', filter: 'sell', method: 'lowest' } })
    expect(screen.getByText('no contracts')).toBeInTheDocument()
    expect(screen.getByText('Nope')).toBeInTheDocument()
  })
})

describe('MarketBrowser', () => {
  const group = (id: number, name: string, extra: object = {}) => ({
    market_group_id: id,
    name,
    description: null,
    has_types: false,
    child_count: 2,
    ...extra,
  })

  it('lists the root groups', () => {
    renderWithData(<MarketBrowser />, [[['reference', 'market-groups'], { data: [group(4, 'Ships')], meta: {} }]])
    expect(screen.getByRole('link', { name: 'Ships' })).toHaveAttribute('href', '/?group=4')
  })

  it('shows breadcrumbs, subgroups and items of a group', () => {
    const contents = {
      data: {
        ...group(5, 'Frigates', { parent_group_id: 4 }),
        breadcrumbs: [
          { market_group_id: 4, name: 'Ships' },
          { market_group_id: 5, name: 'Frigates' },
        ],
        children: [group(6, 'Standard Frigates')],
        items: [{ type_id: 587, name: 'Rifter', market_group_id: 5, icon_url: 'http://i' }],
      },
      meta: {},
    }
    renderWithData(<MarketBrowser />, [[['reference', 'market-groups', 5], contents]], ['/?group=5'])

    const crumbs = screen.getByRole('navigation', { name: 'Breadcrumbs' })
    expect(within(crumbs).getByRole('link', { name: 'Ships' })).toBeInTheDocument()
    expect(within(crumbs).getByText(/Frigates/)).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Standard Frigates' })).toBeInTheDocument()
    expect(screen.getByText('Rifter')).toBeInTheDocument()
  })

  it('shows search results and the cap notice', () => {
    const results = { data: [{ type_id: 587, name: 'Rifter', market_group_id: 5, icon_url: 'http://i' }], meta: { limit: 1 } }
    renderWithData(<MarketBrowser />, [[['reference', 'types', 'rif'], results]], ['/?q=rif'])

    expect(screen.getByText('Rifter')).toBeInTheDocument()
    expect(screen.getByText(/narrow the search/)).toBeInTheDocument()
  })
})

describe('Structures', () => {
  const structure = (attrs: object) => ({
    id: 1,
    activity_type: 'manufacturing',
    name: 'My Azbel',
    structure_type: 'azbel',
    rig_me: 't2',
    rig_te: 'none',
    system_security: 'lowsec',
    solar_system_id: 30000142,
    solar_system_name: 'Jita',
    tax_rate: 1.5,
    is_default: true,
    ...attrs,
  })
  const list = {
    data: [structure({}), structure({ id: 2, name: 'Athanor', activity_type: 'reaction', structure_type: 'athanor', is_default: false, rig_me: 'none', solar_system_id: null, solar_system_name: null, tax_rate: 0 })],
    meta: { pagination: { page: 1, per_page: 200, total: 2, last_page: 1 } },
  }
  const key = ['structures'] as const

  it('groups structures by activity with their tags', () => {
    renderWithData(<Structures />, [[key, list]])

    expect(screen.getByRole('heading', { name: 'Manufacturing' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Reaction' })).toBeInTheDocument()
    expect(screen.getByText('★ Default')).toBeInTheDocument()
    expect(screen.getByText('ME T2')).toBeInTheDocument()
    expect(screen.getByText('Jita')).toBeInTheDocument()
    expect(screen.getByText('Tax 1.50%')).toBeInTheDocument()
    
    expect(screen.getAllByRole('button', { name: 'Make default' })).toHaveLength(1)
  })

  it('swaps to refineries for reactions and posts the new structure', async () => {
    const post = vi.spyOn(api, 'POST').mockResolvedValue({ data: { data: structure({ id: 3 }), meta: {} }, response: new Response() } as never)
    renderWithData(<Structures />, [[key, { data: [], meta: list.meta }]])

    expect(screen.getByText('No structures yet. Add your first one with the form.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Activity'), { target: { value: 'reaction' } })
    expect(screen.getByLabelText('Structure type')).toHaveValue('athanor')
    expect(screen.queryByRole('option', { name: /Raitaru/ })).not.toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Moon refinery' } })
    fireEvent.change(screen.getByLabelText('ME rig'), { target: { value: 't1' } })
    fireEvent.change(screen.getByLabelText('Structure tax rate %'), { target: { value: '2.5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save structure' }))

    await waitFor(() => expect(post).toHaveBeenCalled())
    expect(post).toHaveBeenCalledWith('/structures', {
      body: {
        activity_type: 'reaction',
        name: 'Moon refinery',
        structure_type: 'athanor',
        rig_me: 't1',
        rig_te: 'none',
        system_security: 'highsec',
        solar_system_id: null,
        tax_rate: 2.5,
        is_default: false,
      },
    })
  })

  it('loads a structure into the form to edit, with its system selected', () => {
    renderWithData(<Structures />, [[key, list]])

    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0]!)

    expect(screen.getByRole('heading', { name: 'Edit My Azbel' })).toBeInTheDocument()
    expect(screen.getByLabelText('Name')).toHaveValue('My Azbel')
    expect(screen.getByLabelText('Structure type')).toHaveValue('azbel')
    expect(screen.getByRole('button', { name: 'Clear system' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Update structure' })).toBeInTheDocument()
  })

  it('does not delete when the confirmation is declined', () => {
    const del = vi.spyOn(api, 'DELETE')
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderWithData(<Structures />, [[key, list]])

    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' })[0]!)
    expect(del).not.toHaveBeenCalled()
  })

  it('opens a prefilled timer form on each card and posts the timer', async () => {
    const post = vi.spyOn(api, 'POST').mockResolvedValue({ data: { data: 1, meta: {} }, response: new Response() } as never)
    renderWithData(<Structures />, [[key, list]])

    expect(screen.getAllByRole('button', { name: 'Add timer' })).toHaveLength(2)
    fireEvent.click(screen.getAllByRole('button', { name: 'Add timer' })[0]!)

    expect(screen.getByRole('form', { name: 'Add vulnerability timer for My Azbel' })).toBeInTheDocument()
    expect(screen.getByText('Jita', { selector: 'strong' })).toBeInTheDocument()
    expect(screen.getByLabelText('Start time (UTC)')).toHaveValue('18:00')

    fireEvent.click(screen.getByRole('button', { name: 'Save timer' }))

    await waitFor(() => expect(post).toHaveBeenCalled())
    expect(post).toHaveBeenCalledWith('/corporation/structure-vulnerability', {
      body: {
        solar_system_id: 30000142,
        solar_system_name: 'Jita',
        structure_type: 'upwell',
        structure_name: 'My Azbel',
        day_of_week: 0,
        start_time_utc: '18:00',
        duration_minutes: 60,
        reinforce_exit_at: null,
        notes: null,
      },
    })
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Vulnerability timer added.'))
  })

  it('asks for a system when the structure has none and builds timer requests', () => {
    renderWithData(<Structures />, [[key, list]])

    fireEvent.click(screen.getAllByRole('button', { name: 'Add timer' })[1]!)
    expect(screen.getByText(/has no system set/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save timer' })).toBeDisabled()

    expect(
      toTimerRequest(' Hook ', { system: { id: 1, name: 'Jita' }, type: 'skyhook', day: '3', start: '06:00', duration: '30', exit: '', notes: '' }),
    ).toMatchObject({ structure_name: 'Hook', day_of_week: null, reinforce_exit_at: null, notes: null })
    expect(
      toTimerRequest('Hook', { system: null, type: 'upwell', day: '3', start: '06:00', duration: '30', exit: '', notes: '' }),
    ).toBeNull()
  })
})
