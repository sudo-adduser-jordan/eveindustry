import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, unwrap, type ApiErrorBody } from './client'
import type { components, paths } from './schema'


const retryUnlessForbidden = (failures: number, error: unknown) =>
  !(error instanceof ApiError && error.status === 403) && failures < 1


async function noContent(request: Promise<{ response: Response; error?: unknown }>) {
  const { response, error } = await request
  if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
}

export type DonationsQuery = NonNullable<NonNullable<paths['/corporation/donations']['get']['parameters']['query']>>
export type ContributorsQuery = NonNullable<
  NonNullable<paths['/corporation/projects/contributors']['get']['parameters']['query']>
>
export type StructureWindowRequest = components['schemas']['StructureWindowRequest']


export function useDonationStatus() {
  return useQuery({
    queryKey: ['corp', 'donations', 'status'],
    queryFn: async () => unwrap(await api.GET('/corporation/donations/status')),
  })
}


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


export function useRefreshDonations() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => noContent(api.POST('/corporation/donations/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'donations'] }),
  })
}


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


export function useRefreshProjects() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => noContent(api.POST('/corporation/projects/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['corp', 'projects'] }),
  })
}


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


export function useSystemSearch(q: string) {
  const term = q.trim()
  return useQuery({
    queryKey: ['systems', 'search', term],
    queryFn: async () => unwrap(await api.GET('/systems', { params: { query: { q: term } } })),
    enabled: term.length >= 2,
    staleTime: 10 * 60_000,
  })
}


export function useBuildProgramsOverview() {
  return useQuery({
    queryKey: ['corp', 'build-programs'],
    queryFn: async () => unwrap(await api.GET('/corporation/build-programs')),
  })
}


export function useBuildProgram(id: number) {
  return useQuery({
    queryKey: ['corp', 'build-programs', id],
    queryFn: async () => unwrap(await api.GET('/corporation/build-programs/{program}', { params: { path: { program: id } } })),
    retry: (failures, error) => !(error instanceof ApiError && [403, 404].includes(error.status)) && failures < 1,
  })
}


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
    
    (unwrap(await api.POST('/corporation/build-programs/merge', { body })) as unknown as { data: { id: number } }),
  )
}
