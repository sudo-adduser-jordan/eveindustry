import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { errorMessage,fieldError, useSaveSponsor, useSponsor, type Sponsor } from '../../api/admin'
import { AdminError } from '../../components/AdminGuard'

function SponsorFields({ sponsor }: { sponsor: Sponsor | null }) {
  const navigate = useNavigate()
  const save = useSaveSponsor(sponsor?.id ?? null)
  const [name, setName] = useState(sponsor?.name ?? '')
  const [url, setUrl] = useState(sponsor?.url ?? '')
  const [sortOrder, setSortOrder] = useState(String(sponsor?.sort_order ?? 0))
  const [active, setActive] = useState(sponsor?.is_active ?? true)
  const [image, setImage] = useState<File | null>(null)

  function submit(e: FormEvent) {
    e.preventDefault()
    save.mutate(
      { name, url, sort_order: Math.max(0, Number(sortOrder) || 0), is_active: active, image },
      { onSuccess: () => navigate('/admin/sponsors') },
    )
  }

  const error = (field: string) => fieldError(save.error, field)
  
  const generic = save.isError && !(save.error instanceof ApiError && save.error.errors)
  return (
    <form className="scan-form panel form-narrow" onSubmit={submit}>
      <div className="scan-field">
        <label htmlFor="sponsor-name">Sponsor name</label>
        <input id="sponsor-name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} aria-invalid={!!error('name')} />
        {error('name') ? <span role="alert" className="field-error">{error('name')}</span> : null}
      </div>
      <div className="scan-field">
        <label htmlFor="sponsor-url">Sponsor URL</label>
        <input id="sponsor-url" type="url" placeholder="https://example.com" value={url} onChange={(e) => setUrl(e.target.value)} required maxLength={255} aria-invalid={!!error('url')} />
        {error('url') ? <span role="alert" className="field-error">{error('url')}</span> : null}
      </div>
      <div className="scan-field">
        <label htmlFor="sponsor-image">Banner image{sponsor ? ' (leave empty to keep the current one)' : ''}</label>
        <input id="sponsor-image" type="file" accept="image/*" required={!sponsor} onChange={(e) => setImage(e.target.files?.[0] ?? null)} aria-invalid={!!error('image')} />
        {error('image') ? <span role="alert" className="field-error">{error('image')}</span> : null}
        {sponsor ? <img className="sponsor-banner sponsor-banner-large" src={sponsor.image_url} alt={`Current banner of ${sponsor.name}`} /> : null}
      </div>
      <div className="scan-field">
        <label htmlFor="sponsor-order">Sort order</label>
        <input id="sponsor-order" type="number" min={0} value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} aria-invalid={!!error('sort_order')} />
        {error('sort_order') ? <span role="alert" className="field-error">{error('sort_order')}</span> : null}
      </div>
      <label className="check">
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Active
      </label>
      {generic ? <p role="alert" className="field-error">{errorMessage(save.error, 'Could not save the sponsor.')}</p> : null}
      <div className="page-actions">
        <button type="submit" className="button" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : sponsor ? 'Save sponsor' : 'Add sponsor'}
        </button>
        <Link to="/admin/sponsors">Cancel</Link>
      </div>
    </form>
  )
}


export default function AdminSponsorForm() {
  const idParam = useParams().sponsor
  const id = idParam === undefined || idParam === 'new' ? null : Number(idParam)
  const sponsor = useSponsor(id)

  return (
    <>
      <div className="page-head">
        <h1>{id === null ? 'Add sponsor' : 'Edit sponsor'}</h1>
      </div>
      {id === null ? (
        <SponsorFields sponsor={null} />
      ) : sponsor.isPending ? (
        <p>Loading sponsor…</p>
      ) : sponsor.isError ? (
        <AdminError error={sponsor.error} what="the sponsor" />
      ) : (
        <SponsorFields sponsor={sponsor.data.data} />
      )}
    </>
  )
}
