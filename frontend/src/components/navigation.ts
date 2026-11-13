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
    { label: 'Character Assets', to: '/assets' },
    { label: 'Corporation Assets', to: '/assets/corporation' },
    { label: 'Materials', to: '/assets/materials' },
    { label: 'Ships', to: '/assets/ships' },
    { label: 'Abyssal Items', to: '/assets/abyssal' },
  ] },
  { id: 'industry', label: 'Industry', links: [
    { label: 'Slots', to: '/industry/slots' },
    { label: 'Jobs Overview', to: '/industry/jobs' },
    { label: 'Most Profitable', to: '/industry/most-profitable' },
    { label: 'Can Produce', to: '/industry/can-produce' },
    { label: 'Reprocessing Value', to: '/industry/reprocessing' },
    { label: 'Blueprint Research', to: '/industry/blueprint-research' },
    { label: 'Reaction Formula Research', to: '/industry/reaction-formula-research' },
    { label: 'Stock Manager', to: '/industry/stock-manager', stockManager: true },
  ] },
  { id: 'industry-planning', label: 'Industry Planning', links: [
    { label: 'Manufacturing', to: '/calculators/manufacturing' },
    { label: 'Invention Planner', to: '/calculators/invention-planner' },
    { label: 'Reactions Planner', to: '/calculators/reaction-planner' },
    { label: 'Reaction Browser', to: '/calculators/reaction-browser' },
    { label: 'Manufacturing Browser', to: '/calculators/manufacturing-browser' },
    { label: 'My Structures', to: '/structures' },
    { label: 'Build Programs', to: '/build-programs' },
  ] },
  { id: 'planetary', label: 'Planetary', links: [
    { label: 'PI Dashboard', to: '/planetary' },
    { label: 'Factory Planner', to: '/planetary/factory-planner' },
  ] },
  { id: 'tools', label: 'Tools', links: [
    { label: 'Contract Appraisal', to: '/tools/contract-appraisal' },
    { label: 'Contract Browser', to: '/tools/contract-browser' },
    { label: 'Moon Scan Analyser', to: '/tools/moon-scan' },
    { label: 'Market Browser', to: '/market/browser' },
    { label: 'Station Trading', to: '/market/station-trading' },
    { label: 'Region Arbitrage', to: '/market/region-arbitrage' },
    { label: 'Market Data', to: '/market-status' },
  ] },
  { id: 'corporate', label: 'Corporate', links: [
    { label: 'Donations', to: '/corporate/donations' },
    { label: 'Projects', to: '/corporate/projects' },
    { label: 'Project Contributors', to: '/corporate/projects/contributors' },
    { label: 'Corp Build Programs', to: '/corporate/build-programs' },
    { label: 'Structure Timers', to: '/corporate/structure-vulnerability' },
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
