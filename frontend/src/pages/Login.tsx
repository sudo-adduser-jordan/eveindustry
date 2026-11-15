import { useLayoutEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useMe } from '../api/queries'
import logoIcon from '../assets/logo-icon.svg'
import eveSsoLoginBlack from '../assets/eve-sso-login-black-large.png'
import eveSsoLoginWhite from '../assets/eve-sso-login-white-large.png'
import { LOGIN_POSTER_SRC, LOGIN_POSTER_SRCSET, LOGIN_VIDEO_SRC } from '../loginBackdrop'
import { themeColorFor } from '../theme'

type DemoState = 'idle' | 'busy' | 'off' | 'error'


export default function Login() {
  const me = useMe()
  const [demo, setDemo] = useState<DemoState>('idle')

  useLayoutEffect(() => {
    const root = document.documentElement
    const prevTheme = root.dataset.theme
    const meta = document.querySelector('meta[name="theme-color"]')
    const prevMeta = meta?.getAttribute('content') ?? null
    root.dataset.theme = 'eve-online'
    meta?.setAttribute('content', themeColorFor('eve-online'))
    return () => {
      if (prevTheme) root.dataset.theme = prevTheme
      else delete root.dataset.theme
      if (meta && prevMeta !== null) meta.setAttribute('content', prevMeta)
    }
  }, [])

  if (me.data) {
    return <Navigate to="/dashboard" replace />
  }

  async function tryDemo() {
    setDemo('busy')
    try {
      const res = await fetch('/demo/login', { method: 'POST', headers: { Accept: 'application/json' } })
      if (res.status === 404) {
        setDemo('off')
        return
      }
      if (!res.ok) {
        setDemo('error')
        return
      }
      const body = (await res.json()) as { data?: { redirect?: string } }
      window.location.assign(body.data?.redirect ?? '/app/dashboard')
    } catch {
      setDemo('error')
    }
  }

  return (
    <div className="auth-page has-video-bg">
      <div className="auth-backdrop" aria-hidden="true">
        <img className="auth-backdrop-poster" src={LOGIN_POSTER_SRC} srcSet={LOGIN_POSTER_SRCSET} sizes="100vw" alt="" fetchPriority="high" />
        <video
          className="auth-backdrop-video"
          autoPlay
          muted
          playsInline
          preload="auto"
          poster={LOGIN_POSTER_SRC}
          tabIndex={-1}
        >
          <source src={LOGIN_VIDEO_SRC} type="video/webm" />
        </video>
        <div className="auth-backdrop-scrim" />
      </div>
      <header className="site-header">
        <Link className="brand" to="/dashboard">
          <img src={logoIcon} alt="" width={20} height={20} />
          Eve Industry
        </Link>
        <nav aria-label="Site navigation">
          <button type="button" onClick={tryDemo} disabled={demo === 'busy'}>
            {demo === 'busy' ? 'Starting demo…' : 'Demo'}
          </button>
        </nav>
      </header>
      <main className="auth-content">
        <section className="auth-panel narrow" aria-labelledby="login-heading">
          <h1 id="login-heading">Login</h1>
          <p>Login securely with your EVE Online account.</p>
          <p>
            <a className="eve-sso-button" href="/auth/eve/scopes">
              <img className="eve-sso-white" src={eveSsoLoginWhite} alt="" aria-hidden="true" width={270} height={45} />
              <img className="eve-sso-black" src={eveSsoLoginBlack} alt="" aria-hidden="true" width={270} height={45} />
              <span className="visually-hidden">Log in with EVE Online</span>
            </a>
          </p>
          <p className="muted">Review your permissions before continuing to the official EVE SSO page.</p>
          {demo === 'off' ? <p role="alert">Demo mode is off on this server (set DEMO_ENABLED=true).</p> : null}
          {demo === 'error' ? <p role="alert">Could not start the demo. Try again.</p> : null}
        </section>
      </main>
    </div>
  )
}
