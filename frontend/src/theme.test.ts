import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLocalStorage } from './test/storage'
import { applyTheme, DEFAULT_THEME, isThemeId, readTheme, THEMES, THEME_GROUPS, THEME_STORAGE_KEY, themeColorFor } from './theme'

ensureLocalStorage()

const here = dirname(fileURLToPath(import.meta.url))
function themeCss(name: string): string {
  return readFileSync(join(here, 'styles/themes', name), 'utf8')
}

const themeFiles: Record<string, string> = {
  carbon: themeCss('carbon.css'),
  'eve-online': themeCss('eve-online.css'),
  amarr: themeCss('amarr.css'),
  caldari: themeCss('caldari.css'),
  gallente: themeCss('gallente.css'),
  minmatar: themeCss('minmatar.css'),
  'angel-cartel': themeCss('angel-cartel.css'),
  'blood-raiders': themeCss('blood-raiders.css'),
  guristas: themeCss('guristas.css'),
  sansha: themeCss('sansha.css'),
  serpentis: themeCss('serpentis.css'),
  dark: themeCss('dark.css'),
  light: themeCss('light.css'),
}
const tokens = readFileSync(join(here, 'styles/tokens.css'), 'utf8')

function customProperties(css: string): string[] {
  return [...css.matchAll(/--[\w-]+/g)].map((m) => m[0])
}

beforeEach(() => {
  localStorage.clear()
  delete document.documentElement.dataset.theme
  
  if (!document.querySelector('meta[name="theme-color"]')) {
    const meta = document.createElement('meta')
    meta.setAttribute('name', 'theme-color')
    document.head.appendChild(meta)
  }
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', '#0e0f11')
})

it('registers one theme file per theme id', () => {
  expect(THEMES.map((t) => t.id).sort()).toEqual(Object.keys(themeFiles).sort())
  for (const { id } of THEMES) {
    expect(themeFiles[id]).toContain(`:root[data-theme='${id}']`)
  }
})

it('only overrides tokens defined in tokens.css', () => {
  const known = new Set(customProperties(tokens))
  expect(known.size).toBeGreaterThan(10)
  for (const [id, css] of Object.entries(themeFiles)) {
    for (const prop of customProperties(css)) {
      expect(known.has(prop), `${id} sets unknown token ${prop}`).toBe(true)
    }
  }
})

it('keeps each accent and its -rgb twin in sync', () => {
  for (const [id, css] of Object.entries(themeFiles)) {
    for (const base of ['--accent', '--accent2']) {
      if (css.includes(`${base}:`)) {
        expect(css, `${id} changes ${base} without ${base}-rgb`).toContain(`${base}-rgb:`)
      }
    }
  }
})

it('defaults to carbon and rejects unknown saved values', () => {
  expect(readTheme()).toBe(DEFAULT_THEME)
  localStorage.setItem(THEME_STORAGE_KEY, 'dark')
  expect(readTheme()).toBe('dark')
  localStorage.setItem(THEME_STORAGE_KEY, 'midnight')
  expect(readTheme()).toBe(DEFAULT_THEME)
  expect(isThemeId('light')).toBe(true)
  expect(isThemeId('midnight')).toBe(false)
})

it('applies the theme to the document, the meta tint and storage', () => {
  applyTheme('light')
  expect(document.documentElement.dataset.theme).toBe('light')
  expect(document.querySelector('meta[name="theme-color"]')).toHaveAttribute('content', themeColorFor('light'))
  expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('light')
})

it('falls back to the default for unknown themes', () => {
  applyTheme('midnight')
  expect(document.documentElement.dataset.theme).toBe(DEFAULT_THEME)
  expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe(DEFAULT_THEME)
})

it('ships the core, empire and pirate themes, and never the website theme', () => {
  const ids = THEMES.map((t) => t.id)
  expect(ids).toEqual(expect.arrayContaining([
    'carbon', 'eve-online',
    'amarr', 'caldari', 'gallente', 'minmatar',
    'angel-cartel', 'blood-raiders', 'guristas', 'sansha', 'serpentis',
    'dark', 'light',
  ]))
  expect(new Set(ids).size, 'theme ids must be unique').toBe(ids.length)
  expect(isThemeId('eve-online-website')).toBe(false)
})

it('groups every theme into the picker sections', () => {
  expect(THEME_GROUPS).toEqual(['Core', 'Empires', 'Pirates', 'Neutral'])
  for (const theme of THEMES) {
    expect(theme.label, `${theme.id} needs a label`).toBeTruthy()
    expect(theme.group, `${theme.id} needs a group`).toBeTruthy()
  }
})


it('changes more than colours in every non-default theme', () => {
  const structural = ['clip-path', 'border-radius', 'text-transform', 'box-shadow', 'font-display', 'content: none']
  for (const [id, css] of Object.entries(themeFiles)) {
    const hits = structural.filter((prop) => css.includes(prop))
    expect(hits.length, `${id} only changes colours`).toBeGreaterThanOrEqual(2)
  }
})

it('gives EVE Online window chrome with a title band', () => {
  const css = themeFiles['eve-online'] ?? ''
  expect(css).toContain('clip-path: none')
  expect(css).toContain('title band')
  expect(css).toContain('.panel > :is(h2, h3):first-child')
  expect(css).toContain('font-display: \'Rajdhani\'')
})
