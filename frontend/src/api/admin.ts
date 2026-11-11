import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, unwrap, type ApiErrorBody } from './client'
import type { components, paths } from './schema'

export type AdminUser = components['schemas']['AdminUserResource']
export type Sponsor = components['schemas']['SponsorResource']
export type AccountUpdate = {
  email: string
  current_password?: string
  password?: string
  password_confirmation?: string
}
export type UsersQuery = NonNullable<paths['/admin/users']['get']['parameters']['query']>
export type UserAction = 'status' | 'admin' | 'stock-manager'
export type SponsorValues = { name: string; url: string; sort_order: number; is_active: boolean; image: File | null }


export function fieldError(error: unknown, field: string): string | undefined {
  return error instanceof ApiError ? error.errors?.[field]?.[0] : undefined
}


export function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

async function noContent(request: Promise<{ response: Response; error?: unknown }>) {
  const { response, error } = await request
  if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
}


const retryUnlessForbidden = (failures: number, error: unknown) =>
  !(error instanceof ApiError && (error.status === 403 || error.status === 404)) && failures < 1

export function useAdminDashboard() {
  return useQuery({
    queryKey: ['admin', 'dashboard'],
    queryFn: async () => unwrap(await api.GET('/admin/dashboard')),
    retry: retryUnlessForbidden,
  })
}

export function useAdminUsers(query: UsersQuery) {
  return useQuery({
    queryKey: ['admin', 'users', query],
    queryFn: async () => unwrap(await api.GET('/admin/users', { params: { query } })),
    placeholderData: keepPreviousData,
    retry: retryUnlessForbidden,
  })
}


export function useUserAction() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, action }: { id: number; action: UserAction }) => {
      const params = { params: { path: { user: id } } }
      switch (action) {
        case 'status':
          return unwrap(await api.POST('/admin/users/{user}/toggle-status', params))
        case 'admin':
          return unwrap(await api.POST('/admin/users/{user}/toggle-admin', params))
        case 'stock-manager':
          return unwrap(await api.POST('/admin/users/{user}/toggle-stock-manager', params))
      }
    },
    onSuccess: () => client.invalidateQueries({ queryKey: ['admin'] }),
  })
}

export function useDeleteUser() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => noContent(api.DELETE('/admin/users/{user}', { params: { path: { user: id } } })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['admin'] }),
  })
}


export function useAdminUserJobs(id: number, regionId: number | undefined) {
  return useQuery({
    queryKey: ['admin', 'users', id, 'industry-jobs', regionId],
    queryFn: async () =>
      unwrap(
        await api.GET('/admin/users/{user}/industry-jobs', {
          params: { path: { user: id }, query: { region_id: regionId } },
        }),
      ),
    placeholderData: keepPreviousData,
    retry: retryUnlessForbidden,
  })
}

export function useSponsors() {
  return useQuery({
    queryKey: ['admin', 'sponsors'],
    queryFn: async () => unwrap(await api.GET('/admin/sponsors')),
    retry: retryUnlessForbidden,
  })
}

export function useSponsor(id: number | null) {
  return useQuery({
    queryKey: ['admin', 'sponsors', id],
    queryFn: async () => unwrap(await api.GET('/admin/sponsors/{sponsor}', { params: { path: { sponsor: id ?? 0 } } })),
    enabled: id !== null,
    retry: retryUnlessForbidden,
  })
}


export function sponsorFormData(values: SponsorValues): FormData {
  const form = new FormData()
  form.set('name', values.name)
  form.set('url', values.url)
  form.set('sort_order', String(values.sort_order))
  form.set('is_active', values.is_active ? '1' : '0')
  if (values.image) form.set('image', values.image)
  return form
}


export function useSaveSponsor(id: number | null) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (values: SponsorValues) => {
      const init = { body: {} as never, bodySerializer: () => sponsorFormData(values) }
      return id === null
        ? unwrap(await api.POST('/admin/sponsors', init))
        : unwrap(await api.POST('/admin/sponsors/{sponsor}', { ...init, params: { path: { sponsor: id } } }))
    },
    onSuccess: () => client.invalidateQueries({ queryKey: ['admin', 'sponsors'] }),
  })
}

export function useDeleteSponsor() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => noContent(api.DELETE('/admin/sponsors/{sponsor}', { params: { path: { sponsor: id } } })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['admin', 'sponsors'] }),
  })
}

export function useAdminAccount() {
  return useQuery({
    queryKey: ['admin', 'account'],
    queryFn: async () => unwrap(await api.GET('/admin/account')),
    retry: retryUnlessForbidden,
  })
}

export function useUpdateAccount() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (body: AccountUpdate) => unwrap(await api.PUT('/admin/account', { body })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['admin', 'account'] }),
  })
}

export type DiagnosticsQuery = NonNullable<paths['/admin/esi-diagnostics/summary']['get']['parameters']['query']>
export type DiagnosticsEventsQuery = NonNullable<paths['/admin/esi-diagnostics/events']['get']['parameters']['query']>
export type DiagnosticEvent = components['schemas']['EsiDiagnosticEventResource']

const diagnostics = (name: string, query: unknown) => ['admin', 'esi-diagnostics', name, query] as const

export function useEsiDiagnosticsSummary(query: DiagnosticsQuery) {
  return useQuery({
    queryKey: diagnostics('summary', query),
    queryFn: async () => unwrap(await api.GET('/admin/esi-diagnostics/summary', { params: { query } })),
    placeholderData: keepPreviousData,
    retry: retryUnlessForbidden,
  })
}

export function useEsiDiagnosticsTrends(query: DiagnosticsQuery) {
  return useQuery({
    queryKey: diagnostics('trends', query),
    queryFn: async () => unwrap(await api.GET('/admin/esi-diagnostics/trends', { params: { query } })),
    placeholderData: keepPreviousData,
    retry: retryUnlessForbidden,
  })
}

export function useEsiDiagnosticsEndpoints(query: DiagnosticsQuery) {
  return useQuery({
    queryKey: diagnostics('endpoints', query),
    queryFn: async () => unwrap(await api.GET('/admin/esi-diagnostics/endpoints', { params: { query } })),
    placeholderData: keepPreviousData,
    retry: retryUnlessForbidden,
  })
}

export function useEsiDiagnosticsBatches(query: DiagnosticsQuery) {
  return useQuery({
    queryKey: diagnostics('batches', query),
    queryFn: async () => unwrap(await api.GET('/admin/esi-diagnostics/batches', { params: { query } })),
    placeholderData: keepPreviousData,
    retry: retryUnlessForbidden,
  })
}

export function useEsiDiagnosticsHealth() {
  return useQuery({
    queryKey: ['admin', 'esi-diagnostics', 'health'],
    queryFn: async () => unwrap(await api.GET('/admin/esi-diagnostics/health')),
    refetchInterval: 60_000,
    retry: retryUnlessForbidden,
  })
}


export function useEsiDiagnosticsEvents(query: DiagnosticsQuery) {
  return useInfiniteQuery({
    queryKey: diagnostics('events', query),
    queryFn: async ({ pageParam }) =>
      unwrap(await api.GET('/admin/esi-diagnostics/events', { params: { query: { ...query, before_id: pageParam ?? undefined } } })),
    initialPageParam: null as number | null,
    getNextPageParam: (last) => (last.meta as { next_cursor?: number | null }).next_cursor ?? undefined,
    retry: retryUnlessForbidden,
  })
}
