import { useMemo, useState } from 'react'
import {
  useDisablePush, useEnablePush, useNotificationConfig, useSendTestNotification, useUpdateNotifications,
  type NotificationTypeSetting,
} from '../api/notifications'
import { useMe } from '../api/queries'
import { useInstall } from '../pwa/install'
import { useDeviceState } from '../pwa/push'

function leadLabel(minutes: number) {
  return minutes < 60 ? `${minutes} minutes before` : `${minutes / 60} hour${minutes === 60 ? '' : 's'} before`
}

type Change = { enabled?: boolean; lead_minutes?: number; character_ids?: number[] }

function TypeRow({ type, characters, onChange, pending }: {
  type: NotificationTypeSetting
  characters: Array<{ character_id: number; name: string }>
  onChange: (change: Change) => void
  pending: boolean
}) {
  
  const selected = type.character_ids.length === 0 ? characters.map((c) => c.character_id) : type.character_ids
  const toggleCharacter = (id: number) => {
    const next = selected.includes(id) ? selected.filter((other) => other !== id) : [...selected, id]
    if (next.length === 0) return
    onChange({ character_ids: next.length === characters.length ? [] : next })
  }

  return (
    <li className="panel notification-type">
      <label>
        <input type="checkbox" checked={type.enabled} disabled={!type.available || pending} onChange={(event) => onChange({ enabled: event.target.checked })} />
        {' '}<strong>{type.label}</strong>
      </label>
      <div className="muted">{type.description}</div>
      {!type.available ? <div className="muted">Needs a character in a corporation.</div> : null}
      {type.enabled && type.lead_options ? (
        <label className="scan-field">Warn me{' '}
          <select value={type.lead_minutes ?? ''} disabled={pending} onChange={(event) => onChange({ lead_minutes: Number(event.target.value) })}>
            {type.lead_options.map((minutes) => <option key={minutes} value={minutes}>{leadLabel(minutes)}</option>)}
          </select>
        </label>
      ) : null}
      {type.enabled && type.supports_characters && characters.length > 1 ? (
        <fieldset>
          <legend>Characters</legend>
          {characters.map((c) => (
            <label key={c.character_id} className="inline-check">
              <input type="checkbox" checked={selected.includes(c.character_id)} disabled={pending} onChange={() => toggleCharacter(c.character_id)} /> {c.name}
            </label>
          ))}
        </fieldset>
      ) : null}
    </li>
  )
}

export default function Notifications() {
  const config = useNotificationConfig()
  const me = useMe()
  const update = useUpdateNotifications()
  const enable = useEnablePush()
  const disable = useDisablePush()
  const test = useSendTestNotification()
  const device = useDeviceState()
  const install = useInstall()
  const [quiet, setQuiet] = useState<{ start: string; end: string; timezone: string } | null>(null)

  const characters = me.data?.data.characters ?? []
  const categories = useMemo(() => {
    const grouped = new Map<string, NotificationTypeSetting[]>()
    for (const type of config.data?.types ?? []) grouped.set(type.category, [...(grouped.get(type.category) ?? []), type])
    return [...grouped.entries()]
  }, [config.data])

  if (me.data?.data.demo) {
    return (
      <>
        <h1>Notifications</h1>
        <p className="muted">Push notifications are not available on the demo account. Log in with EVE Online to get alerts for your own jobs, planets and structures.</p>
      </>
    )
  }
  if (config.isPending) return <p>Loading notification settings…</p>
  if (config.isError) return <p role="alert">Could not load notification settings.</p>

  const data = config.data
  const hours = quiet ?? { start: data.quiet_hours.start ?? '', end: data.quiet_hours.end ?? '', timezone: data.quiet_hours.timezone }
  const error = update.error ?? enable.error ?? disable.error ?? test.error
  const afterDeviceChange = () => { void device.refresh() }

  return (
    <>
      <h1>Notifications</h1>
      <p className="muted">Get a push notification on this device when something in EVE needs your attention, even when the app is closed.</p>
      {error ? <p role="alert">{error.message}</p> : null}

      <section className="panel" aria-labelledby="device-heading">
        <h2 id="device-heading">This device</h2>
        {!data.enabled ? <p>Push notifications are not configured on this server yet.</p> : null}
        {data.enabled && device.state === 'unsupported' ? (
          <p>This browser cannot receive push notifications here. Use the production site, and on iPhone or iPad add the app to your Home Screen first.</p>
        ) : null}
        {data.enabled && device.state === 'denied' ? <p>Notifications are blocked for this site. Allow them in your browser site settings, then reload.</p> : null}
        {data.enabled && device.state === 'off' ? (
          <>
            <p>Notifications are off on this device.</p>
            <button type="button" className="button" disabled={enable.isPending} onClick={() => enable.mutate(data.vapid_public_key ?? '', { onSuccess: afterDeviceChange })}>Enable notifications</button>
          </>
        ) : null}
        {data.enabled && device.state === 'on' ? (
          <div className="form-actions">
            <span>Notifications are on for this device ({data.devices} {data.devices === 1 ? 'device' : 'devices'} in total).</span>
            <button type="button" className="button" disabled={test.isPending} onClick={() => test.mutate()}>Send a test</button>
            <button type="button" className="button" disabled={disable.isPending} onClick={() => disable.mutate(undefined, { onSuccess: afterDeviceChange })}>Turn off on this device</button>
          </div>
        ) : null}
        {device.state !== 'on' && install.canInstall ? (
          <p>
            <button type="button" className="button" onClick={() => { void install.install() }}>Install app to enable notifications</button>
          </p>
        ) : null}
        {device.state !== 'on' && install.ios ? (
          <p>On iPhone or iPad, tap Share, then Add to Home Screen, and open the app from your Home Screen to enable notifications.</p>
        ) : null}
        {test.data ? <p role="status">{test.data.sent > 0 ? 'Test sent.' : 'No device accepted the test.'}</p> : null}
      </section>

      <h2>What to notify me about</h2>
      {categories.map(([category, types]) => (
        <section key={category} aria-label={category}>
          <h3>{category}</h3>
          <ul className="cards">
            {types.map((type) => (
              <TypeRow key={type.key} type={type} characters={characters} pending={update.isPending}
                onChange={(change) => update.mutate({ types: { [type.key]: change } })} />
            ))}
          </ul>
        </section>
      ))}

      <section className="panel" aria-labelledby="quiet-heading">
        <h2 id="quiet-heading">Quiet hours</h2>
        <p className="muted">Notifications that come due during quiet hours are sent when they end.</p>
        <div className="planner-form">
          <label className="scan-field">From <input type="time" value={hours.start} onChange={(event) => setQuiet({ ...hours, start: event.target.value })} /></label>
          <label className="scan-field">To <input type="time" value={hours.end} onChange={(event) => setQuiet({ ...hours, end: event.target.value })} /></label>
          <label className="scan-field">Time zone <input value={hours.timezone} onChange={(event) => setQuiet({ ...hours, timezone: event.target.value })} /></label>
          <button type="button" className="button" disabled={update.isPending} onClick={() => update.mutate({ quiet_hours: { start: hours.start || null, end: hours.end || null, timezone: hours.timezone || 'UTC' } }, { onSuccess: () => setQuiet(null) })}>Save quiet hours</button>
          <button type="button" className="button" disabled={update.isPending} onClick={() => update.mutate({ quiet_hours: { start: null, end: null } }, { onSuccess: () => setQuiet(null) })}>Clear</button>
        </div>
      </section>
    </>
  )
}
