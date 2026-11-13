import { useEsiStatus } from '../api/queries'

export default function EsiStatus() {
  const status = useEsiStatus()
  if (status.isPending) {
    return (
      <p className="esi-status" role="status">
        <span className="esi-dot" aria-hidden="true" /> Checking ESI…
      </p>
    )
  }
  if (status.isError) {
    return (
      <p className="esi-status" role="alert">
        <span className="esi-dot off" aria-hidden="true" /> ESI status unavailable
      </p>
    )
  }
  const { online, players } = status.data.data
  if (!online) {
    return (
      <p className="esi-status" role="status">
        <span className="esi-dot off" aria-hidden="true" /> ESI unreachable
      </p>
    )
  }
  return (
    <p className="esi-status" role="status">
      <span className="esi-dot on" aria-hidden="true" /> ESI Online
      {players !== null ? <span className="muted"> · {players.toLocaleString('en-US')} players</span> : null}
    </p>
  )
}
