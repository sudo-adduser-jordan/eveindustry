import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, unwrap, type ApiErrorBody } from './client'
import type { components } from './schema'

type Schemas = components['schemas']

export type InventionRequest = Schemas['InventionCalculateRequest']
export type InventionResult = Schemas['InventionJobResource']
export type InventionChain = Schemas['InventionChainResource']
export type InventionPlanBody = Schemas['InventionPlanStoreRequest']
export type SavedInventionPlan = Schemas['InventionPlanResource']


export function useInventionSearch(q: string) {
  return useQuery({
    queryKey: ['reference', 'invention-items', q],
    queryFn: async () => unwrap(await api.GET('/invention/items', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 10 * 60_000,
  })
}


export function useInventionChain(typeId: number | null) {
  return useQuery({
    queryKey: ['reference', 'invention-chain', typeId],
    queryFn: async () => unwrap(await api.GET('/invention/items/{typeId}', { params: { path: { typeId: typeId! } } })),
    enabled: typeId !== null,
    staleTime: 10 * 60_000,
    retry: false,
  })
}


export function useDecryptors() {
  return useQuery({
    queryKey: ['calculators', 'invention', 'decryptors'],
    queryFn: async () => unwrap(await api.GET('/calculators/invention/decryptors')),
    staleTime: 60 * 60_000,
  })
}


export function useInventionSkills(characterId: number | null) {
  return useQuery({
    queryKey: ['calculators', 'invention', 'skills', characterId],
    queryFn: async () =>
      unwrap(await api.GET('/calculators/invention/skills', { params: { query: { character_id: characterId! } } })),
    enabled: characterId !== null,
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export function useCalculateInvention() {
  return useMutation({
    mutationFn: async (body: InventionRequest) => unwrap(await api.POST('/calculators/invention', { body })),
  })
}

export function useInventionPlans() {
  return useQuery({
    queryKey: ['plans', 'invention'],
    queryFn: async () => unwrap(await api.GET('/invention-plans', { params: { query: { per_page: 200 } } })),
    staleTime: 60_000,
  })
}


export function useSaveInventionPlan() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number | null; body: InventionPlanBody }) =>
      id === null
        ? unwrap(await api.POST('/invention-plans', { body }))
        : unwrap(await api.PUT('/invention-plans/{plan}', { params: { path: { plan: id } }, body })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['plans', 'invention'] }),
  })
}

export function useDeleteInventionPlan() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      const { response, error } = await api.DELETE('/invention-plans/{plan}', { params: { path: { plan: id } } })
      if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
    },
    onSuccess: () => client.invalidateQueries({ queryKey: ['plans', 'invention'] }),
  })
}
