import { useMe } from '../api/queries'

export interface RequiredScope {
  scope: string
  
  description: string
}


const SCOPES_URL = '/auth/eve/scopes'


export function ScopeWarning({ required }: { required: RequiredScope[] }) {
  const me = useMe()
  const characters = me.data?.data.characters ?? []
  if (characters.length === 0) return null

  const missing = required.filter(({ scope }) => characters.every((c) => !c.scopes.includes(scope)))
  if (missing.length === 0) return null

  return (
    <div className="panel warn" role="alert">
      <strong>Missing ESI scopes.</strong> Some of this page may be empty.
      <ul>
        {missing.map(({ scope, description }) => (
          <li key={scope}>
            {description} (<code>{scope}</code>) is not granted for any character.
          </li>
        ))}
      </ul>
      <a href={SCOPES_URL}>Re-authenticate with EVE SSO to grant them</a>
    </div>
  )
}
