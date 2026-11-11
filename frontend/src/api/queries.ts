import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, unwrap, type ApiErrorBody } from './client'
import type { paths, components } from './schema'

export type AssetScope = 'personal' | 'corporate'

/** The signed-in user and their characters. Cached for the whole session. */
export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => unwrap(await api.GET('/me')),
    staleTime: 5 * 60_000,
  })
}

/** Per-region data age. Polled so the badge turns amber without a reload. */
export function useFreshness() {
  return useQuery({
    queryKey: ['market', 'freshness'],
    queryFn: async () => unwrap(await api.GET('/market/freshness')),
    staleTime: 60_000,
    refetchInterval: 5 * 60_000,
  })
}

/**
 * Assets grouped by solar system and station. A 403 on corporation assets
 * is final (its message says what to fix), so it is not retried.
 */
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

/** Fetch a bounded page only after a station or container is expanded. */
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

/** Queue a background refresh of personal and corporation assets (202, no body). */
export function useRefreshAssets() {
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/assets/refresh')),
  })
}

/** Industry slots per character, running jobs and jobs ready for collection. */
export function useIndustryAvailability() {
  return useQuery({
    queryKey: ['industry', 'availability'],
    queryFn: async () => unwrap(await api.GET('/industry/availability')),
    staleTime: 60_000,
  })
}

/** Await a write that answers 2xx with no body; throw ApiError otherwise. */
async function postNoContent(request: Promise<{ response: Response; error?: unknown }>) {
  const { response, error } = await request
  if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
}

/** Recompute the slot data now (204), then reload it. */
export function useRefreshAvailability() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/industry/availability/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['industry', 'availability'] }),
  })
}

/** Forget every industry and ESI cache of the user's characters. 429 within 30 min of the last clear. */
export function useClearIndustryCaches() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/industry/availability/clear')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['industry'] }),
  })
}

/** Items in hangars worth more reprocessed than sold. Slow on a cold cache. */
export function useReprocessing() {
  return useQuery({
    queryKey: ['industry', 'reprocessing'],
    queryFn: async () => unwrap(await api.GET('/industry/reprocessing')),
    staleTime: 5 * 60_000,
  })
}

type RefetchInterval = number | false | ((query: { state: { data?: unknown } }) => number | false)

/** Material stacks in the user's assets, grouped and priced. */
export function useAssetMaterials(refetchInterval: RefetchInterval = false) {
  return useQuery({
    queryKey: ['assets', 'materials'],
    queryFn: async () => unwrap(await api.GET('/assets/materials')),
    refetchInterval,
  })
}

/** Ship hulls in the user's assets, grouped by ship group. */
export function useAssetShips(refetchInterval: RefetchInterval = false) {
  return useQuery({
    queryKey: ['assets', 'ships'],
    queryFn: async () => unwrap(await api.GET('/assets/ships')),
    refetchInterval,
  })
}

/** Mutated modules ranked by roll. Slow on a cold cache (one ESI call per item). */
export function useAbyssalItems(refetchInterval: RefetchInterval = false) {
  return useQuery({
    queryKey: ['assets', 'abyssal'],
    queryFn: async () => unwrap(await api.GET('/assets/abyssal')),
    refetchInterval,
  })
}

type StationTradingQuery = NonNullable<
  NonNullable<paths['/market/station-trading']['get']['parameters']['query']>
>

type RegionArbitrageQuery = NonNullable<
  NonNullable<paths['/market/region-arbitrage']['get']['parameters']['query']>
>

/** Station trading spreads in one trade hub. The server sorts by daily ISK profit. */
export function useStationTrading(query: StationTradingQuery) {
  return useQuery({
    queryKey: ['market', 'station-trading', query],
    queryFn: async () => unwrap(await api.GET('/market/station-trading', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}

/** Buy in one hub, sell in another. 422 when both are the same region. */
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

/** Systems the user's corporation has scanned (the picker for stored scans). */
export function useMoonSystems() {
  return useQuery({
    queryKey: ['moon-scans', 'systems'],
    queryFn: async () => unwrap(await api.GET('/moon-scans/systems', { params: { query: { per_page: 200 } } })),
    staleTime: 60_000,
  })
}

/** Price, grade and store a pasted scan. Refreshes the stored systems list. */
export function useAnalyseMoons() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (body: { scan_data: string; region_id: '10000002' | '10000043' | '10000032' | '10000030' | '10000042'; pricing_mode: PricingMode }) =>
      unwrap(await api.POST('/moon-scans', { body })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['moon-scans', 'systems'] }),
  })
}

/** The stored moons of one system, optionally re-priced. */
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

/** Mark a stored moon as occupied by a corporation, or clear it. */
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

/** Corporations whose name matches, for the occupation picker (2+ characters). */
export function useCorporationSearch(q: string) {
  return useQuery({
    queryKey: ['corporations', 'search', q],
    queryFn: async () => unwrap(await api.GET('/corporations', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 5 * 60_000,
  })
}

/** Solar systems whose name contains `q` (2+ characters), for the pickers. */
export function useSystemSearch(q: string) {
  return useQuery({
    queryKey: ['systems', 'search', q],
    queryFn: async () => unwrap(await api.GET('/systems', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 5 * 60_000,
  })
}

/** Top-level market groups. A small fixed SDE list. */
export function useRootMarketGroups() {
  return useQuery({
    queryKey: ['reference', 'market-groups'],
    queryFn: async () => unwrap(await api.GET('/market-groups')),
    staleTime: 60 * 60_000,
  })
}

/** A market group's breadcrumbs, child groups and items. */
export function useMarketGroup(groupId: number | null) {
  return useQuery({
    queryKey: ['reference', 'market-groups', groupId],
    queryFn: async () =>
      unwrap(await api.GET('/market-groups/{marketGroupId}', { params: { path: { marketGroupId: groupId as number } } })),
    enabled: groupId !== null,
    staleTime: 60 * 60_000,
  })
}

/** Market types whose name contains `term` (at most 50). Idle under two characters. */
export function useTypeSearch(term: string) {
  return useQuery({
    queryKey: ['reference', 'types', term],
    queryFn: async () => unwrap(await api.GET('/types', { params: { query: { q: term } } })),
    enabled: term.length >= 2,
    staleTime: 60 * 60_000,
  })
}

type AppraisalBody = NonNullable<paths['/market/appraisals']['post']['requestBody']>['content']['application/json']

/** Price pasted item names from public contracts. Nothing is stored. */
export function useAppraise() {
  return useMutation({
    mutationFn: async (body: AppraisalBody) => unwrap(await api.POST('/market/appraisals', { body })),
  })
}

type ContractQuery = NonNullable<NonNullable<paths['/market/contracts']['get']['parameters']['query']>>

/** Public contracts, newest first. */
export function useContracts(query: ContractQuery) {
  return useQuery({
    queryKey: ['market', 'contracts', query],
    queryFn: async () => unwrap(await api.GET('/market/contracts', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}

/** One public contract with its items; 404 once the next import dropped it. */
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

/** The user's structures, defaults first. */
export function useStructures() {
  return useQuery({
    queryKey: ['structures'],
    queryFn: async () => unwrap(await api.GET('/structures', { params: { query: { per_page: 200 } } })),
  })
}

/** Create (no `id`) or change (with `id`) a structure, then reload the list. */
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

/** Make a structure the default for its activity type. */
export function useSetDefaultStructure() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) =>
      unwrap(await api.POST('/structures/{structure}/default', { params: { path: { structure: id } } })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['structures'] }),
  })
}

/** Planetary colonies of every character. A cold cache reads ESI, so it can be slow. */
export function usePlanetary() {
  return useQuery({
    queryKey: ['planetary'],
    queryFn: async () => unwrap(await api.GET('/planetary')),
    staleTime: 5 * 60_000,
  })
}

/** Drop the cached colonies (204), then read them from ESI again. */
export function useRefreshPlanetary() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: () => postNoContent(api.POST('/planetary/refresh')),
    onSuccess: () => client.invalidateQueries({ queryKey: ['planetary'] }),
  })
}

export type FactoryTier = 'p1_to_p2' | 'p2_to_p3' | 'p3_to_p4' | 'p1_to_p3' | 'p0_to_p3'

/**
 * Factory planet profitability for a tier. The endpoint's `results` rows are
 * untyped in the OpenAPI spec (two row shapes), so the page types them itself.
 */
export function useFactoryPlan(query: { tier: FactoryTier; region_id: number; tax_rate: number }) {
  return useQuery({
    queryKey: ['planetary', 'factory-planner', query],
    queryFn: async () => unwrap(await api.GET('/calculators/factory-planner', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}

// ---------------------------------------------------------------------------
// Phase D slice 6: dashboard, industry tools, stock manager, build programs
// ---------------------------------------------------------------------------

/** Counts, characters and saved plans for the dashboard. Counts come from a cache refreshed every 15 min. */
export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => unwrap(await api.GET('/dashboard')),
    staleTime: 60_000,
  })
}

type JobsQuery = NonNullable<paths['/industry/jobs']['get']['parameters']['query']>

/** Active industry jobs of all characters, valued at one hub's sell prices. */
export function useJobsOverview(query: JobsQuery) {
  return useQuery({
    queryKey: ['industry', 'jobs', query],
    queryFn: async () => unwrap(await api.GET('/industry/jobs', { params: { query } })),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })
}

/** What each character (and corporation) can build from the materials in its hangars. Cached an hour server-side. */
export function useCanProduce() {
  return useQuery({
    queryKey: ['industry', 'can-produce'],
    queryFn: async () => unwrap(await api.GET('/industry/can-produce')),
    staleTime: 5 * 60_000,
  })
}

/** Recompute can-produce now (slow: re-reads blueprints and assets), then reload it. */
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

/** The stock watchlist against corporation hangars. 403 unless the stock manager is enabled for the user. */
export function useStockReport() {
  return useQuery({
    queryKey: ['stock'],
    queryFn: async () => unwrap(await api.GET('/stock')),
    retry: (failures, error) => !(error instanceof ApiError && error.status === 403) && failures < 1,
  })
}

/** Type name typeahead for the stock manager. Searches from two characters. */
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

/** Stock against the program, optionally only at some stations. Slow on a cold ESI cache. */
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

/** Stations and structures where the user's characters hold assets. */
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
