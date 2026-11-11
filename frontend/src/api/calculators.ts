import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { api, unwrap } from './client'
import type { components, paths } from './schema'

type Schemas = components['schemas']

export type ManufacturingRequest = Schemas['ManufacturingCalculateRequest']
export type ManufacturingResult = Schemas['ManufacturingJobResource']
export type Structure = Schemas['StructureResource']
export type BlueprintActivity = Schemas['ActivityResource']
export type BuildProgramRequest = Schemas['StoreBuildProgramRequest']


export function useStructures(activity: 'manufacturing' | 'invention' | 'reaction') {
  return useQuery({
    queryKey: ['structures', activity],
    queryFn: async () =>
      unwrap(await api.GET('/structures', { params: { query: { activity_type: activity, per_page: 200 } } })),
    staleTime: 5 * 60_000,
  })
}


export function useCorporateExport() {
  return useQuery({
    queryKey: ['calculators', 'corporate-export'],
    queryFn: async () => unwrap(await api.GET('/calculators/corporate-export')),
    staleTime: 5 * 60_000,
  })
}


export function useBlueprintSearch(q: string) {
  return useQuery({
    queryKey: ['reference', 'blueprints', q],
    queryFn: async () => unwrap(await api.GET('/blueprints', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 10 * 60_000,
  })
}


export function useBlueprintActivity(blueprintTypeId: number | null) {
  return useQuery({
    queryKey: ['reference', 'blueprint', blueprintTypeId],
    queryFn: async () =>
      unwrap(await api.GET('/blueprints/{blueprintTypeId}', { params: { path: { blueprintTypeId: blueprintTypeId! } } })),
    enabled: blueprintTypeId !== null,
    staleTime: 10 * 60_000,
    retry: false,
  })
}


export function useSystemSearch(q: string) {
  return useQuery({
    queryKey: ['reference', 'systems', q],
    queryFn: async () => unwrap(await api.GET('/systems', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 10 * 60_000,
  })
}


export function useCostIndices(systemId: number | null) {
  return useQuery({
    queryKey: ['reference', 'cost-indices', systemId],
    queryFn: async () =>
      unwrap(await api.GET('/systems/{systemId}/cost-indices', { params: { path: { systemId: systemId! } } })),
    enabled: systemId !== null,
    staleTime: 10 * 60_000,
    retry: false,
  })
}


export function useManufacturingSkills(characterId: number | null) {
  return useQuery({
    queryKey: ['calculators', 'manufacturing', 'skills', characterId],
    queryFn: async () =>
      unwrap(await api.GET('/calculators/manufacturing/skills', { params: { query: { character_id: characterId! } } })),
    enabled: characterId !== null,
    staleTime: 5 * 60_000,
    retry: false,
  })
}


export async function calculateManufacturing(body: ManufacturingRequest) {
  return unwrap(await api.POST('/calculators/manufacturing', { body }))
}

export function useCalculateManufacturing() {
  return useMutation({ mutationFn: calculateManufacturing })
}

export interface QueueSummaryItem {
  revenue: number
  input_cost: number
  fees: number
}


export function useQueueSummary(items: QueueSummaryItem[]) {
  return useQuery({
    queryKey: ['calculators', 'queue-summary', items],
    queryFn: async () => unwrap(await api.POST('/calculators/manufacturing/queue-summary', { body: { items } })),
    enabled: items.length > 0,
    placeholderData: keepPreviousData,
    staleTime: Infinity,
  })
}


export function useAssetStock(typeIds: number[], enabled: boolean) {
  return useQuery({
    queryKey: ['calculators', 'stock', typeIds],
    queryFn: async () => unwrap(await api.POST('/calculators/manufacturing/stock', { body: { type_ids: typeIds } })),
    enabled: enabled && typeIds.length > 0,
    staleTime: 60_000,
  })
}


export function useCreateBuildProgram(corporate: boolean) {
  return useMutation({
    mutationFn: async (body: BuildProgramRequest) =>
      unwrap(
        corporate
          ? await api.POST('/corporation/build-programs', { body })
          : await api.POST('/build-programs', { body }),
      ),
  })
}


export function useMarketGroupTree() {
  return useQuery({
    queryKey: ['reference', 'market-groups', 'tree'],
    queryFn: async () => unwrap(await api.GET('/market-groups/tree')),
    staleTime: 60 * 60_000,
  })
}


export function useMarketGroupItems(groupId: number | null) {
  return useQuery({
    queryKey: ['reference', 'market-groups', groupId],
    queryFn: async () =>
      unwrap(await api.GET('/market-groups/{marketGroupId}', { params: { path: { marketGroupId: groupId! } } })),
    enabled: groupId !== null,
    staleTime: 60 * 60_000,
  })
}


export function useTypeSearch(q: string) {
  return useQuery({
    queryKey: ['reference', 'types', q],
    queryFn: async () => unwrap(await api.GET('/types', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 10 * 60_000,
  })
}

type BrowserQuery = NonNullable<paths['/calculators/manufacturing-browser']['get']['parameters']['query']>


export function useManufacturingBrowse(query: BrowserQuery | null) {
  return useQuery({
    queryKey: ['calculators', 'manufacturing-browser', query],
    queryFn: async () => unwrap(await api.GET('/calculators/manufacturing-browser', { params: { query: query! } })),
    enabled: query !== null,
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}


export function bonusesOf(structure: Structure | undefined): { me: number; te: number } {
  return structure ? structure.effective_bonuses : { me: 0, te: 0 }
}

export type ReactionBrowserRequest = Schemas['ReactionBrowserRequest']


export function useReactionBrowse(body: ReactionBrowserRequest | null) {
  return useQuery({
    queryKey: ['calculators', 'reaction-browser', body],
    queryFn: async () => unwrap(await api.POST('/calculators/reaction-browser', { body: body! })),
    enabled: body !== null,
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: false,
  })
}
