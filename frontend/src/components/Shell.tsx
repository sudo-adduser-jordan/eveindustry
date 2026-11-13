import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useEffect, useState } from 'react'
import { useMe } from '../api/queries'
import { useSessionActions } from '../api/account'
import { THEMES, THEME_GROUPS, useTheme } from '../theme'
import { InstallPrompt } from './InstallPrompt'
import { DemoBanner } from './DemoBanner'
import logoIcon from '../assets/logo-icon.svg'
import { PwaStatus } from './PwaStatus'
import { groupForPath, navigationGroups, NAVIGATION_STORAGE_KEY } from './navigation'

export function Shell() {
  const me = useMe()
  const session = useSessionActions()
  const [theme, setTheme] = useTheme()
  const { pathname } = useLocation()
  const activeGroup = groupForPath(pathname)
  const [openGroup, setOpenGroup] = useState<string | null>(() => {
    if (activeGroup) return activeGroup
    try {
      const saved = localStorage.getItem(NAVIGATION_STORAGE_KEY)
      return navigationGroups.some(({ id }) => id === saved) ? saved : null
    } catch { return null }
  })

  useEffect(() => {
    if (activeGroup) setOpenGroup(activeGroup)
  }, [activeGroup])

  useEffect(() => {
    try {
      if (openGroup) localStorage.setItem(NAVIGATION_STORAGE_KEY, openGroup)
      else localStorage.removeItem(NAVIGATION_STORAGE_KEY)
    } catch {  }
  }, [openGroup])

  
  const [navOpen, setNavOpen] = useState(false)
  useEffect(() => { setNavOpen(false) }, [pathname])
  useEffect(() => {
    if (!navOpen) return
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setNavOpen(false) }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [navOpen])

  return (
    <div className="shell">
      <header className="topbar">
        <button type="button" className="burger" aria-label="Toggle navigation" aria-expanded={navOpen} aria-controls="main-sidebar" onClick={() => setNavOpen(!navOpen)}>
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
        </button>
        <NavLink className="topbar-brand" to="/dashboard"><img src={logoIcon} alt="" width="24" height="24" /><span>Eve Industrialist</span></NavLink>
      </header>
      <div className={`sidebar-overlay${navOpen ? ' open' : ''}`} onClick={() => setNavOpen(false)} aria-hidden="true" />
      <aside id="main-sidebar" className={`sidebar${navOpen ? ' open' : ''}`} aria-label="Main navigation">
        <div className="sidebar-head">
          <NavLink className="brand" to="/dashboard"><img src={logoIcon} alt="" width="28" height="28" /><span>Eve Industrialist</span></NavLink>
          <button type="button" className="sidebar-close" aria-label="Close navigation" onClick={() => setNavOpen(false)}>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
          </button>
        </div>
        <nav id="app-navigation">
          <NavLink to="/dashboard">Dashboard</NavLink>
          <NavLink to="/characters">Characters</NavLink>
          {navigationGroups.filter((group) => !group.admin || me.data?.data.admin).map((group) => {
            const expanded = openGroup === group.id
            return (
              <div className="nav-group" key={group.id}>
                <button className={`nav-group-toggle${activeGroup === group.id ? ' has-active-page' : ''}`} aria-expanded={expanded} aria-controls={`nav-${group.id}`} onClick={() => setOpenGroup(expanded ? null : group.id)}>
                  <span>{group.label}</span><span className="nav-chevron" aria-hidden="true">⌄</span>
                </button>
                <div id={`nav-${group.id}`} className="nav-submenu" hidden={!expanded}>
                  {group.links.filter((link) => !link.stockManager || me.data?.data.stock_manager).map((link) => (
                    <NavLink key={link.to} to={link.to} end={group.links.some((other) => other.to !== link.to && other.to.startsWith(`${link.to}/`)) || link.to === '/'}>{link.label}</NavLink>
                  ))}
                </div>
              </div>
            )
          })}
        </nav>
        <div className="sidebar-foot">
          {me.data ? <p className="sidebar-user">{me.data.data.avatar_url ? <img src={me.data.data.avatar_url} alt="" width="28" height="28" /> : null}<span>{me.data.data.display_name}</span></p> : null}
          {me.data?.data.demo ? null : <p><a href="/auth/eve/scopes">Add character</a></p>}
          <p className="theme-setting">
            <label htmlFor="theme-select">Theme</label>
            <select id="theme-select" value={theme} onChange={(e) => setTheme(e.target.value)}>
              {THEME_GROUPS.map((group) => (
                <optgroup key={group} label={group}>
                  {THEMES.filter((t) => t.group === group).map((t) => <option key={t.id} value={t.id} title={t.blurb}>{t.label}</option>)}
                </optgroup>
              ))}
            </select>
          </p>
          <button type="button" className="button small" disabled={session.logout.isPending} onClick={() => session.logout.mutate()}>Log out</button>
          {session.logout.error ? <p role="alert">{session.logout.error.message}</p> : null}
        </div>
      </aside>
      <main className="content">
        {me.data?.data.demo ? <DemoBanner /> : null}
        <PwaStatus /><Outlet />
      </main>
      <InstallPrompt />
    </div>
  )
}
