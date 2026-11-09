import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, type ApiErrorBody } from './client'
import { forgetThisDevice } from './notifications'
import { leaveSession } from './persistence'

async function done(request: Promise<{ response: Response; error?: unknown }>) {
  const { response, error } = await request
  if (!response.ok) throw new ApiError(response.status, error as ApiErrorBody | undefined)
}

export function useCharacterActions() {
  const client = useQueryClient()
  const refresh = () => client.invalidateQueries()
  return {
    main: useMutation({ mutationFn: (character: number) => done(api.POST('/characters/{character}/main', { params: { path: { character } } })), onSuccess: refresh }),
    remove: useMutation({ mutationFn: (character: number) => done(api.DELETE('/characters/{character}', { params: { path: { character } } })), onSuccess: refresh }),
    deleteAccount: useMutation({ mutationFn: () => done(api.DELETE('/account')), onSuccess: () => leaveSession('/app/login') }),
  }
}

export function useSessionActions() {
  return {
    logout: useMutation({ mutationFn: async () => { await forgetThisDevice().catch(() => undefined); return done(api.POST('/logout')) }, onSuccess: () => leaveSession('/app/login') }),
  }
}
