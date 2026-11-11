import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, unwrap, type ApiErrorBody } from './client'
import type { components, paths } from './schema'

/** Queries on corporation endpoints: a 403 says what to fix, so it is never retried. */
const retryUnlessForbidden = (failures: number, error: unknown) =>
  !(error instanceof ApiError && error.status === 403) && failures < 1

/** Await a write that answers 2xx with no body; throw ApiError otherwise. */
async function noContent(request: Promise<{ response: Response; error?: unknown }>) {
  const { response, error } = await request
  if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
}

export type DonationsQuery = NonNullable<NonNullable<paths['/corporation/donations']['get']['parameters']['query']>>
export type ContributorsQuery = NonNullable<
  NonNullable<paths['/corporation/projects/contributors']['get']['parameters']['query']>
>
export type StructureWindowRequest = components['schemas']['StructureWindowRequest']

// ── Donations ──────────────────────────────────────────────────────────────

/** Whether donation tracking is on, who provides it, and who could enable it. */
export function useDonationStatus() {
  return useQuery({
    queryKey: ['corp', 'donations', 'status'],
    queryFn: async () => unwrap(await api.GET('/corporation/donations/status')),
  })
}

/** Donations by donator. 403 when tracking is off, so only ask once `enabled`. */
export function useDonations(query: DonationsQuery, enabled: boolean) {
  return useQuery({
    queryKey: ['corp', 'donations', query],
    queryFn: async () => unwrap(await api.GET('/corporation/donations', { params: { query } })),
    enabled,
    retry: retryUnlessForbidden,
    placeholderData: keepPreviousData,
  })
}

export function useEnableDonations() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (characterId: number | undefined) =>
      unwrap(await api.POST('/corporation/donations/enable', { body: { character_id: characterId } })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'donations'] }),
  })
}

export function useDisableDonations() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async () => unwrap(await api.POST('/corporation/donations/disable')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'donations'] }),
  })
}

/** Forget the cached wallet journals (204), then reload. */
export function useRefreshDonations() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => noContent(api.POST('/corporation/donations/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'donations'] }),
  })
}

// ── Projects ───────────────────────────────────────────────────────────────

/** The corporation's projects. Slow on a cold cache: one ESI call per project. */
export function useProjects() {
  return useQuery({
    queryKey: ['corp', 'projects'],
    queryFn: async () => unwrap(await api.GET('/corporation/projects', { params: { query: { per_page: 200 } } })),
    retry: retryUnlessForbidden,
    staleTime: 60_000,
  })
}

export function useProjectContributors(query: ContributorsQuery) {
  return useQuery({
    queryKey: ['corp', 'projects', 'contributors', query],
    queryFn: async () =>
      unwrap(await api.GET('/corporation/projects/contributors', { params: { query: { ...query, per_page: 200 } } })),
    retry: retryUnlessForbidden,
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })
}

/** Forget the cached projects (204), then reload both project pages. */
export function useRefreshProjects() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => noContent(api.POST('/corporation/projects/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'projects'] }),
  })
}

// ── Structure vulnerability windows ────────────────────────────────────────

export function useStructureWindows() {
  return useQuery({
    queryKey: ['corp', 'structure-windows'],
    queryFn: async () =>
      unwrap(await api.GET('/corporation/structure-vulnerability', { params: { query: { per_page: 200 } } })),
    retry: retryUnlessForbidden,
  })
}

function useWindowMutation<V>(fn: (variables: V) => Promise<unknown>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'structure-windows'] }),
  })
}

export function useAddStructureWindow() {
  return useWindowMutation(async (body: StructureWindowRequest) =>
    unwrap(await api.POST('/corporation/structure-vulnerability', { body })),
  )
}

export function useUpdateStructureWindow() {
  return useWindowMutation(async ({ id, body }: { id: number; body: StructureWindowRequest }) =>
    unwrap(await api.PUT('/corporation/structure-vulnerability/{window}', { params: { path: { window: id } }, body })),
  )
}

export function useRemoveStructureWindow() {
  return useWindowMutation((id: number) =>
    noContent(api.DELETE('/corporation/structure-vulnerability/{window}', { params: { path: { window: id } } })),
  )
}

// ── Solar system typeahead ─────────────────────────────────────────────────

/** Systems whose name contains `q` (at most 20). Idle below two characters. */
export function useSystemSearch(q: string) {
  const term = q.trim()
  return useQuery({
    queryKey: ['systems', 'search', term],
    queryFn: async () => unwrap(await api.GET('/systems', { params: { query: { q: term } } })),
    enabled: term.length >= 2,
    staleTime: 10 * 60_000,
  })
}

// ── Corporate build programs ───────────────────────────────────────────────

/** Whether the feature is on, what the user may do, and the programs. Never 403. */
export function useBuildProgramsOverview() {
  return useQuery({
    queryKey: ['corp', 'build-programs'],
    queryFn: async () => unwrap(await api.GET('/corporation/build-programs')),
  })
}

/** One program with its step tree. 404 when it is another corporation's. */
export function useBuildProgram(id: number) {
  return useQuery({
    queryKey: ['corp', 'build-programs', id],
    queryFn: async () => unwrap(await api.GET('/corporation/build-programs/{program}', { params: { path: { program: id } } })),
    retry: (failures, error) => !(error instanceof ApiError && [403, 404].includes(error.status)) && failures < 1,
  })
}

/**
 * Stock found for a program, counted at the given locations (all when empty).
 * Reads the corporation's assets with the enabling director's token, so it
 * can be slow on a cold cache.
 */
export function useBuildProgramProgress(id: number, inputIds: number[], outputIds: number[]) {
  return useQuery({
    queryKey: ['corp', 'build-programs', id, 'progress', inputIds, outputIds],
    queryFn: async () =>
      unwrap(
        await api.GET('/corporation/build-programs/{program}/progress', {
          params: {
            path: { program: id },
            query: { 'input_location_ids[]': inputIds, 'output_location_ids[]': outputIds },
          },
        }),
      ),
    staleTime: 60_000,
    retry: false,
  })
}

/** Stations and structures that hold the corporation's assets, for the location filters. */
export function useBuildProgramLocations(id: number) {
  return useQuery({
    queryKey: ['corp', 'build-programs', id, 'locations'],
    queryFn: async () =>
      unwrap(
        await api.GET('/corporation/build-programs/{program}/asset-locations', {
          params: { path: { program: id }, query: { per_page: 200 } },
        }),
      ),
    staleTime: 5 * 60_000,
    retry: false,
  })
}

function useProgramsMutation<V = void, R = unknown>(fn: (variables: V) => Promise<R>) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'build-programs'] }),
  })
}

export function useEnableBuildPrograms() {
  return useProgramsMutation(async (characterId: number | undefined) =>
    unwrap(await api.POST('/corporation/build-programs/enable', { body: { character_id: characterId } })),
  )
}

export function useDisableBuildPrograms() {
  return useProgramsMutation<void>(async () => unwrap(await api.POST('/corporation/build-programs/disable')))
}

export function useRenameBuildProgram() {
  return useProgramsMutation(async ({ id, name }: { id: number; name: string }) =>
    unwrap(await api.PUT('/corporation/build-programs/{program}', { params: { path: { program: id } }, body: { name } })),
  )
}

export function useDeleteBuildProgram() {
  return useProgramsMutation((id: number) =>
    noContent(api.DELETE('/corporation/build-programs/{program}', { params: { path: { program: id } } })),
  )
}

export function useSaveLocationFilter() {
  return useProgramsMutation(
    async ({ id, input, output }: { id: number; input: number[]; output: number[] }) =>
      unwrap(
        await api.PATCH('/corporation/build-programs/{program}/location-filter', {
          params: { path: { program: id } },
          body: { input_location_ids: input, output_location_ids: output },
        }),
      ),
  )
}

export function useMergeBuildPrograms() {
  return useProgramsMutation(async (body: { program_ids: number[]; name: string }) =>
    // The spec types this 201 as a bare number; the body is the new program.
    (unwrap(await api.POST('/corporation/build-programs/merge', { body })) as unknown as { data: { id: number } }),
  )
}
