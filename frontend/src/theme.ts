import { useCallback, useEffect, useState } from 'react'

/** A bundled look. Colours live in `styles/themes/<id>.css`, scoped to
 *  `:root[data-theme='<id>']`; this registry only carries metadata. */
export interface ThemeDefinition {
  id: string
  label: string
  blurb: string
  themeColor: string
}

export const THEME_STORAGE_KEY = 'eve-industry-theme'
export const DEFAULT_THEME = 'industrialist'

export const THEMES: ThemeDefinition[] = [
  { id: 'industrialist', label: 'Industrialist', blurb: 'Sci-fi operations look: chamfered panels, neon accents.', themeColor: '#0d1520' },
  { id: 'eve-online', label: 'EVE Online', blurb: 'In-game client: framed windows, title bands, amber highlights.', themeColor: '#070b12' },
  { id: 'amarr', label: 'Amarr Empire', blurb: 'Imperial gold with square double frames and tracked capitals.', themeColor: '#0d0a06' },
  { id: 'caldari', label: 'Caldari State', blurb: 'Corporate steel-cyan: flat, thin lines, sentence case.', themeColor: '#0a1218' },
  { id: 'gallente', label: 'Gallente Federation', blurb: 'Emerald and teal, rounded and softly elevated.', themeColor: '#08120f' },
  { id: 'minmatar', label: 'Minmatar Republic', blurb: 'Rust and amber, square panels with a heavy accent stripe.', themeColor: '#100c0a' },
  { id: 'dark', label: 'Dark', blurb: 'Flat and quiet: no glows, system type, sentence-case headings.', themeColor: '#111114' },
  { id: 'light', label: 'Light', blurb: 'Soft daylight: rounded cards, gentle shadows, pill buttons.', themeColor: '#f2f4f9' },
]

export function isThemeId(value: unknown): value is string {
  return typeof value === 'string' && THEMES.some((theme) => theme.id === value)
}

export function readTheme(): string {
  try {
    const saved = localStorage.getItem(THEME_STORAGE_KEY)
    if (isThemeId(saved)) return saved
  } catch {
    // Storage unavailable (private mode): fall through to the default.
  }
  return DEFAULT_THEME
}

export function themeColorFor(id: string): string {
  return THEMES.find((theme) => theme.id === id)?.themeColor ?? THEMES[0]?.themeColor ?? '#0d1520'
}

/** Apply a theme now: `data-theme` selects the token overrides, the
 *  `theme-color` meta tints the browser chrome, and the choice persists. */
export function applyTheme(id: string): void {
  const theme = isThemeId(id) ? id : DEFAULT_THEME
  document.documentElement.dataset.theme = theme
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', themeColorFor(theme))
  try {
    localStorage.setItem(THEME_STORAGE_KEY, theme)
  } catch {
    // Storage unavailable: the theme still applies for this page.
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
