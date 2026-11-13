import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, unwrap, type ApiErrorBody } from './client'
import type { paths, components } from './schema'

export type AssetScope = 'personal' | 'corporate'


export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => unwrap(await api.GET('/me')),
    staleTime: 5 * 60_000,
  })
}


export function useFreshness() {
  return useQuery({
    queryKey: ['market', 'freshness'],
    queryFn: async () => unwrap(await api.GET('/market/freshness')),
    staleTime: 60_000,
    refetchInterval: 5 * 60_000,
  })
}


export function useEsiStatus(refetchInterval: number | false = 60_000) {
  return useQuery({
    queryKey: ['esi', 'status'],
    queryFn: async () => unwrap(await api.GET('/esi/status')),
    staleTime: 60_000,
    refetchInterval,
    retry: false,
  })
}


export function useAssetsByLocation(scope: AssetScope, search: string, refetchInterval: number | false = false) {
  return useQuery({
    queryKey: ['assets', scope, 'by-location', search],
    queryFn: async () => {
      const query = { search: search === '' ? undefined : search, include_items: false }
      return scope === 'personal'
        ? unwrap(await api.GET('/assets/by-location', { params: { query } }))
        : unwrap(await api.GET('/corporation/assets/by-location', { params: { query } }))
    },
    retry: (failures, error) => !(error instanceof ApiError && error.status === 403) && failures < 1,
    placeholderData: keepPreviousData,
    refetchInterval,
  })
}


export function useAssetItems(
  scope: AssetScope,
  filters: { location_id?: number; parent_item_id?: number; search?: string },
  revision: string | null | undefined,
) {
  return useInfiniteQuery({
    queryKey: ['assets', scope, 'items', filters, revision],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => {
      const query = { ...filters, include_children: false, snapshot_version: revision || undefined, per_page: 50, page: pageParam }
      return scope === 'personal'
        ? unwrap(await api.GET('/assets', { params: { query } }))
        : unwrap(await api.GET('/corporation/assets', { params: { query } }))
    },
    getNextPageParam: (page) => {
      const pagination = page.meta.pagination
      return pagination.page < pagination.last_page ? pagination.page + 1 : undefined
    },
    retry: (failures, error) => !(error instanceof ApiError && error.status === 403) && failures < 1,
    staleTime: 60_000,
  })
}


export function useRefreshAssets() {
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/assets/refresh')),
  })
}


export function useIndustryAvailability() {
  return useQuery({
    queryKey: ['industry', 'availability'],
    queryFn: async () => unwrap(await api.GET('/industry/availability')),
    staleTime: 60_000,
  })
}


async function postNoContent(request: Promise<{ response: Response; error?: unknown }>) {
  const { response, error } = await request
  if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
}


export function useRefreshAvailability() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/industry/availability/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['industry', 'availability'] }),
  })
}


export function useClearIndustryCaches() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/industry/availability/clear')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['industry'] }),
  })
}


export function useReprocessing() {
  return useQuery({
    queryKey: ['industry', 'reprocessing'],
    queryFn: async () => unwrap(await api.GET('/industry/reprocessing')),
    staleTime: 5 * 60_000,
  })
}

type RefetchInterval = number | false | ((query: { state: { data?: unknown } }) => number | false)


export function useAssetMaterials(refetchInterval: RefetchInterval = false) {
  return useQuery({
    queryKey: ['assets', 'materials'],
    queryFn: async () => unwrap(await api.GET('/assets/materials')),
    refetchInterval,
  })
}


type StationTradingQuery = NonNullable<
  NonNullable<paths['/market/station-trading']['get']['parameters']['query']>
>

type RegionArbitrageQuery = NonNullable<
  NonNullable<paths['/market/region-arbitrage']['get']['parameters']['query']>
>


export function useStationTrading(query: StationTradingQuery) {
  return useQuery({
    queryKey: ['market', 'station-trading', query],
    queryFn: async () => unwrap(await api.GET('/market/station-trading', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}


export function useRegionArbitrage(query: RegionArbitrageQuery) {
  return useQuery({
    queryKey: ['market', 'region-arbitrage', query],
    queryFn: async () => unwrap(await api.GET('/market/region-arbitrage', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export type PricingMode = 'raw' | 'compressed' | 'refined'


export function useMoonSystems() {
  return useQuery({
    queryKey: ['moon-scans', 'systems'],
    queryFn: async () => unwrap(await api.GET('/moon-scans/systems', { params: { query: { per_page: 200 } } })),
    staleTime: 60_000,
  })
}


export function useAnalyseMoons() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (body: { scan_data: string; region_id: '10000002' | '10000043' | '10000032' | '10000030' | '10000042'; pricing_mode: PricingMode }) =>
      unwrap(await api.POST('/moon-scans', { body })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['moon-scans', 'systems'] }),
  })
}


export function useLoadMoonSystem() {
  return useMutation({
    mutationFn: async ({ system, pricing_mode }: { system: string; pricing_mode?: PricingMode }) =>
      unwrap(
        await api.GET('/moon-scans/systems/{system}', {
          params: { path: { system }, query: { pricing_mode, per_page: 200 } },
        }),
      ),
  })
}


export function useSetMoonOccupation() {
  return useMutation({
    mutationFn: async ({
      moonId,
      ...body
    }: {
      moonId: number
      occupied: boolean
      corporation_id?: number
      corporation_name?: string
    }) => unwrap(await api.PUT('/moon-scans/moons/{moonId}/occupation', { params: { path: { moonId } }, body })),
  })
}


export function useCorporationSearch(q: string) {
  return useQuery({
    queryKey: ['corporations', 'search', q],
    queryFn: async () => unwrap(await api.GET('/corporations', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 5 * 60_000,
  })
}


export function useSystemSearch(q: string) {
  return useQuery({
    queryKey: ['systems', 'search', q],
    queryFn: async () => unwrap(await api.GET('/systems', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 5 * 60_000,
  })
}


export function useRootMarketGroups() {
  return useQuery({
    queryKey: ['reference', 'market-groups'],
    queryFn: async () => unwrap(await api.GET('/market-groups')),
    staleTime: 60 * 60_000,
  })
}


export function useMarketGroup(groupId: number | null) {
  return useQuery({
    queryKey: ['reference', 'market-groups', groupId],
    queryFn: async () =>
      unwrap(await api.GET('/market-groups/{marketGroupId}', { params: { path: { marketGroupId: groupId as number } } })),
    enabled: groupId !== null,
    staleTime: 60 * 60_000,
  })
}


export function useTypeSearch(term: string) {
  return useQuery({
    queryKey: ['reference', 'types', term],
    queryFn: async () => unwrap(await api.GET('/types', { params: { query: { q: term } } })),
    enabled: term.length >= 2,
    staleTime: 60 * 60_000,
  })
}

type AppraisalBody = NonNullable<paths['/market/appraisals']['post']['requestBody']>['content']['application/json']


export function useAppraise() {
  return useMutation({
    mutationFn: async (body: AppraisalBody) => unwrap(await api.POST('/market/appraisals', { body })),
  })
}

type ContractQuery = NonNullable<NonNullable<paths['/market/contracts']['get']['parameters']['query']>>


export function useContracts(query: ContractQuery) {
  return useQuery({
    queryKey: ['market', 'contracts', query],
    queryFn: async () => unwrap(await api.GET('/market/contracts', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}


export function useContract(contractId: number) {
  return useQuery({
    queryKey: ['market', 'contracts', contractId],
    queryFn: async () =>
      unwrap(await api.GET('/market/contracts/{contractId}', { params: { path: { contractId } } })),
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export type StructureInput = components['schemas']['StoreStructureRequest']


export function useStructures() {
  return useQuery({
    queryKey: ['structures'],
    queryFn: async () => unwrap(await api.GET('/structures', { params: { query: { per_page: 200 } } })),
  })
}


export function useSaveStructure() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, input }: { id: number | null; input: StructureInput }) =>
      id === null
        ? unwrap(await api.POST('/structures', { body: input }))
        : unwrap(await api.PUT('/structures/{structure}', { params: { path: { structure: id } }, body: input })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['structures'] }),
  })
}

export function useDeleteStructure() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: (id: number) =>
      postNoContent(api.DELETE('/structures/{structure}', { params: { path: { structure: id } } })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['structures'] }),
  })
}


export function useSetDefaultStructure() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) =>
      unwrap(await api.POST('/structures/{structure}/default', { params: { path: { structure: id } } })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['structures'] }),
  })
}


export function usePlanetary() {
  return useQuery({
    queryKey: ['planetary'],
    queryFn: async () => unwrap(await api.GET('/planetary')),
    staleTime: 5 * 60_000,
  })
}


export function useRefreshPlanetary() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/planetary/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['planetary'] }),
  })
}

export type FactoryTier = 'p1_to_p2' | 'p2_to_p3' | 'p3_to_p4' | 'p1_to_p3' | 'p0_to_p3'


export function useFactoryPlan(query: { tier: FactoryTier; region_id: number; tax_rate: number }) {
  return useQuery({
    queryKey: ['planetary', 'factory-planner', query],
    queryFn: async () => unwrap(await api.GET('/calculators/factory-planner', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}


export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => unwrap(await api.GET('/dashboard')),
    staleTime: 60_000,
  })
}

type JobsQuery = NonNullable<paths['/industry/jobs']['get']['parameters']['query']>


export function useJobsOverview(query: JobsQuery) {
  return useQuery({
    queryKey: ['industry', 'jobs', query],
    queryFn: async () => unwrap(await api.GET('/industry/jobs', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })
}


export function useCanProduce() {
  return useQuery({
    queryKey: ['industry', 'can-produce'],
    queryFn: async () => unwrap(await api.GET('/industry/can-produce')),
    staleTime: 5 * 60_000,
  })
}

type MostProfitableQuery = NonNullable<paths['/industry/most-profitable']['get']['parameters']['query']>


export function useMostProfitable(query: MostProfitableQuery | null) {
  return useQuery({
    queryKey: ['industry', 'most-profitable', query],
    queryFn: async () => unwrap(await api.GET('/industry/most-profitable', { params: { query: query! } })),
    enabled: query !== null,
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}


export function useRefreshCanProduce() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/industry/can-produce/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['industry', 'can-produce'] }),
  })
}

type BlueprintResearchQuery = NonNullable<paths['/industry/blueprint-research']['get']['parameters']['query']>

type FormulaResearchQuery = NonNullable<paths['/industry/reaction-formula-research']['get']['parameters']['query']>

export function useBlueprintResearch(query: BlueprintResearchQuery) {
  return useQuery({
    queryKey: ['industry', 'blueprint-research', query],
    queryFn: async () => unwrap(await api.GET('/industry/blueprint-research', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
  })
}

export function useReactionFormulaResearch(query: FormulaResearchQuery) {
  return useQuery({
    queryKey: ['industry', 'reaction-formula-research', query],
    queryFn: async () => unwrap(await api.GET('/industry/reaction-formula-research', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
  })
}


export function useStockReport() {
  return useQuery({
    queryKey: ['stock'],
    queryFn: async () => unwrap(await api.GET('/stock')),
    retry: (failures, error) => !(error instanceof ApiError && error.status === 403) && failures < 1,
  })
}


export function useStockSearch(term: string) {
  return useQuery({
    queryKey: ['stock', 'search', term],
    queryFn: async () => unwrap(await api.GET('/stock/search', { params: { query: { q: term } } })),
    enabled: term.trim().length >= 2,
    staleTime: 60_000,
  })
}

export function useStockMutations() {
  const client = useQueryClient()
  const done = () => client.invalidateQueries({ queryKey: ['stock'], exact: true })
  return {
    add: useMutation({
      mutationFn: (body: { type_id: number; desired_quantity: number }) =>
        postNoContent(api.POST('/stock/items', { body })),
      onSuccess: done,
    }),
    update: useMutation({
      mutationFn: ({ id, desired_quantity }: { id: number; desired_quantity: number }) =>
        postNoContent(api.PUT('/stock/items/{item}', { params: { path: { item: id } }, body: { desired_quantity } })),
      onSuccess: done,
    }),
    remove: useMutation({
      mutationFn: (id: number) => postNoContent(api.DELETE('/stock/items/{item}', { params: { path: { item: id } } })),
      onSuccess: done,
    }),
    refresh: useMutation({
      mutationFn: () => postNoContent(api.POST('/stock/refresh')),
      onSuccess: done,
    }),
  }
}

export function useBuildPrograms() {
  return useQuery({
    queryKey: ['build-programs'],
    queryFn: async () => unwrap(await api.GET('/build-programs')),
  })
}

export function useBuildProgram(id: number) {
  return useQuery({
    queryKey: ['build-programs', id],
    queryFn: async () => unwrap(await api.GET('/build-programs/{program}', { params: { path: { program: id } } })),
    retry: (failures, error) => !(error instanceof ApiError && error.status === 404) && failures < 1,
  })
}


export function useBuildProgramProgress(id: number, locationIds: number[]) {
  return useQuery({
    queryKey: ['build-programs', id, 'progress', locationIds],
    queryFn: async () =>
      unwrap(
        await api.GET('/build-programs/{program}/progress', {
          params: { path: { program: id }, query: { 'location_ids[]': locationIds.length > 0 ? locationIds : undefined } },
        }),
      ),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    retry: false,
  })
}


export function useBuildProgramLocations(id: number) {
  return useQuery({
    queryKey: ['build-programs', id, 'locations'],
    queryFn: async () =>
      unwrap(
        await api.GET('/build-programs/{program}/asset-locations', {
          params: { path: { program: id }, query: { per_page: 200 } },
        }),
      ),
    staleTime: 5 * 60_000,
  })
}

export function useBuildProgramMutations(id?: number) {
  const client = useQueryClient()
  const list = () => client.invalidateQueries({ queryKey: ['build-programs'] })
  return {
    rename: useMutation({
      mutationFn: (name: string) =>
        postNoContent(api.PUT('/build-programs/{program}', { params: { path: { program: id ?? 0 } }, body: { name } })),
      onSuccess: list,
    }),
    remove: useMutation({
      mutationFn: (programId: number) =>
        postNoContent(api.DELETE('/build-programs/{program}', { params: { path: { program: programId } } })),
      onSuccess: list,
    }),
    setLocations: useMutation({
      mutationFn: (location_ids: number[]) =>
        postNoContent(
          api.PATCH('/build-programs/{program}/location-filter', { params: { path: { program: id ?? 0 } }, body: { location_ids } }),
        ),
      onSuccess: list,
    }),
    merge: useMutation({
      mutationFn: async (body: { program_ids: number[]; name: string }) => {
        const { data, response, error } = await api.POST('/build-programs/merge', { body })
        if (!response.ok || !data) throw new ApiError(response.status, error as ApiErrorBody | undefined)
        return data as unknown as { data: { id: number } }
      },
      onSuccess: list,
    }),
  }
}
