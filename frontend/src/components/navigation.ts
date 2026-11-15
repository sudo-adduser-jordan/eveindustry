export interface NavigationLink {
  label: string
  to: string
  stockManager?: boolean
}

export interface NavigationSeparator {
  separator: true
  stockManager?: boolean
}

export type NavigationEntry = NavigationLink | NavigationSeparator

export function isSeparator(entry: NavigationEntry): entry is NavigationSeparator {
  return (entry as NavigationSeparator).separator === true
}

export interface NavigationGroup {
  id: string
  label: string
  admin?: boolean
  links: NavigationEntry[]
}


export const navigationGroups: NavigationGroup[] = [
  { id: 'assets', label: 'Assets', links: [
    { label: 'Character', to: '/assets' },
    { label: 'Corporation', to: '/assets/corporation' },
    { label: 'Materials', to: '/assets/materials' },
    { label: 'Blueprints', to: '/industry/blueprint-research' },
    { label: 'Formulas', to: '/industry/reaction-formula-research' },
  ] },
  { id: 'industry', label: 'Industry', links: [
	  { label: 'Structures', to: '/structures' },
	  { label: 'Jobs', to: '/industry/slots' },
	  { separator: true },
	  { label: 'Build Programs', to: '/build-programs' },
	  { label: 'Manufacturing', to: '/calculators/manufacturing' },
	  { label: 'Invention', to: '/calculators/invention-planner' },
	  { label: 'Reaction', to: '/calculators/reaction-planner' },
	  { separator: true },
	  { label: 'Can Produce', to: '/industry/can-produce' },
	  { label: 'Most Profitable', to: '/industry/most-profitable' },
	  { label: 'Stock Manager', to: '/industry/stock-manager', stockManager: true },
	  { label: 'Reprocessing Value', to: '/industry/reprocessing' },
	  { separator: true },
	  { label: 'Manufacturing Browser', to: '/calculators/manufacturing-browser' },
	  { label: 'Reaction Browser', to: '/calculators/reaction-browser' },
  ] },
  { id: 'planetary', label: 'Planetary', links: [
    { label: 'PI Dashboard', to: '/planetary' },
    { label: 'Factory Browser', to: '/planetary/factory-planner' },
  ] },
  { id: 'tools', label: 'Tools', links: [
	  { label: 'Market Browser', to: 'https://evemarketbrowser.com/region/' },
	  { label: 'Item Appraisal', to: 'https://janice.e-351.com/' },
	  { separator: true },
	  { label: 'Contract Browser', to: '/tools/contract-browser' },
	  { label: 'Station Trading', to: '/market/station-trading' },
	  { label: 'Region Arbitrage', to: '/market/region-arbitrage' },
  ] },
  { id: 'admin', label: 'Admin', admin: true, links: [
    { label: 'Dashboard', to: '/admin/dashboard' },
    { label: 'Users', to: '/admin/users' },
    { label: 'Sponsors', to: '/admin/sponsors' },
    { label: 'ESI Diagnostics', to: '/admin/esi-diagnostics' },
    { label: 'Settings', to: '/admin/settings' },
  ] },
]

export const NAVIGATION_STORAGE_KEY = 'eve-industry-navigation-group'

export function isExternalLink(to: string): boolean {
  return to.startsWith('http://') || to.startsWith('https://')
}

export function groupForPath(pathname: string): string | null {
  return navigationGroups.find((group) => group.links.some((entry) => !isSeparator(entry) && (pathname === entry.to || (entry.to !== '/' && pathname.startsWith(`${entry.to}/`)))))?.id ?? null
}
