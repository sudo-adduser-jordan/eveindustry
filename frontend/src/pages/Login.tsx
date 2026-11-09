import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useMe } from '../api/queries'

type DemoState = 'idle' | 'busy' | 'off' | 'error'

/** Sign-in page inside the app shell. EVE SSO is disabled until the server
 *  port lands; the demo button signs in the shared demo account. Signed-in
 *  visitors bounce straight to the dashboard. */
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
    <>
      <h1>Log in</h1>
      <p>
        Track your industry, plan production and explore market opportunities using the tools in the navigation.
        Unofficial fan tool. EVE Online © CCP hf.
      </p>
      <p>
        <button
          type="button"
          disabled
          title="EVE SSO sign-in has not been ported to the Go server yet"
        >
          Log in with EVE Online
        </button>
      </p>
      <p className="muted">EVE SSO sign-in is not available in this build yet.</p>
      <p>
        <button type="button" onClick={tryDemo} disabled={demo === 'busy'}>
          Try the demo
        </button>
      </p>
      {demo === 'off' ? <p role="alert">Demo mode is off on this server (set DEMO_ENABLED=true).</p> : null}
      {demo === 'error' ? <p role="alert">Could not start the demo. Try again.</p> : null}
    </>
  )
}
