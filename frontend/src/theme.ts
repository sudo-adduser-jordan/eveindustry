import { useCallback, useEffect, useState } from 'react'


export interface ThemeDefinition {
  id: string
  label: string
  blurb: string
  themeColor: string
  
  group: string
}

export const THEME_STORAGE_KEY = 'eve-industry-theme'
export const DEFAULT_THEME = 'carbon'

export const THEMES: ThemeDefinition[] = [
  { id: 'carbon', label: 'Carbon', group: 'Core', blurb: 'The classic client UI: charcoal frame, steel-blue highlights.', themeColor: '#0e0f11' },
  { id: 'eve-online', label: 'EVE Online', group: 'Core', blurb: 'In-game client: framed windows, title bands, amber highlights.', themeColor: '#070b12' },

  { id: 'amarr', label: 'Amarr Empire', group: 'Empires', blurb: 'Imperial gold with square double frames and tracked capitals.', themeColor: '#0d0a06' },
  { id: 'caldari', label: 'Caldari State', group: 'Empires', blurb: 'Corporate steel-cyan: flat, thin lines, sentence case.', themeColor: '#0a1218' },
  { id: 'gallente', label: 'Gallente Federation', group: 'Empires', blurb: 'Emerald and teal, rounded and softly elevated.', themeColor: '#08120f' },
  { id: 'minmatar', label: 'Minmatar Republic', group: 'Empires', blurb: 'Rust and amber, square panels with a heavy accent stripe.', themeColor: '#100c0a' },

  { id: 'angel-cartel', label: 'Angel Cartel', group: 'Pirates', blurb: 'Blood red and silver; reverse-chamfered, hard corners.', themeColor: '#120a0c' },
  { id: 'blood-raiders', label: 'Blood Raider Covenant', group: 'Pirates', blurb: 'Near-black crimson with a heavy red cap on each panel.', themeColor: '#0f0507' },
  { id: 'guristas', label: 'Guristas Pirates', group: 'Pirates', blurb: 'Gunmetal with orange-red highlights and soft inner edges.', themeColor: '#101216' },
  { id: 'sansha', label: 'Sansha\u2019s Nation', group: 'Pirates', blurb: 'Bone white and signal red, stark double frames.', themeColor: '#0d0d10' },
  { id: 'serpentis', label: 'Serpentis', group: 'Pirates', blurb: 'Deep green jade, rounded and quietly elevated.', themeColor: '#0a1010' },

  { id: 'dark', label: 'Dark', group: 'Neutral', blurb: 'Flat and quiet: no glows, system type, sentence-case headings.', themeColor: '#111114' },
  { id: 'light', label: 'Light', group: 'Neutral', blurb: 'Soft daylight: rounded cards, gentle shadows, pill buttons.', themeColor: '#f2f4f9' },
]


export const THEME_GROUPS: string[] = THEMES.reduce<string[]>((groups, theme) => {
  if (!groups.includes(theme.group)) groups.push(theme.group)
  return groups
}, [])

export function isThemeId(value: unknown): value is string {
  return typeof value === 'string' && THEMES.some((theme) => theme.id === value)
}

export function readTheme(): string {
  try {
    const saved = localStorage.getItem(THEME_STORAGE_KEY)
    if (isThemeId(saved)) return saved
  } catch {
    
  }
  return DEFAULT_THEME
}

export function themeColorFor(id: string): string {
  return THEMES.find((theme) => theme.id === id)?.themeColor ?? THEMES[0]?.themeColor ?? '#0e0f11'
}


export function applyTheme(id: string): void {
  const theme = isThemeId(id) ? id : DEFAULT_THEME
  document.documentElement.dataset.theme = theme
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', themeColorFor(theme))
  try {
    localStorage.setItem(THEME_STORAGE_KEY, theme)
  } catch {
    
  }
}

export function useTheme(): [string, (id: string) => void] {
  const [theme, setThemeState] = useState(readTheme)
  useEffect(() => {
    applyTheme(theme)
  }, [theme])
  const setTheme = useCallback((id: string) => {
    setThemeState(isThemeId(id) ? id : DEFAULT_THEME)
  }, [])
  return [theme, setTheme]
}
