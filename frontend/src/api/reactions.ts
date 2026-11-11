import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, unwrap, type ApiErrorBody } from './client'
import type { components } from './schema'

type Schemas = components['schemas']

export type ReactionRequest = Schemas['ReactionsCalculateRequest']
export type ReactionResult = Schemas['ReactionJobResource']
export type ReactionPlanRequest = Schemas['ReactionPlannerCalculateRequest']
export type ReactionPlanResult = Schemas['ReactionPlanCalculationResource']
export type SavedReactionPlan = Schemas['ReactionPlanResource']
export type ReactionPlanBody = Schemas['ReactionPlanStoreRequest']
export type ReactionProgramRequest = Schemas['StoreReactionPlanProgramRequest']


export function useFormulaSearch(q: string) {
  return useQuery({
    queryKey: ['reference', 'reaction-formulas', q],
    queryFn: async () => unwrap(await api.GET('/reactions/formulas', { params: { query: { q } } })),
    enabled: q.length >= 2,
    staleTime: 10 * 60_000,
  })
}


export function useFormulaActivity(formulaTypeId: number | null) {
  return useQuery({
    queryKey: ['reference', 'reaction-formula', formulaTypeId],
    queryFn: async () =>
      unwrap(await api.GET('/reactions/formulas/{formulaTypeId}', { params: { path: { formulaTypeId: formulaTypeId! } } })),
    enabled: formulaTypeId !== null,
    staleTime: 10 * 60_000,
    retry: false,
  })
}


export function useReactionSkills(characterId: number | null) {
  return useQuery({
    queryKey: ['calculators', 'reactions', 'skills', characterId],
    queryFn: async () =>
      unwrap(await api.GET('/calculators/reactions/skills', { params: { query: { character_id: characterId! } } })),
    enabled: characterId !== null,
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export function useCalculateReaction() {
  return useMutation({
    mutationFn: async (body: ReactionRequest) => unwrap(await api.POST('/calculators/reactions', { body })),
  })
}

export function useCalculateReactionPlan() {
  return useMutation({
    mutationFn: async (body: ReactionPlanRequest) => unwrap(await api.POST('/calculators/reaction-planner', { body })),
  })
}


export function useReactionStock(typeIds: number[]) {
  return useQuery({
    queryKey: ['calculators', 'reaction-stock', typeIds],
    queryFn: async () => unwrap(await api.POST('/calculators/reaction-planner/stock', { body: { type_ids: typeIds } })),
    enabled: typeIds.length > 0,
    staleTime: 60_000,
  })
}


export function useFormulaStock(formulaTypeIds: number[]) {
  return useQuery({
    queryKey: ['calculators', 'formula-stock', formulaTypeIds],
    queryFn: async () =>
      unwrap(await api.POST('/calculators/reaction-planner/formula-stock', { body: { formula_type_ids: formulaTypeIds } })),
    enabled: formulaTypeIds.length > 0,
    staleTime: 60_000,
  })
}


export function useReactionPlans() {
  return useQuery({
    queryKey: ['plans', 'reaction'],
    queryFn: async () => unwrap(await api.GET('/reaction-plans', { params: { query: { per_page: 200 } } })),
    staleTime: 60_000,
  })
}


export function useSaveReactionPlan() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, body }: { id: number | null; body: ReactionPlanBody }) =>
      id === null
        ? unwrap(await api.POST('/reaction-plans', { body }))
        : unwrap(await api.PUT('/reaction-plans/{plan}', { params: { path: { plan: id } }, body })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['plans', 'reaction'] }),
  })
}

export function useDeleteReactionPlan() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => {
      const { response, error } = await api.DELETE('/reaction-plans/{plan}', { params: { path: { plan: id } } })
      if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
    },
    onSuccess: () => client.invalidateQueries({ queryKey: ['plans', 'reaction'] }),
  })
}


export function useCreateReactionProgram(corporate: boolean) {
  return useMutation({
    mutationFn: async (body: ReactionProgramRequest) =>
      unwrap(
        corporate
          ? await api.POST('/corporation/build-programs/from-reaction-plan', { body })
          : await api.POST('/build-programs/from-reaction-plan', { body }),
      ),
  })
}
