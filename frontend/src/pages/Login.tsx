import { useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useMe } from '../api/queries'
import logoIcon from '../assets/logo-icon.svg'

type DemoState = 'idle' | 'busy' | 'off' | 'error'


export default function Login() {
  const me = useMe()
  const [demo, setDemo] = useState<DemoState>('idle')

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
    <div className="auth-page">
      <header className="site-header">
        <Link className="brand" to="/dashboard">
          <img src={logoIcon} alt="" width={32} height={32} />
          Eve Industrialist
        </Link>
        <nav aria-label="Site navigation">
          <Link to="/login" aria-current="page">
            Login
          </Link>
        </nav>
      </header>
      <main className="auth-content">
        <section className="auth-panel narrow" aria-labelledby="login-heading">
          <h1 id="login-heading">Login</h1>
          <p>Login securely with your EVE Online account.</p>
          <p>
            <a className="button primary" href="/auth/eve/scopes">
              Login with EVE Online
            </a>
          </p>
          <p className="muted">Review your permissions before continuing to the official EVE SSO page.</p>
          <p>
            <button type="button" className="button" onClick={tryDemo} disabled={demo === 'busy'}>
              {demo === 'busy' ? 'Starting demo…' : 'Try the demo'}
            </button>
          </p>
          <p className="muted">Explore with three generated characters. No EVE login needed; data resets daily.</p>
          {demo === 'off' ? <p role="alert">Demo mode is off on this server (set DEMO_ENABLED=true).</p> : null}
          {demo === 'error' ? <p role="alert">Could not start the demo. Try again.</p> : null}
        </section>
      </main>
    </div>
  )
}
