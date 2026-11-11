const whole = new Intl.NumberFormat('en-US')


export function formatQty(n: number): string {
  return whole.format(n)
}


export function formatIsk(value: number): string {
  const abs = Math.abs(value)
  if (abs >= 1e9) return `${(value / 1e9).toFixed(2)}B`
  if (abs >= 1e6) return `${(value / 1e6).toFixed(2)}M`
  if (abs >= 1e3) return `${(value / 1e3).toFixed(2)}K`
  return value.toFixed(2)
}


const DEMO_ID_FLOOR = 99_000_000_000

export function portraitUrl(characterId: number, size = 32): string {
  return characterId >= DEMO_ID_FLOOR
    ? `/demo/portrait/${characterId}.svg?size=${size}`
    : `https://images.evetech.net/characters/${characterId}/portrait?size=${size}`
}
