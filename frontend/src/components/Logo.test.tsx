import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { render } from '@testing-library/react'
import { Logo } from './Logo'

const here = dirname(fileURLToPath(import.meta.url))
const themeCss = readFileSync(join(here, '..', 'styles', 'theme.css'), 'utf8')

it('paints the sidebar logo from theme tokens instead of fixed colours', () => {
  const { container } = render(<Logo />)
  const svg = container.querySelector('svg')
  expect(svg).not.toBeNull()
  const html = svg?.outerHTML ?? ''
  expect(html).toContain('var(--accent)')
  expect(html).toContain('var(--accent2)')
  expect(html).not.toContain('#f0b429')
  expect(html).not.toContain('#7dd3fc')
})

it('honours the requested size', () => {
  const { container } = render(<Logo size={24} />)
  const svg = container.querySelector('svg')
  expect(svg).toHaveAttribute('width', '24')
  expect(svg).toHaveAttribute('height', '24')
})

it('keeps the favicon branding on neutral themes', () => {
  for (const theme of ['dark', 'light']) {
    expect(themeCss).toContain(`:root[data-theme='${theme}']`)
  }
  for (const colour of ['#f0b429', '#7dd3fc']) {
    expect(themeCss).toContain(colour)
  }
})

it('gives the light neutral logo a white tile and the dark one a dark tile', () => {
  const lightBlock = themeCss.match(/:root\[data-theme='light'\]\s*\{[^}]*\}/)?.[0] ?? ''
  const darkBlock = themeCss.match(/:root\[data-theme='dark'\]\s*\{[^}]*\}/)?.[0] ?? ''
  expect(lightBlock).toContain('--logo-bg: #ffffff')
  expect(darkBlock).toContain('--logo-bg: #0d1520')
})
