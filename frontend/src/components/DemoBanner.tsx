
import { EveSsoButton } from './EveSsoButton'

export function DemoBanner() {
  return (
    <div className="demo-banner" role="note">
      <span><strong>Demo account.</strong> These characters and their data are generated, not real, and reset every 24 hours. Account changes are turned off.</span>
      <EveSsoButton />
    </div>
  )
}
