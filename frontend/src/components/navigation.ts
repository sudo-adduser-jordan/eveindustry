export interface NavigationLink {
  label: string
  to: string
  stockManager?: boolean
}

export interface NavigationGroup {
  id: string
  label: string
  admin?: boolean
  links: NavigationLink[]
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
	  { label: 'Jobs', to: '/industry/jobs' },
	  { label: 'Slots', to: '/industry/slots' },
	  { label: 'Can Produce', to: '/industry/can-produce' },
	  { label: 'Most Profitable', to: '/industry/most-profitable' },
	  { label: 'Build Programs', to: '/build-programs' },
	  { label: 'Reprocessing Value', to: '/industry/reprocessing' },
	  { label: 'Manufacturing Calculator', to: '/calculators/manufacturing' },
	  { label: 'Manufacturing Browser', to: '/calculators/manufacturing-browser' },
	  { label: 'Reaction Browser', to: '/calculators/reaction-browser' },
	  { label: 'Invention Planner', to: '/calculators/invention-planner' },
	  { label: 'Reactions Planner', to: '/calculators/reaction-planner' },
	  { label: 'Stock Manager', to: '/industry/stock-manager', stockManager: true },
  ] },
  { id: 'planetary', label: 'Planetary', links: [
    { label: 'PI Dashboard', to: '/planetary' },
    { label: 'Factory Planner', to: '/planetary/factory-planner' },
  ] },
  { id: 'tools', label: 'Tools', links: [
	  { label: 'Market Browser', to: 'https://evemarketbrowser.com/region/' },
	  { label: 'Item Appraisal', to: 'https://janice.e-351.com/' },
	  { label: 'Contract Browser', to: '/tools/contract-browser' },
	  { label: 'Station Trading', to: '/market/station-trading' },
	  { label: 'Region Arbitrage', to: '/market/region-arbitrage' },
	  { label: 'Moon Scan Analyser', to: '/tools/moon-scan' },
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

export function groupForPath(pathname: string): string | null {
  return navigationGroups.find((group) => group.links.some(({ to }) => pathname === to || (to !== '/' && pathname.startsWith(`${to}/`))))?.id ?? null
}
