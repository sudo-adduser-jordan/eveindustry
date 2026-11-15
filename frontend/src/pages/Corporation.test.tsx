import { render, screen } from '@testing-library/react'
import { ApiError } from '../api/client'
import { ErrorNotice } from '../components/CorpCommon'
import { me, renderWithData } from '../test/render'
import BuildPrograms, { progressClass } from './CorporateBuildPrograms'
import { asSteps, groupSteps } from './BuildProgramDetail'
import Donations, { leaderboardText } from './Donations'
import ProjectContributors from './ProjectContributors'
import Projects from './Projects'

const meta = { pagination: { page: 1, per_page: 200, total: 1, last_page: 1 } }
const seedMe: [readonly unknown[], unknown] = [['me'], me(['esi-wallet.read_corporation_wallets.v1', 'esi-corporations.read_projects.v1'])]

const status = (over: object = {}) => ({
  data: {
    enabled: true,
    enabled_by_me: false,
    corporation_name: 'Test Corp',
    provider_character_name: 'Banker',
    can_enable: false,
    wallet_characters: [],
    ...over,
  },
  meta: {},
})

describe('Donations', () => {
  const donations = {
    data: [
      {
        donator_id: 111,
        donator_name: 'Alice',
        portrait_url: 'https://images.evetech.net/characters/111/portrait?size=64',
        total_amount: 1500000,
        donation_count: 2,
        donations: [
          { id: 1, date: '2026-10-20 10:00:00', amount: 1000000, reason: 'thanks', wallet_division: 1, wallet_name: 'Master Wallet' },
          { id: 2, date: '2026-10-19 10:00:00', amount: 500000, reason: '', wallet_division: 1, wallet_name: 'Master Wallet' },
        ],
      },
    ],
    meta: {
      summary: {
        corporation_id: 1,
        corporation_name: 'Test Corp',
        date_from: '2026-09-23',
        date_to: '2026-10-23',
        total: 1500000,
        wallet_divisions: [
          { division: 1, name: 'Master Wallet', balance: 5 },
          { division: 2, name: 'Division 2', balance: 6 },
        ],
      },
      pagination: meta.pagination,
    },
  }

  it('shows the total, donators and the division filter', () => {
    renderWithData(<Donations />, [seedMe, [['corp', 'donations', 'status'], status({ enabled_by_me: true })], [['corp', 'donations', {}], donations]])

    expect(screen.getByText('1,500,000 ISK', { selector: 'strong' })).toBeInTheDocument()
    expect(screen.getByText('Alice')).toBeInTheDocument()
    expect(screen.getByText(/Grand total \(1 donator\)/)).toBeInTheDocument()
    expect(screen.getByLabelText('Division')).toBeInTheDocument()
    expect(screen.getByLabelText('From')).toHaveValue('2026-09-23')
    expect(screen.getByText('View 2 individual donations')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Disable' })).toBeInTheDocument()
  })

  it('only offers Disable to the user who enabled tracking', () => {
    renderWithData(<Donations />, [seedMe, [['corp', 'donations', 'status'], status()], [['corp', 'donations', {}], donations]])
    expect(screen.queryByRole('button', { name: 'Disable' })).not.toBeInTheDocument()
  })

  it('offers to enable tracking with a wallet character', () => {
    renderWithData(<Donations />, [
      seedMe,
      [
        ['corp', 'donations', 'status'],
        status({ enabled: false, can_enable: true, wallet_characters: [{ character_id: 5, name: 'Banker', corporation_name: 'Test Corp' }] }),
      ],
    ])
    expect(screen.getByText('Donation tracking not enabled')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enable donation tracking' })).toBeInTheDocument()
    expect(screen.getByText('Banker')).toBeInTheDocument()
  })

  it('explains what is needed when nobody can enable tracking', () => {
    renderWithData(<Donations />, [seedMe, [['corp', 'donations', 'status'], status({ enabled: false })]])
    expect(screen.getByText(/No one in your corporation has enabled donation tracking/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enable donation tracking' })).not.toBeInTheDocument()
  })

  it('formats the leaderboard for chat', () => {
    expect(leaderboardText([{ donator_name: 'Alice', total_amount: 1500000 }, { donator_name: 'Bob', total_amount: 20 }])).toBe(
      '1 - Alice - 1,500,000 ISK\n2 - Bob - 20 ISK',
    )
  })
})

describe('ErrorNotice', () => {
  it('shows the 403 message that says what to fix', () => {
    render(<ErrorNotice error={new ApiError(403, { message: 'No corporation associated with your account.', code: 'forbidden' })} what="the tracker" unavailable="Tracker unavailable:" />)
    expect(screen.getByRole('alert')).toHaveTextContent('Tracker unavailable: No corporation associated with your account.')
  })

  it('is generic for other failures', () => {
    render(<ErrorNotice error={new ApiError(500, undefined)} what="the tracker" unavailable="x" />)
    expect(screen.getByRole('alert')).toHaveTextContent('Could not load the tracker.')
  })
})

describe('Projects', () => {
  const project = (over: object) => ({
    id: 'p1',
    name: 'Project One',
    state: 'Active',
    last_modified: '2026-10-20T08:30:00+00:00',
    progress: { current: 25, desired: 100 },
    completion_pct: 25,
    is_complete: false,
    reward: { initial: 4_000_000, remaining: 3_000_000 },
    reward_earned: 1_000_000,
    contributors: [{ character_id: 111, character_name: 'Alice', contributed: 25 }],
    ...over,
  })

  it('summarises and lists projects', () => {
    renderWithData(<Projects />, [
      seedMe,
      [
        ['corp', 'projects'],
        {
          data: [project({}), project({ id: 'p2', name: 'Done', is_complete: true, completion_pct: 100, reward: null, contributors: [] })],
          meta: {
            summary: {
              corporation_id: 1,
              corporation_name: 'Test Corp',
              total_projects: 2,
              completed_projects: 1,
              in_progress_projects: 1,
              total_progress: 125,
              total_desired: 200,
              overall_completion_pct: 62.5,
              total_reward_initial: 4_000_000,
              total_reward_remaining: 3_000_000,
              total_reward_earned: 1_000_000,
            },
            pagination: meta.pagination,
          },
        },
      ],
    ])

    expect(screen.getByText('Analytics and performance for Test Corp')).toBeInTheDocument()
    expect(screen.getByText('Project One')).toBeInTheDocument()
    expect(screen.getByText('Complete')).toBeInTheDocument()
    expect(screen.getByText('1.0M', { selector: 'dd' })).toBeInTheDocument()
    expect(screen.getByText('62.5%')).toBeInTheDocument()
    expect(screen.getByText('3.0M ISK remaining of 4.0M ISK')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Contributor leaderboard' })).toBeInTheDocument()
  })
})

describe('ProjectContributors', () => {
  it('ranks contributors and shows their share', () => {
    renderWithData(<ProjectContributors />, [
      seedMe,
      [
        ['corp', 'projects', 'contributors', {}],
        {
          data: [
            { character_id: 222, character_name: 'Bob', project_count: 2, attributed_isk: 900, attributed_pct: 60, projects: [{ id: 'a', name: 'Alpha', quantity: 9, attributed_isk: 900 }] },
            { character_id: null, character_name: null, project_count: 1, attributed_isk: 600, attributed_pct: 40, projects: [] },
          ],
          meta: {
            summary: { corporation_name: 'Test Corp', total_attributed_isk: 1500, total_rewarded_projects: 2, date_from: null, date_to: null, project_name: null },
            pagination: meta.pagination,
          },
        },
      ],
    ])

    expect(screen.getByText('Bob')).toBeInTheDocument()
    expect(screen.getByText('Character #?')).toBeInTheDocument()
    expect(screen.getByText('900.00 ISK', { selector: 'td' })).toBeInTheDocument()
    expect(screen.getByText('60.0%')).toBeInTheDocument()
    expect(screen.getByText('1,500.00 ISK')).toBeInTheDocument()
    expect(screen.getByText('Alpha')).toBeInTheDocument()
  })
})

describe('BuildPrograms', () => {
  const overview = (over: object) => ({
    data: {
      enabled: true,
      enabled_by_me: false,
      corporation_name: 'Test Corp',
      provider_character_name: 'Director',
      can_view: true,
      can_manage: true,
      eligible_characters: [],
      programs: [],
      ...over,
    },
    meta: {},
  })
  const program = {
    id: 7,
    name: 'Corp Rifters',
    program_type: 'manufacturing',
    t2_type_id: 587,
    t2_type_name: 'Rifter',
    step_count: 3,
    input_location_ids: [60003760],
    output_location_ids: [],
    updated_at: '2026-10-21T10:00:00+00:00',
  }

  it('lists programs with their filters and progress', () => {
    renderWithData(<BuildPrograms />, [
      [['corp', 'build-programs'], overview({ programs: [program, { ...program, id: 8, name: 'Other', step_count: 1, input_location_ids: [] }] })],
      [['corp', 'build-programs', 7, 'progress', [60003760], []], { data: { overall_progress: 42, steps: [], sections: [] }, meta: {} }],
    ])

    expect(screen.getByRole('link', { name: 'Corp Rifters' })).toHaveAttribute('href', '/corporate/build-programs/7')
    expect(screen.getByText('3 production steps')).toBeInTheDocument()
    expect(screen.getByText('Filtered by saved locations')).toBeInTheDocument()
    expect(screen.getByText('42% of the stock is in place')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Merge' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete Corp Rifters' })).toBeInTheDocument()
  })

  it('hides manage actions from plain members', () => {
    renderWithData(<BuildPrograms />, [
      [['corp', 'build-programs'], overview({ can_manage: false, programs: [program] })],
      [['corp', 'build-programs', 7, 'progress', [60003760], []], { data: { overall_progress: 100, steps: [], sections: [] }, meta: {} }],
    ])
    expect(screen.queryByRole('button', { name: 'Merge' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Delete/ })).not.toBeInTheDocument()
  })

  it('offers eligible directors the enable form', () => {
    renderWithData(<BuildPrograms />, [
      [['corp', 'build-programs'], overview({ enabled: false, can_view: false, can_manage: false, eligible_characters: [{ character_id: 5, name: 'Director', corporation_name: 'Test Corp' }] })],
    ])
    expect(screen.getByText('Corporate build programs not enabled')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enable corporate build programs' })).toBeInTheDocument()
  })

  it('says what is missing when nobody is eligible', () => {
    renderWithData(<BuildPrograms />, [[['corp', 'build-programs'], overview({ enabled: false, can_view: false, can_manage: false })]])
    expect(screen.getByText(/No eligible characters found/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Manage characters' })).toBeInTheDocument()
  })

  it('colours progress and slices steps into sections', () => {
    expect([progressClass(100), progressClass(50), progressClass(10)]).toEqual(['bar-good', 'bar-manufacturing', 'bar-research'])
    const step = (name: string) => ({ type_id: 1, name, quantity_required: 1, runs_needed: null, activity_type: null, materials: [], children: [] })
    const steps = asSteps([step('a'), step('b'), step('c')])
    const groups = groupSteps(steps, [{ name: 'One', step_count: 1 }, { name: 'Two', step_count: 2 }])
    expect(groups.map((g) => [g.name, g.start, g.steps.map((s) => s.name)])).toEqual([
      ['One', 0, ['a']],
      ['Two', 1, ['b', 'c']],
    ])
    expect(groupSteps(steps, [])).toEqual([{ name: null, start: 0, steps }])
  })
})
