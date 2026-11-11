import { useState } from 'react'
import { useCorporateExport, useCreateBuildProgram, type BuildProgramRequest } from '../../api/calculators'


function programUrl(corporate: boolean, id: number): string {
  return corporate ? `/corporate/build-programs/${id}` : `/build-programs/${id}`
}


export function ExportProgramButton({
  label,
  corporate = false,
  build,
  hint,
}: {
  label: string
  corporate?: boolean
  build: () => BuildProgramRequest
  hint?: string
}) {
  const create = useCreateBuildProgram(corporate)
  const [created, setCreated] = useState(false)

  function run() {
    setCreated(false)
    create.mutate(build(), {
      onSuccess: (response) => {
        setCreated(true)
        window.location.assign(programUrl(corporate, response.data.id))
      },
    })
  }

  return (
    <div>
      <button type="button" className="button" disabled={create.isPending || created} onClick={run}>
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


export function CorporateExportButton({ label, build }: { label: string; build: () => BuildProgramRequest }) {
  const access = useCorporateExport()
  if (!access.data?.data.available) return null
  return (
    <ExportProgramButton
      corporate
      label={label}
      build={build}
      hint={`Corporate program for ${access.data.data.corporation_name ?? 'your corporation'}`}
    />
  )
}
