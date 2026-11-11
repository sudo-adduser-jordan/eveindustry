import { useInstall } from '../pwa/install'


export function InstallPrompt() {
  const { bannerOpen, canInstall, ios, install, dismiss } = useInstall()
  if (!bannerOpen) return null

  return (
    <div className="install-prompt" role="dialog" aria-labelledby="install-prompt-title">
      <button type="button" className="install-prompt-close" aria-label="Dismiss" onClick={dismiss}>×</button>
      <strong id="install-prompt-title">Install Eve Industrialist</strong>
      {canInstall ? (
        <>
          <p>Open it like an app, and turn on notifications for finished jobs and timers.</p>
          <div className="form-actions">
            <button type="button" className="button" onClick={() => { void install() }}>Install</button>
            <button type="button" className="button" onClick={dismiss}>Not now</button>
          </div>
        </>
      ) : ios ? (
        <>
          <p>Tap the Share button, then Add to Home Screen. Open the app from your Home Screen to turn on notifications.</p>
          <div className="form-actions">
            <button type="button" className="button" onClick={dismiss}>Got it</button>
          </div>
        </>
      ) : null}
    </div>
  )
}
