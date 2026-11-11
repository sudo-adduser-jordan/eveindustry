import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useEffect, useState } from 'react'
import { useMe } from '../api/queries'
import { useSessionActions } from '../api/account'
import { usePushSync } from '../api/notifications'
import { THEMES, useTheme } from '../theme'
import { InstallPrompt } from './InstallPrompt'
import { DemoBanner } from './DemoBanner'
import logoIcon from '../assets/logo-icon.svg'
import { PwaStatus } from './PwaStatus'
import { groupForPath, navigationGroups, NAVIGATION_STORAGE_KEY } from './navigation'

function DiscordIcon() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
      <path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z" />
    </svg>
  )
}

export function Shell() {
  const me = useMe()
  const session = useSessionActions()
  const [theme, setTheme] = useTheme()
  usePushSync(Boolean(me.data) && !me.data?.data.demo)
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
    } catch { /* Navigation also works when browser storage is unavailable. */ }
  }, [openGroup])

  // Below 992px the sidebar slides in over the page; see the media query in theme.css.
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
          <NavLink to="/notifications">Notifications</NavLink>
          {navigationGroups.filter((group) => !group.admin || me.data?.data.admin).map((group) => {
            const expanded = openGroup === group.id
            return (
              <div className="nav-group" key={group.id}>
                <button className={`nav-group-toggle${activeGroup === group.id ? ' has-active-page' : ''}`} aria-expanded={expanded} aria-controls={`nav-${group.id}`} onClick={() => setOpenGroup(expanded ? null : group.id)}>
                  <span>{group.label}</span><span className="nav-chevron" aria-hidden="true">⌄</span>
                </button>
                <div id={`nav-${group.id}`} className="nav-submenu" hidden={!expanded}>
                  {group.links.filter((link) => (!link.stockManager || me.data?.data.stock_manager) && (!link.feature || me.data?.data.features?.[link.feature])).map((link) => (
                    <NavLink key={link.to} to={link.to} end={group.links.some((other) => other.to !== link.to && other.to.startsWith(`${link.to}/`)) || link.to === '/'}>{link.label}</NavLink>
                  ))}
                </div>
              </div>
            )
          })}
          {me.data?.data.discord_url ? <a className="button discord-link" href={me.data.data.discord_url} target="_blank" rel="noopener noreferrer"><DiscordIcon />Join Discord</a> : null}
        </nav>
        <div className="sidebar-foot">
          {me.data ? <p className="sidebar-user">{me.data.data.avatar_url ? <img src={me.data.data.avatar_url} alt="" width="28" height="28" /> : null}<span>{me.data.data.display_name}</span></p> : null}
          {me.data?.data.demo ? null : <p><a href="/auth/eve/scopes">Add character</a></p>}
          <p className="theme-setting">
            <label htmlFor="theme-select">Theme</label>
            <select id="theme-select" value={theme} onChange={(e) => setTheme(e.target.value)}>
              {THEMES.map((t) => <option key={t.id} value={t.id} title={t.blurb}>{t.label}</option>)}
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
