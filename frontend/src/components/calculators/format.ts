import { formatIsk } from '../format'


export function formatDuration(seconds: number): string {
  const s = Math.max(0, Math.round(seconds))
  const d = Math.floor(s / 86400)
  const h = Math.floor((s % 86400) / 3600)
  const m = Math.floor((s % 3600) / 60)
  const parts: string[] = []
  if (d > 0) parts.push(`${d}d`)
  if (h > 0 || d > 0) parts.push(`${h}h`)
  if (m > 0 || h > 0 || d > 0) parts.push(`${m}m`)
  parts.push(`${s % 60}s`)
  return parts.join(' ')
}


export function isk(value: number): string {
  return `${formatIsk(value)} ISK`
}


export function signedIsk(value: number): string {
  return `${value >= 0 ? '+' : '-'}${formatIsk(Math.abs(value))} ISK`
}

const volumeFormat = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })


export function formatVolume(m3: number): string {
  return `${volumeFormat.format(m3)} m³`
}


export function profitClass(value: number): string {
  return value >= 0 ? 'text-profit' : 'text-loss'
}


export function productName(blueprintName: string): string {
  return blueprintName.replace(/ Blueprint$/, '')
}


export function csvCell(value: string | number): string {
  return `"${String(value).replace(/"/g, '""')}"`
}
