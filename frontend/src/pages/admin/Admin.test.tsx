import { screen, within } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { fieldError, sponsorFormData } from '../../api/admin'
import { AdminError, AdminGuard } from '../../components/AdminGuard'
import { BarChart } from '../../components/BarChart'
import { renderWithData } from '../../test/render'
import AdminDashboard from './AdminDashboard'
import AdminSponsorForm from './AdminSponsorForm'
import AdminSponsors from './AdminSponsors'
import AdminUserJobs from './AdminUserJobs'
import AdminUsers from './AdminUsers'

const meAs = (admin: boolean) => ({ data: { id: 1, display_name: 'Boss', admin, characters: [] }, meta: {} })

const user = (over: Record<string, unknown> = {}) => ({
  id: 2,
  display_name: 'Ana',
  email: 'ana@example.com',
  portrait_url: null,
  character_count: 3,
  role: 'user',
  is_active: true,
  is_stock_manager_enabled: false,
  last_login_at: null,
  created_at: '2026-10-01T10:00:00+00:00',
  ...over,
})

const pagination = { page: 1, per_page: 20, total: 2, last_page: 1 }

describe('AdminGuard', () => {
  const page = (
    <Routes>
      <Route element={<AdminGuard />}>
        <Route index element={<p>secret page</p>} />
      </Route>
    </Routes>
  )

  it('shows the page to admins', () => {
    renderWithData(page, [[['me'], meAs(true)]])
    expect(screen.getByText('secret page')).toBeInTheDocument()
  })

  it('shows the denial page to everyone else', () => {
    renderWithData(page, [[['me'], meAs(false)]])
    expect(screen.getByRole('alert')).toHaveTextContent('Admin access required')
    expect(screen.queryByText('secret page')).not.toBeInTheDocument()
  })

  it('maps an API 403 to the same denial page', () => {
    renderWithData(<AdminError error={new ApiError(403, { message: 'Admin access required.' })} what="x" />)
    expect(screen.getByRole('alert')).toHaveTextContent('Admin access required')
  })

  it('keeps other errors short', () => {
    renderWithData(<AdminError error={new ApiError(500, undefined)} what="the users" />)
    expect(screen.getByRole('alert')).toHaveTextContent('Could not load the users.')
  })
})

describe('AdminDashboard', () => {
  it('shows counts, charts and recent users', () => {
    renderWithData(<AdminDashboard />, [
      [
        ['admin', 'dashboard'],
        {
          data: {
            stats: { users: 1234, characters: 5, active_users: 4, recent_logins: 3, new_users_week: 2, new_users_month: 9, logged_in_24h: 1 },
            registrations: [{ date: '2026-10-20', count: 2 }],
            logins: [],
            recent_users: [user({ role: 'admin' })],
          },
          meta: {},
        },
      ],
    ])
    expect(screen.getByText('1,234')).toBeInTheDocument()
    expect(screen.getByText('New users this month')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /New registrations: 2 in the last 30 days/ })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /Login activity: 0 in the last 30 days/ })).toBeInTheDocument()
    expect(screen.getByText('ana@example.com')).toBeInTheDocument()
    expect(screen.getByText('Admin')).toBeInTheDocument()
  })
})

describe('BarChart', () => {
  it('fills days without data with zero', () => {
    const { container } = renderWithData(<BarChart title="T" series={[{ date: '2026-10-22', count: 4 }]} days={3} now={new Date('2026-10-23T12:00:00Z')} />)
    const titles = Array.from(container.querySelectorAll('rect title')).map((t) => t.textContent)
    expect(titles).toEqual(['2026-10-21: 0', '2026-10-22: 4', '2026-10-23: 0'])
  })
})

describe('AdminUsers', () => {
  const key = ['admin', 'users', { sort: 'created_at', direction: 'desc', page: 1 }]
  const list = {
    data: [user(), user({ id: 3, display_name: 'Root', role: 'super_admin' }), user({ id: 1, display_name: 'Boss', role: 'admin', is_stock_manager_enabled: true })],
    meta: { pagination },
  }

  it('lists users with their role, status and stock manager', () => {
    renderWithData(<AdminUsers />, [[key, list], [['me'], meAs(true)]])
    expect(screen.getByText('2 total')).toBeInTheDocument()
    expect(screen.getByText('Super admin')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Stock manager: on' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('link', { name: 'Industry jobs of Ana' })).toHaveAttribute('href', '/admin/users/2/industry-jobs')
    expect(screen.getByRole('columnheader', { name: /Joined/ })).toHaveAttribute('aria-sort', 'descending')
  })

  it('locks super admins', () => {
    renderWithData(<AdminUsers />, [[key, list], [['me'], meAs(true)]])
    const rows = screen.getAllByRole('row').slice(1)
    const ana = within(rows[0]!)
    const root = within(rows[1]!)
    expect(ana.getByRole('button', { name: 'Delete' })).toBeEnabled()
    for (const name of ['Deactivate', 'Make admin', 'Delete']) {
      expect(root.getByRole('button', { name })).toBeDisabled()
    }
  })

  it('says when nothing matches', () => {
    renderWithData(<AdminUsers />, [[key, { data: [], meta: { pagination: { ...pagination, total: 0 } } }]])
    expect(screen.getByText('No users found.')).toBeInTheDocument()
  })
})

describe('AdminUserJobs', () => {
  const jobs = {
    data: {
      user: { id: 7, display_name: 'Worker' },
      region_id: 10000002,
      regions: [
        { id: 10000002, name: 'The Forge (Jita)' },
        { id: 10000043, name: 'Domain (Amarr)' },
      ],
      groups: [
        {
          activity_name: 'Manufacturing',
          jobs: [
            {
              job_id: 1,
              character_name: 'Ana',
              type_id: 587,
              item_name: 'Rifter',
              item_image_url: null,
              runs: 10,
              expected_output: 10,
              estimated_value: 1_500_000,
              time_remaining: '2h 0m',
              progress_pct: 50,
              ready_for_delivery: false,
              location: 'Jita IV',
            },
            {
              job_id: 2,
              character_name: 'Ana',
              type_id: null,
              item_name: 'Unknown thing',
              item_image_url: null,
              runs: 1,
              expected_output: 1,
              estimated_value: null,
              time_remaining: '0m',
              progress_pct: 100,
              ready_for_delivery: true,
              location: 'Jita IV',
            },
          ],
        },
      ],
      summary: [{ type_id: 587, item_name: 'Rifter', item_image_url: null, total_runs: 10, total_expected_output: 10, job_count: 1, estimated_value: 1_500_000 }],
      grand_total: 1_500_000,
    },
    meta: {},
  }

  function renderJobs(data: unknown) {
    return renderWithData(
      <Routes>
        <Route path="/admin/users/:user/industry-jobs" element={<AdminUserJobs />} />
      </Routes>,
      [[['admin', 'users', 7, 'industry-jobs', undefined], data]],
      ['/admin/users/7/industry-jobs'],
    )
  }

  it('shows jobs with their value and the region picker', () => {
    renderJobs(jobs)
    expect(screen.getByText('Worker')).toBeInTheDocument()
    expect(screen.getByRole('combobox')).toHaveValue('10000002')
    expect(screen.getAllByText('1.50M ISK').length).toBeGreaterThan(0)
    expect(screen.getByText('Ready')).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: 'Rifter progress' })).toHaveAttribute('aria-valuenow', '50')
    
    expect(screen.queryByText('0.00 ISK')).not.toBeInTheDocument()
  })

  it('says when the user has no active jobs', () => {
    renderJobs({ data: { ...jobs.data, groups: [], summary: [], grand_total: null }, meta: {} })
    expect(screen.getByText('No active industry jobs found.')).toBeInTheDocument()
  })
})

describe('sponsors', () => {
  const sponsor = { id: 4, name: 'Acme', url: 'https://acme.example/very/long/path/that/keeps/going/and/going', image_url: '/storage/sponsors/a.png', is_active: false, sort_order: 3 }

  it('lists sponsors', () => {
    renderWithData(<AdminSponsors />, [[['admin', 'sponsors'], { data: [sponsor], meta: {} }]])
    expect(screen.getByRole('img', { name: 'Acme' })).toHaveAttribute('src', '/storage/sponsors/a.png')
    expect(screen.getByText('Inactive')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Edit Acme' })).toHaveAttribute('href', '/admin/sponsors/4')
    expect(screen.getByRole('link', { name: /https:\/\/acme.example.*…/ })).toHaveAttribute('target', '_blank')
  })

  it('offers to add the first one', () => {
    renderWithData(<AdminSponsors />, [[['admin', 'sponsors'], { data: [], meta: {} }]])
    expect(screen.getByText(/No sponsors yet/)).toBeInTheDocument()
  })

  it('needs an image for a new sponsor but not for an edit', () => {
    const { unmount } = renderWithData(
      <Routes>
        <Route path="/admin/sponsors/:sponsor" element={<AdminSponsorForm />} />
      </Routes>,
      [],
      ['/admin/sponsors/new'],
    )
    expect(screen.getByLabelText(/Banner image/)).toBeRequired()
    unmount()
    renderWithData(
      <Routes>
        <Route path="/admin/sponsors/:sponsor" element={<AdminSponsorForm />} />
      </Routes>,
      [[['admin', 'sponsors', 4], { data: sponsor, meta: {} }]],
      ['/admin/sponsors/4'],
    )
    expect(screen.getByLabelText(/Banner image/)).not.toBeRequired()
    expect(screen.getByLabelText('Sponsor name')).toHaveValue('Acme')
    expect(screen.getByLabelText('Active')).not.toBeChecked()
  })

  it('builds the multipart body with 1/0 flags and an optional file', () => {
    const file = new File(['x'], 'b.png', { type: 'image/png' })
    const form = sponsorFormData({ name: 'A', url: 'https://a.test', sort_order: 2, is_active: true, image: file })
    expect(form.get('is_active')).toBe('1')
    expect(form.get('sort_order')).toBe('2')
    expect((form.get('image') as File).name).toBe('b.png')
    expect(sponsorFormData({ name: 'A', url: 'https://a.test', sort_order: 0, is_active: false, image: null }).has('image')).toBe(false)
  })
})

describe('fieldError', () => {
  it('reads the first message of a 422 field', () => {
    const error = new ApiError(422, { message: 'Invalid', errors: { email: ['Taken', 'Other'] } })
    expect(fieldError(error, 'email')).toBe('Taken')
    expect(fieldError(error, 'name')).toBeUndefined()
    expect(fieldError(new Error('x'), 'email')).toBeUndefined()
  })
})
