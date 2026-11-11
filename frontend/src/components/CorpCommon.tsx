import { ApiError } from '../api/client'


export function formatIskWhole(n: number): string {
  return `${Math.round(n).toLocaleString('en-US')} ISK`
}


export function formatUtc(iso: string): string {
  return new Date(iso).toISOString().slice(0, 16).replace('T', ' ')
}


export function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}


export function errorText(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const first = error.errors ? Object.values(error.errors)[0]?.[0] : undefined
    return first ?? (error.message.startsWith('Request failed') ? fallback : error.message)
  }
  return fallback
}

export function isStatus(error: unknown, status: number): boolean {
  return error instanceof ApiError && error.status === status
}


export function ErrorNotice({ error, what, unavailable }: { error: unknown; what: string; unavailable: string }) {
  if (isStatus(error, 403)) {
    return (
      <p role="alert" className="panel warn">
        <strong>{unavailable}</strong> {(error as ApiError).message}
      </p>
    )
  }
  if (isStatus(error, 404)) {
    return (
      <p role="alert" className="panel warn">
        Not found.
      </p>
    )
  }
  return <p role="alert">Could not load {what}.</p>
}


export function WriteError({ error, fallback }: { error: unknown; fallback: string }) {
  if (!error) return null
  return (
    <p role="alert" className="panel warn">
      {errorText(error, fallback)}
    </p>
  )
}
