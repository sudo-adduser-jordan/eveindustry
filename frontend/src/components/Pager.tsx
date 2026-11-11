
export function Pager({
  pagination,
  onPage,
}: {
  pagination: { page: number; per_page: number; total: number; last_page: number }
  onPage: (page: number) => void
}) {
  if (pagination.last_page <= 1) return null
  return (
    <nav className="pager" aria-label="Pages">
      <button type="button" className="button" disabled={pagination.page <= 1} onClick={() => onPage(pagination.page - 1)}>
        Previous
      </button>
      <span className="muted">
        Page {pagination.page} of {pagination.last_page} ({pagination.total} results)
      </span>
      <button
        type="button"
        className="button"
        disabled={pagination.page >= pagination.last_page}
        onClick={() => onPage(pagination.page + 1)}
      >
        Next
      </button>
    </nav>
  )
}
