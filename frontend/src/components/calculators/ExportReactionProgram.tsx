import { useState } from 'react'
import { useCorporateExport } from '../../api/calculators'
import { useCreateReactionProgram, type ReactionProgramRequest } from '../../api/reactions'


export function ReactionExport({
  label,
  corporate = false,
  build,
  hint,
}: {
  label: string
  corporate?: boolean
  build: () => ReactionProgramRequest
  hint?: string
}) {
  const create = useCreateReactionProgram(corporate)
  const [created, setCreated] = useState(false)

  return (
    <div>
      <button
        type="button"
        className="button"
        disabled={create.isPending || created}
        onClick={() =>
          create.mutate(build(), {
            onSuccess: (response) => {
              setCreated(true)
              const id = Number(response.data.id)
              window.location.assign(corporate ? `/corporate/build-programs/${id}` : `/build-programs/${id}`)
            },
          })
        }
      >
        {create.isPending ? 'Creating…' : created ? 'Created, opening…' : label}
      </button>
      {hint ? <p className="calc-note">{hint}</p> : null}
      {create.isError ? (
        <p role="alert" className="text-loss">
          {create.error.message}
        </p>
      ) : null}
    </div>
  )
}


export function CorporateReactionExport({ label, build }: { label: string; build: () => ReactionProgramRequest }) {
  const access = useCorporateExport()
  if (!access.data?.data.available) return null
  return <ReactionExport corporate label={label} build={build} hint={`Corporate program for ${access.data.data.corporation_name ?? 'your corporation'}`} />
}
